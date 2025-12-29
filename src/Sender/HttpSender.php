<?php

namespace BaddyBugs\Agent\Sender;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class HttpSender implements SenderInterface
{
    protected int $maxRetries = 3;
    protected float $timeout = 5.0;
    protected bool $compress = true;
    protected bool $sign = true;

    public function __construct()
    {
        $this->maxRetries = config('baddybugs.retry_attempts', 3);
        $this->timeout = config('baddybugs.send_timeout', 5.0);
        $this->compress = config('baddybugs.compress', true);
        $this->sign = config('baddybugs.sign_payloads', true);
    }

    public function send(array $batch): bool
    {
        if (empty($batch)) {
            return true;
        }

        // Rate limiting protection
        if ($this->isRateLimited()) {
            return false;
        }

        $endpoint = config('baddybugs.endpoint');
        $apiKey = config('baddybugs.api_key');

        if (!$endpoint || !$apiKey) {
            return false;
        }

        $payload = ['events' => $batch];
        $body = json_encode($payload);
        $headers = $this->buildHeaders($body, $apiKey);

        // Compress if enabled and extension available
        if ($this->compress && function_exists('gzencode')) {
            $body = gzencode($body, 6);
            $headers['Content-Encoding'] = 'gzip';
        }

        return $this->sendWithRetry($endpoint, $body, $headers);
    }

    protected function buildHeaders(string $body, string $apiKey): array
    {
        $headers = [
            'Authorization' => 'Bearer ' . $apiKey,
            // 'X-Project-ID' => config('baddybugs.project_id'), // Removed
            'X-Agent-Version' => '1.0.0',
            'X-BaddyBugs-Internal' => 'true', // Critical for preventing loops
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];

        // Add HMAC signature for payload integrity
        if ($this->sign) {
            // Fix: config('key', default) does NOT return default if key exists but is null.
            // Explicitly handle null/empty secret by checking validity.
            $secret = config('baddybugs.signing_secret');
            
            if (empty($secret)) {
                $secret = $apiKey;
            }

            // Cast to string to strictly satisfy hash_hmac requirements (no null allowed)
            $secret = (string) $secret;

            $timestamp = time();
            $signature = hash_hmac('sha256', $timestamp . '.' . $body, $secret);
            
            $headers['X-Signature'] = $signature;
            $headers['X-Timestamp'] = $timestamp;
        }

        return $headers;
    }

    protected function sendWithRetry(string $endpoint, string $body, array $headers): bool
    {
        $lastException = null;
        $backoff = 100; // Start with 100ms

        for ($attempt = 1; $attempt <= $this->maxRetries; $attempt++) {
            try {
                $response = Http::withHeaders($headers)
                    ->timeout($this->timeout)
                    ->withBody($body, $headers['Content-Type'])
                    ->post($endpoint);

                if ($response->successful()) {
                    return true;
                }

                // Handle rate limiting from server
                if ($response->status() === 429) {
                    $retryAfter = $response->header('Retry-After', 60);
                    $this->setRateLimited((int) $retryAfter);
                    return false;
                }

                // Log non-success but don't retry for 4xx errors (except 429)
                if ($response->status() >= 400 && $response->status() < 500) {
                    Log::warning('BaddyBugs: Client error', [
                        'status' => $response->status(),
                        'body' => $response->body()
                    ]);
                    return false;
                }

            } catch (\Throwable $e) {
                $lastException = $e;
            }

            // Exponential backoff with jitter
            if ($attempt < $this->maxRetries) {
                $jitter = mt_rand(0, (int) ($backoff * 0.3));
                usleep(($backoff + $jitter) * 1000);
                $backoff *= 2; // Double the wait time
            }
        }

        if ($lastException) {
            Log::error('BaddyBugs: Failed to send after retries', [
                'error' => $lastException->getMessage()
            ]);
        }

        return false;
    }

    protected function isRateLimited(): bool
    {
        return Cache::has('baddybugs:rate_limited');
    }

    protected function setRateLimited(int $seconds): void
    {
        Cache::put('baddybugs:rate_limited', true, now()->addSeconds($seconds));
        Log::warning("BaddyBugs: Rate limited for {$seconds} seconds");
    }
}

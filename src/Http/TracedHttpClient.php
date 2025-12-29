<?php

namespace BaddyBugs\Agent\Http;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use BaddyBugs\Agent\Facades\BaddyBugs;

/**
 * PSR-18 HTTP Client Decorator
 * 
 * Wraps any PSR-18 compliant HTTP client to add BaddyBugs monitoring.
 * 
 * Usage:
 * ```php
 * use BaddyBugs\Agent\Http\TracedHttpClient;
 * use Symfony\Component\HttpClient\Psr18Client;
 * 
 * $symfonyClient = new Psr18Client();
 * $tracedClient = new TracedHttpClient($symfonyClient);
 * 
 * // Now all requests through $tracedClient are monitored
 * $response = $tracedClient->sendRequest($request);
 * ```
 * 
 * Works with:
 * - Symfony HttpClient (via Psr18Client adapter)
 * - Guzzle (implements ClientInterface)
 * - Buzz
 * - HTTPlug clients
 * - Any PSR-18 compliant client
 */
class TracedHttpClient implements ClientInterface
{
    protected ClientInterface $client;
    protected string $source;

    public function __construct(ClientInterface $client, string $source = 'psr18')
    {
        $this->client = $client;
        $this->source = $source;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $url = (string) $request->getUri();
        
        // Skip internal requests
        if ($request->hasHeader('X-BaddyBugs-Internal') || $this->shouldIgnore($url)) {
            return $this->client->sendRequest($request);
        }

        // Add trace ID for distributed tracing
        $traceId = BaddyBugs::getTraceId();
        $request = $request->withHeader('X-Baddybugs-Trace-Id', $traceId);
        
        // Add W3C traceparent header
        // Format: version-traceid-parentid-traceflags
        // version: 00
        // traceid: 32 hex chars (remove dashes from UUID)
        // parentid: 16 hex chars (random span id for this specific call)
        // traceflags: 01 (sampled)
        if ($traceId) {
            $cleanTraceId = str_replace('-', '', $traceId);
            $spanId = bin2hex(random_bytes(8));
            $traceparent = sprintf('00-%s-%s-01', $cleanTraceId, $spanId);
            $request = $request->withHeader('traceparent', $traceparent);
        }
        
        $start = microtime(true);
        $error = null;
        $response = null;

        try {
            $response = $this->client->sendRequest($request);
            return $response;
        } catch (\Throwable $e) {
            $error = $e->getMessage();
            throw $e;
        } finally {
            $duration = (microtime(true) - $start) * 1000;
            
            BaddyBugs::record('http_client', $request->getMethod() . ' ' . parse_url($url, PHP_URL_HOST), [
                'method' => $request->getMethod(),
                'url' => $url,
                'status' => $response ? $response->getStatusCode() : 0,
                'duration_ms' => $duration,
                'success' => $response && $response->getStatusCode() < 400,
                'size' => $response ? $response->getBody()->getSize() : null,
                'error' => $error,
                'source' => $this->source,
            ]);
        }
    }

    protected function shouldIgnore(string $url): bool
    {
        $endpoint = config('baddybugs.endpoint');
        if ($endpoint && str_contains($url, $endpoint)) {
            return true;
        }
        
        $ignoreDomains = config('baddybugs.ignore_domains', []);
        $host = parse_url($url, PHP_URL_HOST);
        if ($host && in_array($host, $ignoreDomains)) {
            return true;
        }

        return false;
    }

    /**
     * Get the underlying client.
     */
    public function getWrappedClient(): ClientInterface
    {
        return $this->client;
    }
}

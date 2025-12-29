<?php

namespace BaddyBugs\Agent\Collectors;

use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Support\Facades\Event;
use BaddyBugs\Agent\Facades\BaddyBugs;
use BaddyBugs\Agent\Support\SecretsDetector;
use GuzzleHttp\Middleware;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class HttpClientCollector implements CollectorInterface
{
    protected array $pendingRequests = [];
    protected SecretsDetector $scrubber;

    public function __construct()
    {
        $this->scrubber = new SecretsDetector();
    }

    public function boot(): void
    {
        // 1. Laravel Http Client (uses Guzzle internally)
        Event::listen(RequestSending::class, [$this, 'handleSending']);
        Event::listen(ResponseReceived::class, [$this, 'handleReceived']);
        Event::listen(ConnectionFailed::class, [$this, 'handleFailed']);
        
        // 2. Register Guzzle middleware globally if Guzzle is available
        $this->registerGuzzleGlobalMiddleware();
    }

    /**
     * Register middleware with the default Guzzle handler stack if available.
     * This catches direct Guzzle usage outside of Laravel's Http facade.
     */
    protected function registerGuzzleGlobalMiddleware(): void
    {
        // This is tricky because Guzzle doesn't have a global handler registry.
        // Users instantiate their own clients. We provide a helper instead.
        // See `BaddyBugs::guzzleMiddleware()` for the recommended approach.
    }

    // =========================================================================
    // Laravel Http Client Events
    // =========================================================================

    public function handleSending(RequestSending $event): void
    {
        try {
            if ($this->shouldIgnoreLaravelRequest($event->request)) {
                return;
            }

            $request = $event->request;
            $hash = spl_object_hash($request);
            
            $this->pendingRequests[$hash] = [
                'start' => microtime(true),
                'method' => $request->method(),
                'url' => $request->url(),
            ];

            // Inject Trace ID for Distributed Tracing
            $event->request->withHeader('X-Baddybugs-Trace-Id', BaddyBugs::getTraceId());
        } catch (\Throwable $e) {
            // Fail silently
        }
    }

    public function handleReceived(ResponseReceived $event): void
    {
        try {
            if ($this->shouldIgnoreLaravelRequest($event->request)) {
                return;
            }

            $request = $event->request;
            $response = $event->response;
            $hash = spl_object_hash($request);
            
            $start = $this->pendingRequests[$hash]['start'] ?? microtime(true);
            $duration = (microtime(true) - $start) * 1000;
            
            unset($this->pendingRequests[$hash]);

            // Capture and scrub payloads
            $requestBody = $this->captureRequestBody($request);
            $responseBody = $this->captureResponseBody($response);

            BaddyBugs::record('http_client', $request->method() . ' ' . parse_url($request->url(), PHP_URL_HOST), [
                'method' => $request->method(),
                'url' => $request->url(),
                'status' => $response->status(),
                'duration_ms' => $duration,
                'success' => $response->successful(),
                'size' => strlen($response->body()),
                'source' => 'laravel_http',
                'request_body' => $requestBody, // UC #22: Payload recording
                'response_body' => $responseBody, // UC #22: Payload recording
            ]);
        } catch (\Throwable $e) {
            // Fail silently
        }
    }

    public function handleFailed(ConnectionFailed $event): void
    {
        try {
            if ($this->shouldIgnoreLaravelRequest($event->request)) {
                return;
            }

            $request = $event->request;
            $hash = spl_object_hash($request);
            
            $start = $this->pendingRequests[$hash]['start'] ?? microtime(true);
            $duration = (microtime(true) - $start) * 1000;
            
            unset($this->pendingRequests[$hash]);

            BaddyBugs::record('http_client', $request->method() . ' ' . parse_url($request->url(), PHP_URL_HOST), [
                'method' => $request->method(),
                'url' => $request->url(),
                'status' => 0,
                'duration_ms' => $duration,
                'success' => false,
                'error' => 'Connection failed',
                'source' => 'laravel_http',
            ]);
        } catch (\Throwable $e) {
            // Fail silently
        }
    }

    protected function shouldIgnoreLaravelRequest($request): bool
    {
        if ($request->hasHeader('X-BaddyBugs-Internal')) {
            return true;
        }

        $endpoint = config('baddybugs.endpoint');
        if ($endpoint && str_contains($request->url(), $endpoint)) {
            return true;
        }
        
        $ignoreDomains = config('baddybugs.ignore_domains', []);
        $host = parse_url($request->url(), PHP_URL_HOST);
        if ($host && in_array($host, $ignoreDomains)) {
            return true;
        }

        return false;
    }

    // =========================================================================
    // Static Helpers for Other HTTP Clients
    // =========================================================================

    /**
     * Get a Guzzle Middleware for direct Guzzle usage.
     * 
     * Usage:
     * ```php
     * $stack = HandlerStack::create();
     * $stack->push(HttpClientCollector::guzzleMiddleware());
     * $client = new Client(['handler' => $stack]);
     * ```
     */
    public static function guzzleMiddleware(): callable
    {
        return function (callable $handler) {
            return function (RequestInterface $request, array $options) use ($handler) {
                $start = microtime(true);
                $url = (string) $request->getUri();
                
                // Ignore internal requests
                if ($request->hasHeader('X-BaddyBugs-Internal') || self::shouldIgnoreUrl($url)) {
                    return $handler($request, $options);
                }

                // Add trace ID
                $request = $request->withHeader('X-Baddybugs-Trace-Id', BaddyBugs::getTraceId());
                
                return $handler($request, $options)->then(
                    function (ResponseInterface $response) use ($request, $start, $url) {
                        $duration = (microtime(true) - $start) * 1000;
                        
                        BaddyBugs::record('http_client', $request->getMethod() . ' ' . parse_url($url, PHP_URL_HOST), [
                            'method' => $request->getMethod(),
                            'url' => $url,
                            'status' => $response->getStatusCode(),
                            'duration_ms' => $duration,
                            'success' => $response->getStatusCode() < 400,
                            'size' => $response->getBody()->getSize(),
                            'source' => 'guzzle_direct',
                        ]);
                        
                        return $response;
                    },
                    function (\Throwable $e) use ($request, $start, $url) {
                        $duration = (microtime(true) - $start) * 1000;
                        
                        BaddyBugs::record('http_client', $request->getMethod() . ' ' . parse_url($url, PHP_URL_HOST), [
                            'method' => $request->getMethod(),
                            'url' => $url,
                            'status' => 0,
                            'duration_ms' => $duration,
                            'success' => false,
                            'error' => $e->getMessage(),
                            'source' => 'guzzle_direct',
                        ]);
                        
                        throw $e;
                    }
                );
            };
        };
    }

    /**
     * Record a manual HTTP request (for cURL, file_get_contents, etc.).
     * 
     * Usage:
     * ```php
     * $start = microtime(true);
     * $result = curl_exec($ch);
     * HttpClientCollector::recordManual('GET', $url, curl_getinfo($ch, CURLINFO_HTTP_CODE), microtime(true) - $start);
     * ```
     */
    public static function recordManual(
        string $method,
        string $url,
        int $status,
        float $durationSeconds,
        ?int $size = null,
        ?string $error = null
    ): void {
        if (self::shouldIgnoreUrl($url)) {
            return;
        }

        BaddyBugs::record('http_client', $method . ' ' . parse_url($url, PHP_URL_HOST), [
            'method' => $method,
            'url' => $url,
            'status' => $status,
            'duration_ms' => $durationSeconds * 1000,
            'success' => $status > 0 && $status < 400,
            'size' => $size,
            'error' => $error,
            'source' => 'manual',
        ]);
    }

    protected static function shouldIgnoreUrl(string $url): bool
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
     * Capture and scrub request body
     * 
     * @param mixed $request Laravel request object
     * @return string|null
     */
    protected function captureRequestBody($request): ?string
    {
        try {
            // Get request body (may be JSON, form data, etc.)
            $body = $request->body();
            
            if (empty($body)) {
                return null;
            }

            // Limit size to avoid huge payloads (max 10KB)
            if (strlen($body) > 10240) {
                $body = substr($body, 0, 10240) . '... (truncated)';
            }

            // Scrub PII and secrets
            return $this->scrubber->scrub($body);

        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Capture and scrub response body
     * 
     * @param mixed $response Laravel response object
     * @return string|null
     */
    protected function captureResponseBody($response): ?string
    {
        try {
            $body = $response->body();
            
            if (empty($body)) {
                return null;
            }

            // Limit size (max 10KB)
            if (strlen($body) > 10240) {
                $body = substr($body, 0, 10240) . '... (truncated)';
            }

            // Scrub PII and secrets
            return $this->scrubber->scrub($body);

        } catch (\Throwable $e) {
            return null;
        }
    }
}

<?php

namespace BaddyBugs\Agent\Middleware;

use Closure;
use Illuminate\Http\Request;
use BaddyBugs\Agent\Buffers\BufferInterface;

/**
 * Terminable Middleware for Zero-Latency Monitoring
 * 
 * ARCHITECTURE:
 * 1. During request: Collectors push data to in-memory buffer (< 0.5ms total)
 * 2. Response is sent to user (user sees fast response)
 * 3. terminate() runs AFTER response is sent
 * 4. All HTTP calls, compression, etc. happen here (invisible to user)
 * 
 * This guarantees ZERO impact on user-perceived response time.
 */
class InjectBaddyBugs
{
    /**
     * Handle an incoming request.
     * 
     * This method does NOTHING to ensure zero overhead.
     */
    public function handle(Request $request, Closure $next)
    {
        try {
            if (config('baddybugs.enabled')) {
                $traceId = null;

                // 1. Try W3C Trace Context (traceparent)
                // Format: version-traceid-parentid-traceflags
                if ($header = $request->header('traceparent')) {
                    $parts = explode('-', $header);
                    if (count($parts) >= 2) {
                        $traceId = $parts[1];
                    }
                }

                // 2. Try BaddyBugs custom header
                if (!$traceId && $header = $request->header('X-Baddybugs-Trace-Id')) {
                    $traceId = $header;
                }

                // 3. Set the Trace ID if found
                if ($traceId && app()->bound(\BaddyBugs\Agent\BaddyBugs::class)) {
                     app(\BaddyBugs\Agent\BaddyBugs::class)->setTraceId($traceId);
                }
            }
        } catch (\Throwable $e) {
            // Ignore errors
        }

        $response = $next($request);

        // Add Trace ID to response headers for debugging/correlation
        try {
            if (app()->bound(\BaddyBugs\Agent\BaddyBugs::class)) {
                $currentTraceId = app(\BaddyBugs\Agent\BaddyBugs::class)->getTraceId();
                if (method_exists($response, 'header')) {
                    $response->header('X-Baddybugs-Trace-Id', $currentTraceId);
                }
            }
        } catch (\Throwable $e) {
            // Ignore errors
        }

        return $response;
    }

    /**
     * Handle tasks AFTER the response has been sent to the browser.
     * 
     * This is where all the "heavy" work happens:
     * - JSON encoding
     * - Gzip compression
     * - HMAC signing
     * - HTTP transmission to BaddyBugs server
     * 
     * The user has already received their response by this point.
     */
    public function terminate($request, $response)
    {
        // 1. Check exclusions before doing ANYTHING
        if (!$this->shouldCapture($request)) {
            return;
        }

        try {
            /** @var BufferInterface $buffer */
            $buffer = app(BufferInterface::class);
            
            // Only flush memory buffer here
            // File/Redis buffers are handled by background worker for even better perf
            if (config('baddybugs.buffer_driver') === 'memory' && method_exists($buffer, 'flushAndSend')) {
                $buffer->flushAndSend();
            }
        } catch (\Throwable $e) {
            // Silently fail - monitoring should NEVER break the app
        }
    }

    /**
     * Determine if the request should be captured.
     */
    protected function shouldCapture(Request $request): bool
    {
        if (!config('baddybugs.enabled')) {
            return false;
        }

        $ignoredPaths = config('baddybugs.ignore_paths', []);
        
        foreach ($ignoredPaths as $path) {
            if ($request->is($path)) {
                return false;
            }
        }

        return true;
    }
}

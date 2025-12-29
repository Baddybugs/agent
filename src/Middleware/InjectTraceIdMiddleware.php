<?php

namespace BaddyBugs\Agent\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use BaddyBugs\Agent\BaddyBugs;

/**
 * Inject Trace ID into Views
 * 
 * This middleware shares the current trace_id with all Blade views,
 * enabling perfect correlation between frontend and backend events.
 * 
 * The trace_id is made available via:
 * - view()->share('baddybugs_trace_id', $traceId)
 * - The @baddybugs Blade directive (which uses the shared variable)
 * 
 * Zero overhead if frontend monitoring is disabled.
 */
class InjectTraceIdMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Only inject if frontend monitoring is enabled
        if (!$this->shouldInjectTraceId()) {
            return $next($request);
        }

        try {
            // Get the current trace ID from BaddyBugs instance
            /** @var BaddyBugs $baddybugs */
            $baddybugs = app(BaddyBugs::class);
            $traceId = $baddybugs->getTraceId();

            // Share trace_id with all views
            view()->share('baddybugs_trace_id', $traceId);

            // Also share the full config for frontend usage
            view()->share('baddybugs_config', [
                'endpoint' => config('baddybugs.endpoint'),
                'api_key' => config('baddybugs.api_key'),
                'project_id' => config('baddybugs.project_id'),
                'sampling_rate' => config('baddybugs.frontend_sampling_rate', 1.0),
                'debug' => config('app.debug', false),
                'env' => config('baddybugs.env', config('app.env', 'production')),
            ]);
        } catch (\Throwable $e) {
            // Silently fail - monitoring should NEVER break the app
            // If BaddyBugs is not properly initialized, continue without trace_id
        }

        return $next($request);
    }

    /**
     * Determine if we should inject the trace ID.
     *
     * @return bool
     */
    protected function shouldInjectTraceId(): bool
    {
        // Check if BaddyBugs is enabled
        if (!config('baddybugs.enabled', false)) {
            return false;
        }

        // Check if frontend monitoring is enabled
        if (!config('baddybugs.frontend_enabled', false)) {
            return false;
        }

        // Check if trace_id exposure is enabled
        if (!config('baddybugs.expose_trace_id', false)) {
            return false;
        }

        return true;
    }
}

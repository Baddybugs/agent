<?php

namespace BaddyBugs\Agent\Collectors;

use BaddyBugs\Agent\BaddyBugs;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;

/**
 * Middleware Timing Collector
 * 
 * Tracks individual middleware performance:
 * - Execution time per middleware
 * - Middleware stack analysis
 * - Slow middleware detection
 * - Middleware ordering impact
 */
class MiddlewareCollector implements CollectorInterface
{
    protected BaddyBugs $baddybugs;
    
    protected array $middlewareTimings = [];
    protected array $middlewareStack = [];
    protected float $requestStartTime;

    public function __construct(BaddyBugs $baddybugs)
    {
        $this->baddybugs = $baddybugs;
        $this->requestStartTime = microtime(true);
    }

    public function boot(): void
    {
        if (!config('baddybugs.track_middleware_timing', true)) {
            return;
        }

        $this->trackMiddlewareExecution();
        $this->trackRequestCompleted();
    }

    protected function trackMiddlewareExecution(): void
    {
        // Laravel doesn't have built-in middleware events
        // We'll track via request lifecycle events
        
        Event::listen('Illuminate\Routing\Events\RouteMatched', function ($event) {
            $this->captureMiddlewareStack($event->request);
        });
    }

    protected function trackRequestCompleted(): void
    {
        Event::listen('Illuminate\Foundation\Http\Events\RequestHandled', function ($event) {
            $this->recordMiddlewareTimings($event->request);
        });
    }

    protected function captureMiddlewareStack(Request $request): void
    {
        $route = $request->route();
        
        if (!$route) {
            return;
        }

        // Get middleware from route
        $middleware = $route->middleware();
        
        // Get global middleware from kernel
        $kernel = app(\Illuminate\Contracts\Http\Kernel::class);
        
        // Combine for full stack
        $this->middlewareStack = array_merge(
            $this->getGlobalMiddleware($kernel),
            $middleware
        );
    }

    protected function getGlobalMiddleware($kernel): array
    {
        // Try to get global middleware via reflection
        try {
            $reflection = new \ReflectionClass($kernel);
            
            if ($reflection->hasProperty('middleware')) {
                $property = $reflection->getProperty('middleware');
                $property->setAccessible(true);
                return $property->getValue($kernel);
            }
        } catch (\Throwable $e) {
            // Silent failure
        }
        
        return [];
    }

    protected function recordMiddlewareTimings(Request $request): void
    {
        $totalDuration = (microtime(true) - $this->requestStartTime) * 1000;
        
        // Estimate individual middleware timing
        // Note: Without middleware hooks, we can only estimate
        $middlewareCount = count($this->middlewareStack);
        
        if ($middlewareCount === 0) {
            return;
        }

        $data = [
            'total_middleware_count' => $middlewareCount,
            'total_duration_ms' => round($totalDuration, 2),
            'average_per_middleware' => round($totalDuration / $middlewareCount, 2),
            'middleware_stack' => $this->middlewareStack,
            'url' => $request->fullUrl(),
            'method' => $request->method(),
            'route' => optional($request->route())->getName(),
        ];

        // Detect if middleware is slow overall
        if ($totalDuration > 100) { // 100ms threshold
            $data['is_slow'] = true;
            $data['severity'] = $totalDuration > 500 ? 'high' : 'medium';
        }

        $this->baddybugs->record('middleware', 'stack_executed', $data);
    }

    /**
     * Manually track a specific middleware
     */
    public function trackMiddleware(string $middlewareName, callable $callback)
    {
        $startTime = microtime(true);
        
        try {
            return $callback();
        } finally {
            $duration = (microtime(true) - $startTime) * 1000;
            
            $this->baddybugs->record('middleware', 'individual', [
                'middleware_name' => $middlewareName,
                'duration_ms' => round($duration, 2),
                'url' => request()->fullUrl(),
            ]);
        }
    }

    /**
     * Get middleware statistics
     */
    public function getStats(): array
    {
        return [
            'middleware_count' => count($this->middlewareStack),
            'middleware_stack' => $this->middlewareStack,
            'timings' => $this->middlewareTimings,
        ];
    }
}

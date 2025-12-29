<?php

namespace BaddyBugs\Agent\Collectors;

use BaddyBugs\Agent\BaddyBugs;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Event;

/**
 * Feature Usage & Product Analytics Collector
 * 
 * Tracks feature usage and product metrics:
 * - Route usage (auto-tracking)
 * - Job execution (auto-tracking)
 * - Custom features (manual tracking via BaddyBugs::feature())
 * - Custom events (manual tracking via BaddyBugs::track())
 */
class FeatureCollector implements CollectorInterface
{
    protected BaddyBugs $baddybugs;
    
    protected array $routeUsage = [];
    protected array $featureUsage = [];

    public function __construct(BaddyBugs $baddybugs)
    {
        $this->baddybugs = $baddybugs;
    }

    public function boot(): void
    {
        if (!$this->shouldCollect()) {
            return;
        }

        // Auto-track routes
        if (config('baddybugs.feature_track_routes', true)) {
            $this->trackRoutes();
        }

        // Auto-track jobs
        if (config('baddybugs.feature_track_jobs', true)) {
            $this->trackJobs();
        }

        // Auto-track custom events (optional, can be high volume)
        if (config('baddybugs.feature_track_custom_events', false)) {
            $this->trackCustomEvents();
        }
    }

    protected function shouldCollect(): bool
    {
        if (!config('baddybugs.feature_tracking_enabled', true)) {
            return false;
        }

        // Apply sampling
        $samplingRate = config('baddybugs.feature_sampling_rate', 1.0);
        if ($samplingRate < 1.0 && (mt_rand() / mt_getrandmax()) > $samplingRate) {
            return false;
        }

        return true;
    }

    protected function trackRoutes(): void
    {
        Event::listen(RequestHandled::class, function (RequestHandled $event) {
            $request = $event->request;
            $route = $request->route();

            if (!$route) {
                return;
            }

            $routeName = $route->getName() ?? $route->uri();
            $controller = $route->getActionName();

            $this->recordFeature('route.accessed', [
                'route_name' => $routeName,
                'route_uri' => $route->uri(),
                'controller' => $controller,
                'method' => $request->method(),
                'status_code' => $event->response->getStatusCode(),
                'user_id' => auth()->id(),
                'url' => $request->fullUrl(),
            ]);
        });
    }

    protected function trackJobs(): void
    {
        Event::listen(JobProcessed::class, function (JobProcessed $event) {
            $jobName = $event->job->resolveName();

            $this->recordFeature('job.executed', [
                'job_name' => $jobName,
                'queue' => $event->job->getQueue(),
                'connection' => $event->connectionName,
                'user_id' => auth()->id(),
            ]);
        });
    }

    protected function trackCustomEvents(): void
    {
        // Listen to all Laravel events for analytics
        Event::listen('*', function ($eventName, $payload) {
            // Filter out internal events
            if ($this->shouldIgnoreEvent($eventName)) {
                return;
            }

            $this->recordFeature('event.triggered', [
                'event_name' => $eventName,
                'user_id' => auth()->id(),
            ]);
        });
    }

    protected function shouldIgnoreEvent(string $eventName): bool
    {
        // Ignore framework internal events to reduce noise
        $ignorePatterns = [
            'Illuminate\\',
            'Laravel\\',
            'eloquent\\',
            'bootstrapped\\',
            'creating\\',
            'kernel\\',
        ];

        foreach ($ignorePatterns as $pattern) {
            if (str_starts_with($eventName, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Track a feature usage (called via BaddyBugs::feature())
     */
    public function trackFeature(string $name, array $properties = []): void
    {
        $this->recordFeature('feature.used', array_merge([
            'feature_name' => $name,
            'user_id' => auth()->id(),
            'url' => request()->fullUrl(),
            'timestamp' => now()->toIso8601String(),
        ], $properties));
    }

    /**
     * Track a custom event (called via BaddyBugs::track())
     */
    public function trackEvent(string $event, array $properties = []): void
    {
        $this->recordFeature('custom.event', array_merge([
            'event_name' => $event,
            'user_id' => auth()->id(),
            'url' => request()->fullUrl(),
            'timestamp' => now()->toIso8601String(),
        ], $properties));
    }

    /**
     * Record a feature usage event
     */
    protected function recordFeature(string $type, array $data): void
    {
        // Enrich with user context if configured
        if (config('baddybugs.enrich_user_context', true)) {
            $data = $this->enrichUserContext($data);
        }

        $this->baddybugs->record('feature', $type, $data);

        // Track in-memory for potential aggregation
        $key = $data['feature_name'] ?? $data['event_name'] ?? $type;
        $this->featureUsage[$key] = ($this->featureUsage[$key] ?? 0) + 1;
    }

    /**
     * Enrich data with user context
     */
    protected function enrichUserContext(array $data): array
    {
        $user = auth()->user();
        
        if (!$user) {
            return $data;
        }

        $contextFields = config('baddybugs.user_context_fields', ['id', 'email', 'name']);
        
        foreach ($contextFields as $field) {
            if (isset($user->$field)) {
                $value = $user->$field;
                
                // Handle relations (roles, permissions)
                if (is_object($value) && method_exists($value, 'pluck')) {
                    $value = $value->pluck('name')->toArray();
                }
                
                $data['user_' . $field] = $value;
            }
        }

        return $data;
    }

    /**
     * Get feature usage statistics
     */
    public function getUsageStats(): array
    {
        return [
            'total_events' => array_sum($this->featureUsage),
            'features' => $this->featureUsage,
            'top_features' => $this->getTopFeatures(10),
        ];
    }

    /**
     * Get top N most used features
     */
    protected function getTopFeatures(int $limit = 10): array
    {
        arsort($this->featureUsage);
        return array_slice($this->featureUsage, 0, $limit, true);
    }
}

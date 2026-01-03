<?php

namespace BaddyBugs\Agent\Collectors;

use BaddyBugs\Agent\BaddyBugs;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Cache;

/**
 * Timeline Collector
 * 
 * Creates ordered timeline for each trace:
 * - Chronological event ordering
 * - Request → Queries → Jobs → View → Response flow
 * - Visual timeline preparation
 * - Event correlation by trace_id
 */
class TimelineCollector implements CollectorInterface
{
    protected BaddyBugs $baddybugs;
    
    protected array $timeline = [];
    protected float $requestStartTime;
    protected string $traceId;

    public function __construct(BaddyBugs $baddybugs)
    {
        $this->baddybugs = $baddybugs;
        $this->requestStartTime = defined('LARAVEL_START') ? LARAVEL_START : microtime(true);
        $this->traceId = $baddybugs->getTraceId();
    }

    public function boot(): void
    {
        if (!config('baddybugs.timeline_enabled', true)) {
            return;
        }

        $this->trackTimelineEvents();
    }

    protected function trackTimelineEvents(): void
    {
        $detailLevel = config('baddybugs.timeline_detail_level', 'detailed');

        // Always track major events
        $this->trackMajorEvents();

        // Track detailed events based on config
        if (in_array($detailLevel, ['detailed', 'full'])) {
            $this->trackDetailedEvents();
        }

        // Track everything for full timeline
        if ($detailLevel === 'full') {
            $this->trackFullEvents();
        }

        // Send timeline on request completion
        $this->sendTimelineOnCompletion();
    }

    protected function trackMajorEvents(): void
    {
        // Request start
        $this->addTimelineEvent('request', 'started', [
            'url' => request()->fullUrl(),
            'method' => request()->method(),
        ], $this->requestStartTime);

        // Route matched
        Event::listen('Illuminate\Routing\Events\RouteMatched', function ($event) {
            $this->addTimelineEvent('routing', 'matched', [
                'route' => $event->route->getName(),
                'controller' => $event->route->getActionName(),
            ]);
        });

        // Request handled
        Event::listen('Illuminate\Foundation\Http\Events\RequestHandled', function ($event) {
            $this->addTimelineEvent('request', 'completed', [
                'status_code' => $event->response->getStatusCode(),
                'duration_ms' => (microtime(true) - $this->requestStartTime) * 1000,
            ]);
        });
    }

    protected function trackDetailedEvents(): void
    {
        // Database queries
        Event::listen('Illuminate\Database\Events\QueryExecuted', function ($event) {
            $this->addTimelineEvent('database', 'query', [
                'sql' => $event->sql,
                'duration_ms' => $event->time,
                'connection' => $event->connectionName,
            ]);
        });

        // Jobs
        Event::listen('Illuminate\Queue\Events\JobProcessing', function ($event) {
            $this->addTimelineEvent('queue', 'job_started', [
                'job' => $event->job->resolveName(),
                'queue' => $event->job->getQueue(),
            ]);
        });

        Event::listen('Illuminate\Queue\Events\JobProcessed', function ($event) {
            $this->addTimelineEvent('queue', 'job_completed', [
                'job' => $event->job->resolveName(),
            ]);
        });

        // Cache operations
        Event::listen('cache:hit', function ($key) {
            $this->addTimelineEvent('cache', 'hit', ['key' => $key]);
        });

        Event::listen('cache:missed', function ($key) {
            $this->addTimelineEvent('cache', 'miss', ['key' => $key]);
        });
    }

    protected function trackFullEvents(): void
    {
        // Model events
        Event::listen('eloquent.*', function ($event, $data) {
            if (is_string($event) && str_starts_with($event, 'eloquent.')) {
                $parts = explode('.', $event);
                $eventType = $parts[1] ?? 'unknown';
                
                $this->addTimelineEvent('eloquent', $eventType, [
                    'model' => $parts[2] ?? 'unknown',
                ]);
            }
        });

        // Mail sending
        Event::listen('Illuminate\Mail\Events\MessageSending', function ($event) {
            $this->addTimelineEvent('mail', 'sending', [
                'subject' => $event->message->getSubject(),
            ]);
        });

        // Notifications
        Event::listen('Illuminate\Notifications\Events\NotificationSending', function ($event) {
            $this->addTimelineEvent('notification', 'sending', [
                'notification' => get_class($event->notification),
                'channel' => $event->channel,
            ]);
        });
    }

    protected function sendTimelineOnCompletion(): void
    {
        app()->terminating(function () {
            if (empty($this->timeline)) {
                return;
            }

            // Sort timeline by timestamp
            usort($this->timeline, function ($a, $b) {
                return $a['timestamp'] <=> $b['timestamp'];
            });

            // Calculate relative times
            $firstTimestamp = $this->timeline[0]['timestamp'] ?? microtime(true);
            foreach ($this->timeline as &$event) {
                $event['relative_time_ms'] = round(($event['timestamp'] - $firstTimestamp) * 1000, 2);
            }

            // Record complete timeline
            $this->baddybugs->record('timeline', 'trace_timeline', [
                'trace_id' => $this->traceId,
                'event_count' => count($this->timeline),
                'total_duration_ms' => round((microtime(true) - $this->requestStartTime) * 1000, 2),
                'events' => $this->timeline,
                'detail_level' => config('baddybugs.timeline_detail_level', 'detailed'),
            ]);
            
            // **NEW: Send lifecycle phases as trace_spans for waterfall visualization**
            $this->sendLifecycleSpans();
            
            if (config('baddybugs.regression_capture_baselines', true)) {
                $interval = (int) config('baddybugs.regression_baseline_snapshot_interval', 100);
                $count = null;
                try {
                    $count = Cache::increment('baddybugs:baseline_counter');
                } catch (\Throwable $e) {
                    $count = random_int(1, max(1, $interval));
                }
                
                if ($interval > 0 && $count % $interval === 0) {
                    $queryCount = 0;
                    $status = null;
                    foreach ($this->timeline as $ev) {
                        if ($ev['type'] === 'database' && $ev['name'] === 'query') {
                            $queryCount++;
                        }
                        if ($ev['type'] === 'request' && $ev['name'] === 'completed' && isset($ev['data']['status_code'])) {
                            $status = (int) $ev['data']['status_code'];
                        }
                    }
                    
                    $errorFlag = ($status && $status >= 500) ? 1 : 0;
                    
                    $this->baddybugs->record('regression', 'baseline_snapshot', [
                        'duration_ms' => round((microtime(true) - $this->requestStartTime) * 1000, 2),
                        'query_count' => $queryCount,
                        'memory_peak' => memory_get_peak_usage(true),
                        'error_flag' => $errorFlag,
                    ]);
                }
            }
        });
    }

    /**
     * Send lifecycle phases as trace_spans for waterfall visualization
     */
    protected function sendLifecycleSpans(): void
    {
        $spans = [];
        $bootstrapEnd = null;
        $middlewareStart = null;
        $middlewareEnd = null;
        $controllerName = null;
        
        // Extract lifecycle events from timeline
        foreach ($this->timeline as $event) {
            // Bootstrap phase (request started to route matched)
            if ($event['type'] === 'request' && $event['name'] === 'started') {
                $bootstrapEnd = $event['timestamp'];
            }
            
            // Route matched = end of bootstrap, start of middleware
            if ($event['type'] === 'routing' && $event['name'] === 'matched') {
                $middlewareStart = $event['timestamp'];
                $controllerName = $event['data']['controller'] ?? null;
            }
            
            // Request completed = end of everything
            if ($event['type'] === 'request' && $event['name'] === 'completed') {
                $middlewareEnd = $event['timestamp'];
            }
        }
        
        // BOOTSTRAP span
        if ($this->requestStartTime && $bootstrapEnd) {
            $spans[] = [
                'type' => 'bootstrap',
                'name' => 'Application Bootstrap',
                'start_time' => $this->requestStartTime,
                'end_time' => $bootstrapEnd,
                'duration_ms' => round(($bootstrapEnd - $this->requestStartTime) * 1000, 2),
            ];
        }
        
        // MIDDLEWARE span
        if ($middlewareStart && $middlewareEnd) {
            $spans[] = [
                'type' => 'middleware',
                'name' => 'Middleware Stack',
                'start_time' => $middlewareStart,
                'end_time' => $middlewareEnd,
                'duration_ms' => round(($middlewareEnd - $middlewareStart) * 1000, 2),
            ];
        }
        
        // CONTROLLER span
        if ($controllerName && $middlewareEnd) {
            $controllerStart = $middlewareEnd;
            $controllerEnd = microtime(true);
            $spans[] = [
                'type' => 'controller',
                'name' => "Controller: {$controllerName}",
                'start_time' => $controllerStart,
                'end_time' => $controllerEnd,
                'duration_ms' => round(($controllerEnd - $controllerStart) * 1000, 2),
            ];
        }
        
        // SENDING span
        $sendingStart = microtime(true) - 0.001;
        $sendingEnd = microtime(true);
        $spans[] = [
            'type' => 'sending',
            'name' => 'Sending Response',
            'start_time' => $sendingStart,
            'end_time' => $sendingEnd,
            'duration_ms' => round(($sendingEnd - $sendingStart) * 1000, 2),
        ];
        
        // Record each span
        foreach ($spans as $span) {
            $this->baddybugs->record('trace_span', $span['type'], $span);
        }
    }

    /**
     * Add an event to the timeline
     */
    public function addTimelineEvent(string $type, string $name, array $data = [], ?float $timestamp = null): void
    {
        $this->timeline[] = [
            'type' => $type,
            'name' => $name,
            'data' => $data,
            'timestamp' => $timestamp ?? microtime(true),
        ];
    }

    /**
     * Get current timeline
     */
    public function getTimeline(): array
    {
        return $this->timeline;
    }

    /**
     * Get timeline event count
     */
    public function getEventCount(): int
    {
        return count($this->timeline);
    }

    /**
     * Clear timeline (useful for testing)
     */
    public function clearTimeline(): void
    {
        $this->timeline = [];
    }
}

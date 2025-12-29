<?php

namespace BaddyBugs\Agent;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Str;
use BaddyBugs\Agent\Buffers\BufferInterface;
use BaddyBugs\Agent\Collectors\CacheCollector;
use BaddyBugs\Agent\Collectors\CommandCollector;
use BaddyBugs\Agent\Collectors\EventCollector;
use BaddyBugs\Agent\Collectors\ExceptionCollector;
use BaddyBugs\Agent\Collectors\JobCollector;
use BaddyBugs\Agent\Collectors\MailCollector;
use BaddyBugs\Agent\Collectors\QueryCollector;
use BaddyBugs\Agent\Collectors\RequestCollector;
use BaddyBugs\Agent\Collectors\GateCollector;
use BaddyBugs\Agent\Collectors\RedisCollector;
use BaddyBugs\Agent\Collectors\TestCollector;
use GuzzleHttp\Middleware;
use Psr\Http\Message\RequestInterface;

class BaddyBugs
{
    /**
     * The application instance.
     */
    protected Application $app;

    /**
     * The current request/process trace ID.
     */
    protected string $traceId;

    /**
     * The current frontend session ID (if linked).
     */
    protected ?string $sessionId = null;

    /**
     * Active timers.
     */
    protected array $timers = [];

    /**
     * The loaded collectors.
     */
    protected array $collectors = [];

    /**
     * Git context collector.
     */
    protected ?Support\GitCollector $gitCollector = null;

    /**
     * Deployment tracker for regression analysis.
     */
    protected ?Support\DeploymentTracker $deploymentTracker = null;

    /**
     * User resolver callback.
     */
    protected $userResolver = null;

    /**
     * Global event filter callback.
     */
    protected $globalFilter = null;

    /**
     * Specific type filters.
     */
    protected array $typeFilters = [];

    /**
     * URL redactor callback.
     */
    protected $urlRedactor = null;

    /**
     * Shared context to be attached to all events.
     */
    protected array $sharedContext = [];

    /**
     * Create a new BaddyBugs instance.
     */
    public function __construct(Application $app)
    {
        $this->app = $app;
        // Generate a trace ID for this request/process life-cycle
        $this->traceId = (string) Str::orderedUuid();
        
        // Initialize Git collector for deployment correlation
        if (config('baddybugs.git_correlation_enabled', true)) {
            $this->gitCollector = new Support\GitCollector();
        }
        
        // Initialize Deployment tracker for regression analysis
        if (config('baddybugs.regression_analysis_enabled', true)) {
            $this->deploymentTracker = new Support\DeploymentTracker();
        }
    }

    /**
     * Boot all enabled collectors.
     */
    public function bootCollectors(): void
    {
        if (!config('baddybugs.enabled')) {
            return;
        }

        $candidates = [
            'requests' => RequestCollector::class,
            'queries' => QueryCollector::class,
            'jobs' => JobCollector::class,
            'commands' => CommandCollector::class,
            'exceptions' => ExceptionCollector::class,
            'cache' => CacheCollector::class,
            'mail' => MailCollector::class,
            'events' => EventCollector::class,
            'http_client' => Collectors\HttpClientCollector::class,
            'models' => Collectors\ModelCollector::class,
            'logs' => Collectors\LogCollector::class,
            'notifications' => Collectors\NotificationCollector::class,
            'schedule' => Collectors\ScheduledTaskCollector::class,
            'llm' => Collectors\LLMCollector::class,
            'gate' => GateCollector::class,
            'redis' => RedisCollector::class,
            'test' => TestCollector::class,
        ];

        foreach ($candidates as $key => $class) {
            if (config("baddybugs.collectors.{$key}") === true) {
                $collector = $this->app->make($class);
                $collector->boot();
                $this->collectors[$key] = $collector;
            }
        }
    }

    /**
     * Record an event to the buffer.
     */
    public function record(string $type, string $name, array $payload = []): void
    {
        if (!config('baddybugs.enabled')) {
            return;
        }

        // 1. Check Sampling
        if (!$this->shouldSample($type, $payload)) {
            return;
        }

        // 2. Check Filtering (Global & Type Specific)
        if ($this->shouldFilter($type, $name, $payload)) {
            return;
        }

        // 3. Prepare Entry
        $entry = [
            'id' => (string) Str::orderedUuid(),
            'trace_id' => $this->traceId,
            'session_id' => $this->sessionId,
            // 'project_id' => config('baddybugs.project_id'), // Removed: API Key identifies project
            'release' => config('baddybugs.release'),
            'git_sha' => config('baddybugs.git_sha'),
            'env' => config('baddybugs.env'),
            'type' => $type,
            'name' => $name,
            'payload' => $this->redact($payload),
            'context' => $this->sharedContext, // Merge shared context
            'timestamp' => microtime(true),
            'datetime' => now()->toIso8601String(),
            'host' => gethostname(),
            'memory' => memory_get_usage(true),
        ];

        // Enrich with Git context if available
        if ($this->gitCollector && $this->gitCollector->hasContext()) {
            $entry = array_merge($entry, $this->gitCollector->getContext());
        }

        // Enrich with Deployment context if available
        if ($this->deploymentTracker && $this->deploymentTracker->hasContext()) {
            $entry = array_merge($entry, $this->deploymentTracker->getContext());
        }

        // Push to buffer
        try {
            $this->app->make(BufferInterface::class)->push($entry);
        } catch (\Throwable $e) {
            // Fail silently
        }
    }

    /**
     * Determine if the event should be sampled.
     */
    protected function shouldSample(string $type, array $payload): bool
    {
        $samplingRate = config("baddybugs.sampling.{$type}") ?? config('baddybugs.sampling.default', 1.0);
        $forceSample = false;
        
        if ($type === 'http_client') {
            $status = $payload['status'] ?? null;
            $success = $payload['success'] ?? null;
            $duration = $payload['duration_ms'] ?? null;
            $threshold = (float) config('baddybugs.http_client_slow_threshold_ms', 500);
            $errorForce = (bool) config('baddybugs.http_client_error_force_sample', true);
            
            if ($errorForce && (($status !== null && (int) $status >= 500) || ($success === false))) {
                $forceSample = true;
            }
            if ($duration !== null && (float) $duration > $threshold) {
                $forceSample = true;
            }
            
            $host = null;
            if (isset($payload['url'])) {
                $host = parse_url((string) $payload['url'], PHP_URL_HOST);
            }
            $overrides = (array) config('baddybugs.http_client_sampling_overrides', []);
            if ($host && array_key_exists($host, $overrides)) {
                $overrideRate = (float) $overrides[$host];
                if ($overrideRate > 0 && $overrideRate <= 1) {
                    $samplingRate = $overrideRate;
                }
            }
        }
        
        if (!$forceSample && $samplingRate < 1.0 && (mt_rand() / mt_getrandmax()) > $samplingRate) {
            return false;
        }

        return true;
    }

    /**
     * Determine if the event should be filtered out.
     */
    protected function shouldFilter(string $type, string $name, array $payload): bool
    {
        // Global Filter
        if ($this->globalFilter && ($this->globalFilter)($type, $name, $payload) === false) {
            return true;
        }

        // Specific Type Filter
        if (isset($this->typeFilters[$type])) {
            foreach ($this->typeFilters[$type] as $filter) {
                if ($filter($name, $payload) === false) {
                    return true;
                }
            }
        }

        return false;
    }

    /*
    |--------------------------------------------------------------------------
    | Context Management
    |--------------------------------------------------------------------------
    */

    /**
     * Add global context to the current request trace.
     * This context will be attached to ALL subsequent events in this request.
     *
     * @param array $context Key-value pairs to add to context
     */
    public function context(array $context): void
    {
        $this->sharedContext = array_merge($this->sharedContext, $context);
        
        // Also record a dedicated context update event for timeline reconstruction
        $this->record('context', 'update', $context);
    }

    /**
     * Get the current shared context.
     */
    public function getContext(): array
    {
        return $this->sharedContext;
    }

    /**
     * Set the user mapping callback.
     */
    public function user(callable $callback): void
    {
        $this->userResolver = $callback;
    }

    /**
     * Resolve user data using the resolver or default logic.
     */
    public function resolveUser($user): ?array
    {
        if ($this->userResolver) {
            return ($this->userResolver)($user);
        }

        try {
            return [
                'id' => $user->getAuthIdentifier(),
                'email' => $user->email ?? null,
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Filtering & Redaction
    |--------------------------------------------------------------------------
    */

    /**
     * Register a global filter callback.
     * Return `false` to prevent recording.
     * Callback receives: (string $type, string $name, array $payload)
     */
    public function filter(callable $callback): void
    {
        $this->globalFilter = $callback;
    }

    /**
     * Register a filter for exceptions.
     * Callback receives: (\Throwable $exception)
     */
    public function filterExceptions(callable $callback): void
    {
        $this->typeFilters['exception'][] = $callback;
    }

    /**
     * Check if an exception should be filtered (discarded).
     * Returns true if it SHOULD be filtered (discarded).
     */
    public function shouldFilterException(\Throwable $e): bool
    {
        if (isset($this->typeFilters['exception'])) {
            foreach ($this->typeFilters['exception'] as $filter) {
                // User returns FALSE to discard (as per generic filter)?
                // OR User returns TRUE to KEEP?
                // Nightwatch convention: "Return false to discard".
                // So if filter($e) === false, we return true (should filter).
                if ($filter($e) === false) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Register a filter for queries.
     * Callback receives: (string $sql, array $bindings, float $time, string $connection)
     */
    public function filterQueries(callable $callback): void
    {
        $this->typeFilters['query'][] = $callback;
        
        // Maintain backward compatibility with specific setter
        $this->queryFilter = $callback;
    }

    /* Updated helper with full context support */
    public function shouldFilterQuery(string $query, array $bindings = [], ?float $time = null, ?string $connection = null): bool
    {
        if (isset($this->typeFilters['query'])) {
             foreach ($this->typeFilters['query'] as $filter) {
                // Nightwatch convention: Return false to discard.
                // We pass available args.
                // If the callback only expects 2 args, PHP is fine with extra args usually?
                // Closures in PHP don't error on extra args if not typed strictly against it?
                // Actually they do if Reflection is involved, but direct invocation matches param count?
                // No, calling $fn($a, $b, $c) when $fn($a, $b) is defined ... IS valid in PHP for user defined functions?
                // Actually it is NOT valid if strict types/params.
                // But we can't know. 
                // However, for safety, strictly generic approach is safer.
                // Assuming standard usage.
                
                // Note: The previous simple `queryFilter` callback setter implied ($sql, $bindings).
                // If we pass 4 args to a 2 arg closure, it might NOT error in basic PHP usage but ArgumentCountError is possible.
                // We will wrap in try-catch to be safe if user defined simpler callback.
                
                try {
                    if ($filter($query, $bindings, $time, $connection) === false) {
                         return true; 
                    }
                } catch (\ArgumentCountError $e) {
                    // Fallback for callbacks that accept fewer arguments
                    if ($filter($query, $bindings) === false) {
                        return true;
                    }
                }
             }
        }
        return false;
    }

    /**
     * Register a filter for jobs.
     * Callback receives: (JobEvent $event)
     */
    public function filterJobs(callable $callback): void
    {
        $this->typeFilters['job'][] = $callback;
    }

    /**
     * Check if a job should be filtered.
     */
    public function shouldFilterJob($event): bool
    {
         if (isset($this->typeFilters['job'])) {
            foreach ($this->typeFilters['job'] as $filter) {
                if ($filter($event) === false) {
                    return true;
                }
            }
        }
        return false;
    }

    public function shouldFilterMail($event): bool
    {
         if (isset($this->typeFilters['mail'])) {
            foreach ($this->typeFilters['mail'] as $filter) {
                if ($filter($event) === false) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Register a filter for mail.
     */
    public function filterMail(callable $callback): void
    {
        $this->typeFilters['mail'][] = $callback;
    }

    /**
     * Register a filter for notifications.
     */
    public function rejectNotifications(callable $callback): void
    {
        $this->typeFilters['notification'][] = function ($name, $payload) use ($callback) {
            return true; 
        };
        
        $this->notificationFilter = $callback;
    }
    
    /**
     * Helper for NotificationCollector to check before processing.
     */
    public function shouldRejectNotification($notification, $channels): bool
    {
        if ($this->notificationFilter) {
            return ($this->notificationFilter)($notification, $channels) === true;
        }
        return false;
    }

    /**
     * Set the URL redaction callback.
     */
    public function redactUrls(callable $callback): void
    {
        $this->urlRedactor = $callback;
    }

    /**
     * Redact a URL using the redactor or default logic.
     */
    public function performUrlRedaction(string $url): string
    {
        if ($this->urlRedactor) {
            return ($this->urlRedactor)($url);
        }

        return $url;
    }

    /**
     * Set the frontend session ID.
     */
    public function setSessionId(?string $sessionId): void
    {
        $this->sessionId = $sessionId;
    }

    /**
     * Get the current Trace ID.
     */
    public function getTraceId(): string
    {
        return $this->traceId;
    }

    /**
     * Set a specific Trace ID (e.g. propagated from upstream).
     */
    public function setTraceId(string $traceId): void
    {
        $this->traceId = $traceId;
    }

    /**
     * Redact sensitive information from the payload.
     */
    protected function redact(array $payload): array
    {
        // Simple recursive redaction
        $keysToRedact = config('baddybugs.redact_keys', []);

        array_walk_recursive($payload, function (&$value, $key) use ($keysToRedact) {
            if (in_array(strtolower($key), $keysToRedact)) {
                $value = '********';
            }
        });

        return $payload;
    }

    /**
     * Enable session replay for the current session.
     */
    public function startSessionReplay(): void
    {
        Support\SessionReplaySampler::enableForCurrentSession();
        
        $this->record('session_replay', 'enabled', [
            'user_id' => auth()->id(),
            'forced' => true,
        ]);
    }

    /**
     * Disable session replay for the current session.
     */
    public function stopSessionReplay(): void
    {
        Support\SessionReplaySampler::disableForCurrentSession();
        
        $this->record('session_replay', 'disabled', [
            'user_id' => auth()->id(),
        ]);
    }

    /**
     * Check if session replay is enabled for the current session.
     */
    public function isSessionReplayEnabled(): bool
    {
        return Support\SessionReplaySampler::shouldRecordSession() || 
               Support\SessionReplaySampler::isForcedForCurrentSession();
    }

    /**
     * Get a Guzzle middleware for trace propagation and collection.
     */
    public static function guzzleMiddleware(): callable
    {
        return Middleware::mapRequest(function (RequestInterface $request) {
            $traceId = app()->bound(self::class)
                ? app(self::class)->getTraceId()
                : (string) Str::orderedUuid();
            
            return $request->withHeader('X-Baddybugs-Trace-Id', $traceId);
        });
    }
    
    /*
    |--------------------------------------------------------------------------
    | Feature Tracking & Product Analytics
    |--------------------------------------------------------------------------
    */

    public function feature(string $name, array $properties = []): void
    {
        if (!config('baddybugs.feature_tracking_enabled', true)) {
            return;
        }

        try {
            $collector = $this->app->make(Collectors\FeatureCollector::class);
            $collector->trackFeature($name, $properties);
        } catch (\Throwable $e) {
            // Silent failure
        }
    }

    public function track(string $event, array $properties = []): void
    {
        if (!config('baddybugs.feature_tracking_enabled', true)) {
            return;
        }

        try {
            $collector = $this->app->make(Collectors\FeatureCollector::class);
            $collector->trackEvent($event, $properties);
        } catch (\Throwable $e) {
            // Silent failure
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Security & Vulnerability Reporting
    |--------------------------------------------------------------------------
    */

    public function scanForVulnerabilities(): void
    {
        if (!config('baddybugs.security_enabled', true)) {
            return;
        }

        try {
            $collector = $this->app->make(Collectors\SecurityCollector::class);
            $collector->reportIssue('manual_scan', [
                'triggered_by' => 'manual',
                'user_id' => auth()->id(),
            ]);
        } catch (\Throwable $e) {
            // Silent failure
        }
    }

    public function reportSecurityIssue(string $type, array $details): void
    {
        if (!config('baddybugs.security_enabled', true)) {
            return;
        }

        try {
            $collector = $this->app->make(Collectors\SecurityCollector::class);
            $collector->reportIssue($type, $details);
        } catch (\Throwable $e) {
            // Silent failure
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Health & Monitoring
    |--------------------------------------------------------------------------
    */

    public function heartbeat(?string $component = null): void
    {
        $this->record('health', 'heartbeat', [
            'component' => $component,
            'timestamp' => now()->toIso8601String(),
            'uptime' => $this->getUptime(),
        ]);
    }

    public function reportHealth(string $component, array $metrics): void
    {
        $this->record('health', 'component_health', array_merge([
            'component' => $component,
            'timestamp' => now()->toIso8601String(),
        ], $metrics));
    }

    protected function getUptime(): float
    {
        if (defined('LARAVEL_START')) {
            return microtime(true) - LARAVEL_START;
        }

        return 0.0;
    }

    /*
    |--------------------------------------------------------------------------
    | Profiling Helpers
    |--------------------------------------------------------------------------
    */

    public function startTimer(string $name): void
    {
        if (!config('baddybugs.profiling_enabled', true)) {
            return;
        }

        try {
            $collector = $this->app->make(Collectors\ProfilingCollector::class);
            $collector->startTimer($name);
        } catch (\Throwable $e) {
            // Silent failure
        }
    }

    public function stopTimer(string $name): void
    {
        if (!config('baddybugs.profiling_enabled', true)) {
            return;
        }

        try {
            $collector = $this->app->make(Collectors\ProfilingCollector::class);
            $collector->stopTimer($name);
        } catch (\Throwable $e) {
            // Silent failure
        }
    }

    public function timer(string $name, callable $callback): mixed
    {
        $this->startTimer($name);
        
        try {
            return $callback();
        } finally {
            $this->stopTimer($name);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Regression Risk Analysis
    |--------------------------------------------------------------------------
    */

    public function deployment(?string $tag = null, array $metadata = []): void
    {
        if (!config('baddybugs.regression_analysis_enabled', true)) {
            return;
        }

        if (!$this->deploymentTracker) {
            return;
        }

        // Get deployment data
        $deploymentData = array_merge(
            $this->deploymentTracker->getDeploymentMetadata(),
            $metadata
        );

        // Override tag if provided
        if ($tag) {
            $deploymentData['deployment_tag'] = $tag;
        }

        // Add metadata from parameters
        if (isset($metadata['released_by'])) {
            $deploymentData['deployment_released_by'] = $metadata['released_by'];
        }

        if (isset($metadata['notes'])) {
            $deploymentData['deployment_notes'] = $metadata['notes'];
        }

        // Record deployment_started event
        $this->record('deployment', 'deployment_started', $deploymentData);

        // Mark deployment as processed
        $this->deploymentTracker->markDeploymentProcessed();
    }

    public function getDeploymentTracker(): ?Support\DeploymentTracker
    {
        return $this->deploymentTracker;
    }

    public function getDeploymentHash(): ?string
    {
        return $this->deploymentTracker?->getDeploymentHash();
    }

    public function getDeploymentTag(): ?string
    {
        return $this->deploymentTracker?->getDeploymentTag();
    }

    /**
     * track an LLM interaction.
     */
    public static function trackLLM(
        string $provider,
        string $model,
        string $prompt,
        string $response,
        array $usage,
        float $durationMs,
        ?float $costUsd = null
    ) {
        if (app()->bound(self::class)) {
            $instance = app(self::class);
            if (isset($instance->collectors['llm'])) {
                $instance->collectors['llm']->record(
                    $provider, $model, $prompt, $response, $usage, $durationMs, $costUsd
                );
            }
        }
    }
}

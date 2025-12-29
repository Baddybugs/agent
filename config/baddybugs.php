<?php

return [
    /*
    |--------------------------------------------------------------------------
    | BaddyBugs Agent Enabled
    |--------------------------------------------------------------------------
    |
    | Enable or disable the monitoring agent. You might want to enable this
    | only in non-local environments or specific debugging scenarios.
    |
    */
    'enabled' => env('BADDYBUGS_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Performance Mode
    |--------------------------------------------------------------------------
    |
    | When enabled, minimizes overhead by:
    | - Disabling debug_backtrace() for query caller detection
    | - Skipping source code extraction for exceptions
    | - Reducing breadcrumb storage
    |
    | Recommended for apps handling > 1000 req/sec.
    |
    */
    'performance_mode' => env('BADDYBUGS_PERFORMANCE_MODE', false),

    /*
    |--------------------------------------------------------------------------
    | Identification
    |--------------------------------------------------------------------------
    |
    | Provide the API Key and Project ID to authenticate with the BaddyBugs
    | platform.
    |
    */
    'api_key' => env('BADDYBUGS_API_KEY'),
    'project_id' => env('BADDYBUGS_PROJECT_ID'),
    'app_name' => env('APP_NAME', 'Laravel'),
    'env' => env('APP_ENV', 'production'),
    
    /*
    |--------------------------------------------------------------------------
    | Release Tracking
    |--------------------------------------------------------------------------
    |
    | For best performance, set these via environment variables during deploy.
    | If not set, the agent will NOT run git commands (zero overhead).
    |
    */
    'release' => env('BADDYBUGS_RELEASE'),
    'git_sha' => env('BADDYBUGS_GIT_SHA'),

    /*
    |--------------------------------------------------------------------------
    | Server Endpoint
    |--------------------------------------------------------------------------
    |
    | The URL where the collected data will be sent.
    |
    */
    'endpoint' => env('BADDYBUGS_ENDPOINT', 'https://api.baddybugs.test/v1/ingest'),

    /*
    |--------------------------------------------------------------------------
    | Buffering & Sending Strategy
    |--------------------------------------------------------------------------
    |
    | Configure how data is buffered before sending.
    | Drivers: 
    |  - 'memory': buffers in memory and sends on shutdown (fastest development)
    |  - 'file': buffers to disk, requires helper process to send (best for production web)
    |  - 'redis': buffers to redis list (high performance)
    |
    */
    'buffer_driver' => env('BADDYBUGS_BUFFER_DRIVER', 'memory'),
    
    // For file buffer (and fallback)
    'storage_path' => storage_path('baddybugs/buffer'),
    
    // Batch size / interval
    'batch_size' => 100, // Max items in one batch
    'send_timeout' => 2.0, // Timeout for HTTP requests to endpoint
    'retry_attempts' => 3,

    /*
    |--------------------------------------------------------------------------
    | Enabled Collectors
    |--------------------------------------------------------------------------
    |
    | Toggle specific collectors on or off. Each collector adds a small overhead.
    |
    */
    'collectors' => [
        'requests' => true,
        'queries' => true,
        'jobs' => true,
        'commands' => true,
        'schedule' => true,
        'llm' => true,
        'exceptions' => true,
        'cache' => true,
        'mail' => true,
        'notifications' => true,
        'events' => true,
        'logs' => true,
        'http_client' => true,
        'models' => true,           // NEW: Track Eloquent model changes (breadcrumbs)
        'models_detailed' => false, // NEW: Full model event logging (high volume)
        'profiling' => false,
        'gate' => true,             // NEW: Track Gate/Authorization checks
        'redis' => true,            // NEW: Track Redis commands
        'test' => env('BADDYBUGS_TEST_MONITORING', false), // NEW: CI/CD Test reporting
    ],

    /*
    |--------------------------------------------------------------------------
    | Sampling Rates (0.0 to 1.0)
    |--------------------------------------------------------------------------
    |
    | Control the volume of data collected for high-frequency events.
    |
    */
    'sampling' => [
        'default' => 1.0,
        'requests' => 1.0, 
        'queries' => 1.0,
        'jobs' => 1.0,
        'exceptions' => 1.0,
        'http_client' => env('BADDYBUGS_HTTP_CLIENT_SAMPLING', 1.0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Sensitive Data Redaction
    |--------------------------------------------------------------------------
    |
    | Keys to automatically redact from payloads (headers, inputs, session).
    |
    */
    'redact_keys' => [
        'password',
        'password_confirmation',
        'token',
        'api_key',
        'secret',
        'credit_card',
        'cvv',
        'ssn',
        'authorization',
        'cookie',
        'session_id',
        'xsrf-token',
    ],
    
    'redact_headers' => [
        'authorization',
        'cookie',
        'set-cookie',
        'x-xsrf-token',
    ],

    /*
    |--------------------------------------------------------------------------
    | Query Monitoring Settings
    |--------------------------------------------------------------------------
    |
    | Threshold in milliseconds to consider a query "slow".
    |
    */
    'slow_query_threshold' => 100, // ms
    'detect_n_plus_one' => true,
    'explain_slow_queries' => true, // NEW: Run EXPLAIN on slow SELECT queries

    /*
    |--------------------------------------------------------------------------
    | Log Collection
    |--------------------------------------------------------------------------
    |
    | Which log levels to fully collect (not just breadcrumbs).
    |
    */
    'log_levels' => ['emergency', 'alert', 'critical', 'error', 'warning'],

    /*
    |--------------------------------------------------------------------------
    | Distributed Tracing
    |--------------------------------------------------------------------------
    |
    | Enable simple trace ID propagation across jobs/queues.
    |
    */
    'tracing' => true,

    /*
    |--------------------------------------------------------------------------
    | Advanced: Security & Performance
    |--------------------------------------------------------------------------
    */
    'compress' => true,             // NEW: Gzip compress payloads before sending
    'sign_payloads' => true,        // NEW: HMAC sign payloads for integrity
    'signing_secret' => env('BADDYBUGS_SIGNING_SECRET'), // Defaults to api_key if not set

    /*
    |--------------------------------------------------------------------------
    | Redis Buffer Settings (when buffer_driver = 'redis')
    |--------------------------------------------------------------------------
    */
    'redis_connection' => 'default',
    'redis_key' => 'baddybugs:buffer',
    'redis_max_size' => 10000,

    /*
    |--------------------------------------------------------------------------
    | Exclusions
    |--------------------------------------------------------------------------
    |
    | Paths, commands, or jobs to ignore.
    |
    */
    'ignore_paths' => [
        'baddybugs/*',
        'telescope/*',
        'nova/*',
        '_debugbar/*',
        'livewire/*',
    ],
    
    'ignore_commands' => [
        'baddybugs:*',
        'queue:work',
        'queue:listen',
        'vendor:publish',
        'package:discover',
    ],
    
    'ignore_jobs' => [
        // 'App\Jobs\SensitiveJob',
    ],
    
    'ignore_domains' => [
        parse_url(env('BADDYBUGS_ENDPOINT', 'https://api.baddybugs.test'), PHP_URL_HOST),
    ],
    
    'http_client_slow_threshold_ms' => env('BADDYBUGS_HTTP_CLIENT_SLOW_MS', 500),
    'http_client_error_force_sample' => env('BADDYBUGS_HTTP_CLIENT_ERROR_FORCE', true),
    'http_client_sampling_overrides' => [
        // 'api.stripe.com' => 1.0,
        // 'api.example.com' => 0.5,
    ],

    /*
    |--------------------------------------------------------------------------
    | Frontend Observability (Blade + Livewire)
    |--------------------------------------------------------------------------
    |
    | Native frontend monitoring for Blade + Livewire applications.
    | This AUTOMATICALLY covers 100% of FilamentPHP applications.
    |
    | For Inertia.js, Vue, React, or Alpine.js: use @baddybugs/js-sdk instead.
    |
    */

    /*
     * Enable frontend monitoring features
     * When enabled, the @baddybugs directive and trace_id injection will be active
     */
    'frontend_enabled' => env('BADDYBUGS_FRONTEND_ENABLED', true),

    /*
     * Expose trace_id to frontend via meta tag and view()->share()
     * This allows perfect correlation between frontend and backend events
     */
    'expose_trace_id' => env('BADDYBUGS_EXPOSE_TRACE_ID', true),

    /*
     * Frontend sampling rate (0.0 to 1.0)
     * Controls what percentage of frontend events are captured
     * 1.0 = capture all, 0.5 = capture 50%, etc.
     */
    'frontend_sampling_rate' => env('BADDYBUGS_FRONTEND_SAMPLING_RATE', 1.0),
    'frontend_web_vitals_enabled' => env('BADDYBUGS_FRONTEND_WEB_VITALS', true),
    'frontend_web_vitals_sampling_rate' => env('BADDYBUGS_FRONTEND_WEB_VITALS_SAMPLING_RATE', 1.0),

    /*
     * Enable deep Livewire 3 monitoring
     * Automatically captures:
     * - Component lifecycle errors
     * - Network failures (message.failed)
     * - Slow/timeout requests (message.processing)
     * - Hydration errors (component.dehydrate)
     *
     * Works with vanilla Livewire AND FilamentPHP (resources, widgets, actions, modals)
     */
    'livewire_monitoring_enabled' => env('BADDYBUGS_LIVEWIRE_MONITORING', true),

    /*
     * Livewire request timeout threshold (milliseconds)
     * If a Livewire request takes longer than this, it will be logged as a performance issue
     */
    'livewire_timeout_threshold' => env('BADDYBUGS_LIVEWIRE_TIMEOUT_MS', 10000), // 10 seconds

    /*
     * Capture Livewire component initialization events
     * Useful for tracking component usage and load patterns
     * WARNING: Can generate high volumes of data in component-heavy apps
     */
    'livewire_track_initialization' => env('BADDYBUGS_LIVEWIRE_TRACK_INIT', false),

    /*
    |--------------------------------------------------------------------------
    | Session Replay (Full-Stack Recording like Highlight.io)
    |--------------------------------------------------------------------------
    |
    | Record and replay user sessions to debug issues in production.
    | The PHP backend provides configuration and correlation data.
    | Actual recording happens client-side via @baddybugs/js-sdk using rrweb.
    |
    | Privacy modes:
    | - 'strict': Mask all text inputs, block sensitive selectors (PCI compliant)
    | - 'moderate': Mask only password fields and payment inputs
    | - 'none': Record everything (use with caution!)
    |
    */

    /*
     * Enable session replay recording
     * WARNING: Can generate significant storage/bandwidth usage
     * Always use with sampling_rate < 1.0 in production
     */
    'session_replay_enabled' => env('BADDYBUGS_SESSION_REPLAY_ENABLED', false),

    /*
     * Sampling rate for session replay (0.0 to 1.0)
     * 
     * Examples:
     * - 1.0 = Record 100% of sessions (development only!)
     * - 0.1 = Record 10% of sessions (reasonable for staging)
     * - 0.01 = Record 1% of sessions (recommended for production)
     * - 0.001 = Record 0.1% of sessions (high-traffic production)
     *
     * Sampling is deterministic per user_id/session to ensure
     * complete session histories for sampled users.
     */
    'session_replay_sampling_rate' => env('BADDYBUGS_SESSION_REPLAY_SAMPLING_RATE', 0.01),

    /*
     * Privacy mode for session recordings
     * 
     * 'strict' (default, PCI-DSS compliant):
     *   - Masks all text inputs
     *   - Blocks password fields entirely
     *   - Blocks elements matching block_selectors
     *   - Safe for production
     *
     * 'moderate':
     *   - Masks only sensitive inputs (passwords, credit cards)
     *   - Allows regular text to be visible
     *   - Use for internal debugging
     *
     * 'none':
     *   - No masking or blocking
     *   - Records everything as-is
     *   - ONLY use in development/staging!
     */
    'session_replay_privacy_mode' => env('BADDYBUGS_SESSION_REPLAY_PRIVACY', 'strict'),

    /*
     * CSS selectors for elements to completely block from recording
     * These elements will appear as blank boxes in the replay
     * 
     * Useful for: credit cards, SSNs, API keys, admin panels
     */
    'session_replay_block_selectors' => env(
        'BADDYBUGS_SESSION_REPLAY_BLOCK', 
        '.password, [data-private], .credit-card, [type="password"], .ssn, .api-key'
    ),

    /*
     * CSS selectors for text inputs to mask (show as ***)
     * Text will be replaced with asterisks in the replay
     * 
     * Useful for: names, emails, addresses (when privacy_mode is 'moderate' or 'none')
     */
    'session_replay_mask_text_selectors' => env(
        'BADDYBUGS_SESSION_REPLAY_MASK_TEXT', 
        'input[type="password"], input[type="email"], .credit-card-number, .cvv'
    ),

    /*
     * Additional privacy options
     */
    'session_replay_record_canvas' => env('BADDYBUGS_SESSION_REPLAY_RECORD_CANVAS', false), // Record canvas elements (charts, etc.)
    'session_replay_record_network' => env('BADDYBUGS_SESSION_REPLAY_RECORD_NETWORK', true), // Record network requests
    'session_replay_record_console' => env('BADDYBUGS_SESSION_REPLAY_RECORD_CONSOLE', true), // Record console logs
    'session_replay_record_performance' => env('BADDYBUGS_SESSION_REPLAY_RECORD_PERFORMANCE', true), // Record performance metrics

    /*
     * Sampling strategy: 'random' or 'deterministic'
     * 
     * 'deterministic' (recommended):
     *   - Same user always gets same sampling decision
     *   - Ensures complete session history for sampled users
     *   - Based on user_id hash
     *
     * 'random':
     *   - Each request independently sampled
     *   - May result in incomplete sessions
     */
    'session_replay_sampling_strategy' => env('BADDYBUGS_SESSION_REPLAY_SAMPLING_STRATEGY', 'deterministic'),

    /*
     * Session replay endpoint
     * Override if using a different endpoint than the main ingestion
     */
    'session_replay_endpoint' => env('BADDYBUGS_SESSION_REPLAY_ENDPOINT', null), // null = use main endpoint + '/sessions'

    /*
    |--------------------------------------------------------------------------
    | Advanced Profiling (CPU + Memory + Breakdown)
    |--------------------------------------------------------------------------
    |
    | Detailed performance profiling with phase breakdown and resource monitoring.
    | Captures CPU time, memory peaks, and timing for each application phase.
    |
    */

    /*
     * Enable advanced profiling
     * Captures detailed performance metrics for every request/job
     */
    'profiling_enabled' => env('BADDYBUGS_PROFILING_ENABLED', true),

    /*
     * Memory threshold for "memory heavy" detection (bytes)
     * Requests using more memory will be flagged
     */
    'profiling_memory_threshold' => env('BADDYBUGS_PROFILING_MEMORY_THRESHOLD', 50 * 1024 * 1024), // 50 MB

    /*
     * CPU time threshold for "CPU intensive" detection (milliseconds)
     * Requests taking more CPU time will be flagged
     */
    'profiling_cpu_threshold' => env('BADDYBUGS_PROFILING_CPU_THRESHOLD', 500), // 500ms

    /*
     * Enable detailed phase breakdown
     * Captures timing for: boot, middleware, controller, view, response
     */
    'profiling_phase_breakdown' => env('BADDYBUGS_PROFILING_PHASE_BREAKDOWN', true),

    /*
     * Enable flamegraph-like segment data
     * Creates detailed timing segments for visualization
     */
    'profiling_flamegraph_data' => env('BADDYBUGS_PROFILING_FLAMEGRAPH_DATA', true),

    /*
     * Sample rate for profiling (0.0 to 1.0)
     * 1.0 = profile all requests, 0.1 = profile 10%
     */
    'profiling_sampling_rate' => env('BADDYBUGS_PROFILING_SAMPLING_RATE', 1.0),

    /*
    |--------------------------------------------------------------------------
    | Centralized Logs (Monolog Integration)
    |--------------------------------------------------------------------------
    |
    | Capture all Laravel logs with enriched context and structured logging.
    | Automatically correlates logs with traces for debugging.
    |
    */

    /*
     * Enable centralized log collection
     * Captures all logs via custom Monolog handler
     */
    'logs_enabled' => env('BADDYBUGS_LOGS_ENABLED', true),

    /*
     * Minimum log level to capture
     * Options: debug, info, notice, warning, error, critical, alert, emergency
     */
    'logs_min_level' => env('BADDYBUGS_LOGS_MIN_LEVEL', 'warning'),

    /*
     * Enable structured logging (JSON)
     * Logs are sent as structured data instead of plain text
     */
    'logs_structured' => env('BADDYBUGS_LOGS_STRUCTURED', true),

    /*
     * Auto-enrich log context
     * Adds trace_id, user_id, url, etc. to every log
     */
    'logs_auto_context' => env('BADDYBUGS_LOGS_AUTO_CONTEXT', true),

    /*
     * Detect log patterns
     * Identifies repeated errors, warning spikes, etc.
     */
    'logs_pattern_detection' => env('BADDYBUGS_LOGS_PATTERN_DETECTION', true),

    /*
     * Pattern detection threshold
     * Number of identical logs within time window to trigger alert
     */
    'logs_pattern_threshold' => env('BADDYBUGS_LOGS_PATTERN_THRESHOLD', 10), // 10 occurrences
    'logs_pattern_window' => env('BADDYBUGS_LOGS_PATTERN_WINDOW', 60), // within 60 seconds

    /*
    |--------------------------------------------------------------------------
    | Security & Vulnerability Scanning
    |--------------------------------------------------------------------------
    |
    | Proactive security monitoring and vulnerability detection.
    | Scans for sensitive data exposure, injection attempts, and known vulnerabilities.
    |
    */

    /*
     * Enable security scanning
     * Monitors for security issues in real-time
     */
    'security_enabled' => env('BADDYBUGS_SECURITY_ENABLED', true),

    /*
     * Scan for sensitive data in logs/payloads
     * Detects: emails, tokens, credit cards, API keys, etc.
     */
    'security_scan_sensitive_data' => env('BADDYBUGS_SECURITY_SCAN_SENSITIVE_DATA', true),

    /*
     * Sensitive data patterns to detect
     * Regex patterns for credit cards, SSN, tokens, etc.
     */
    'security_sensitive_patterns' => [
        'credit_card' => '/\b\d{4}[-\s]?\d{4}[-\s]?\d{4}[-\s]?\d{4}\b/',
        'ssn' => '/\b\d{3}-\d{2}-\d{4}\b/',
        'api_key' => '/\b[a-zA-Z0-9]{32,}\b/',
        'jwt' => '/^[A-Za-z0-9-_=]+\.[A-Za-z0-9-_=]+\.?[A-Za-z0-9-_.+\/=]*$/',
        'private_key' => '/-----BEGIN (RSA |EC )PRIVATE KEY-----/',
    ],

    /*
     * Scan for SQL injection attempts
     * Detects common SQLi patterns in request params
     */
    'security_scan_sql_injection' => env('BADDYBUGS_SECURITY_SCAN_SQL_INJECTION', true),

    /*
     * SQL injection patterns
     */
    'security_sql_injection_patterns' => [
        '/(\bunion\b.*\bselect\b)/i',
        '/(\bselect\b.*\bfrom\b.*\bwhere\b)/i',
        '/(\'|\")(\s)*(or|and)(\s)*(\d+|true|false)(\s)*(=|<|>)/i',
        '/(\bdrop\b.*\btable\b)/i',
    ],

    /*
     * Scan for XSS attempts
     * Detects common XSS patterns in request params
     */
    'security_scan_xss' => env('BADDYBUGS_SECURITY_SCAN_XSS', true),

    /*
     * XSS patterns
     */
    'security_xss_patterns' => [
        '/<script\b[^>]*>(.*?)<\/script>/i',
        '/javascript:/i',
        '/on\w+\s*=/i', // onclick, onerror, etc.
        '/<iframe\b[^>]*>/i',
    ],

    /*
     * Detect dangerous production usage
     * Warns if dd(), dump(), debugbar enabled in production
     */
    'security_detect_dangerous_usage' => env('BADDYBUGS_SECURITY_DETECT_DANGEROUS_USAGE', true),

    /*
     * Dangerous functions to detect
     */
    'security_dangerous_functions' => [
        'dd', 'dump', 'var_dump', 'print_r', 'var_export',
    ],

    /*
     * Scan Composer packages for vulnerabilities
     * Checks composer.lock against known vulnerabilities (requires GitHub token)
     */
    'security_scan_composer' => env('BADDYBUGS_SECURITY_SCAN_COMPOSER', false),

    /*
     * GitHub token for vulnerability scanning
     * Used to query GitHub Security Advisory Database
     */
    'security_github_token' => env('BADDYBUGS_SECURITY_GITHUB_TOKEN'),

    /*
    |--------------------------------------------------------------------------
    | Feature Usage & Product Analytics
    |--------------------------------------------------------------------------
    |
    | Track feature usage and product metrics for analytics.
    | Helps understand how users interact with your application.
    |
    */

    /*
     * Enable feature tracking
     * Tracks routes, features, events for analytics
     */
    'feature_tracking_enabled' => env('BADDYBUGS_FEATURE_TRACKING_ENABLED', true),

    /*
     * Auto-track routes
     * Automatically logs route usage
     */
    'feature_track_routes' => env('BADDYBUGS_FEATURE_TRACK_ROUTES', true),

    /*
     * Auto-track jobs
     * Automatically logs job execution for analytics
     */
    'feature_track_jobs' => env('BADDYBUGS_FEATURE_TRACK_JOBS', true),

    /*
     * Auto-track custom events
     * Logs all Laravel events for analytics
     */
    'feature_track_custom_events' => env('BADDYBUGS_FEATURE_TRACK_CUSTOM_EVENTS', false),

    /*
     * Feature sampling rate (0.0 to 1.0)
     * Reduce volume for high-traffic features
     */
    'feature_sampling_rate' => env('BADDYBUGS_FEATURE_SAMPLING_RATE', 1.0),

    /*
    |--------------------------------------------------------------------------
    | Timeline & Trace Correlation
    |--------------------------------------------------------------------------
    |
    | Build complete timelines for each trace_id.
    | Shows chronological flow: request → queries → jobs → view → response.
    |
    */

    /*
     * Enable timeline collection
     * Builds ordered timeline for each trace
     */
    'timeline_enabled' => env('BADDYBUGS_TIMELINE_ENABLED', true),

    /*
     * Timeline detail level: 'basic', 'detailed', 'full'
     * - basic: major events only
     * - detailed: includes queries, cache
     * - full: everything including breadcrumbs
     */
    'timeline_detail_level' => env('BADDYBUGS_TIMELINE_DETAIL_LEVEL', 'detailed'),

    /*
    |--------------------------------------------------------------------------
    | Database & Cache Deep Dive
    |--------------------------------------------------------------------------
    |
    | Advanced database and cache monitoring.
    | EXPLAIN queries, cache analytics, performance insights.
    |
    */

    /*
     * Enable EXPLAIN ANALYZE on slow queries
     * Shows query execution plan for optimization
     * WARNING: Adds overhead on slow queries
     */
    'database_explain_queries' => env('BADDYBUGS_DATABASE_EXPLAIN_QUERIES', false),

    /*
     * Auto-EXPLAIN threshold (milliseconds)
     * Only EXPLAIN queries slower than this
     */
    'database_explain_threshold' => env('BADDYBUGS_DATABASE_EXPLAIN_THRESHOLD', 1000), // 1 second

    /*
     * Track cache hit/miss ratio
     * Provides cache performance analytics
     */
    'cache_analytics_enabled' => env('BADDYBUGS_CACHE_ANALYTICS_ENABLED', true),

    /*
     * Track top cache keys
     * Identifies most used cache keys
     */
    'cache_track_top_keys' => env('BADDYBUGS_CACHE_TRACK_TOP_KEYS', true),

    /*
     * Top keys limit
     */
    'cache_top_keys_limit' => env('BADDYBUGS_CACHE_TOP_KEYS_LIMIT', 100),

    /*
     * Detect cache thundering herd
     * Identifies multiple processes trying to regenerate same cache key
     */
    'cache_detect_thundering_herd' => env('BADDYBUGS_CACHE_DETECT_THUNDERING_HERD', true),

    /*
    |--------------------------------------------------------------------------
    | Health & Background Monitoring
    |--------------------------------------------------------------------------
    |
    | Monitor scheduled tasks, stuck jobs, queue health.
    | Ensures your background processes are healthy.
    |
    */

    /*
     * Enable health monitoring
     * Monitors cron, queues, jobs
     */
    'health_monitoring_enabled' => env('BADDYBUGS_HEALTH_MONITORING_ENABLED', true),

    /*
     * Monitor scheduled tasks
     * Tracks last run, next run, failures
     */
    'health_monitor_schedule' => env('BADDYBUGS_HEALTH_MONITOR_SCHEDULE', true),

    /*
     * Detect stuck jobs
     * Jobs running longer than threshold
     */
    'health_detect_stuck_jobs' => env('BADDYBUGS_HEALTH_DETECT_STUCK_JOBS', true),

    /*
     * Stuck job threshold (seconds)
     */
    'health_stuck_job_threshold' => env('BADDYBUGS_HEALTH_STUCK_JOB_THRESHOLD', 3600), // 1 hour

    /*
     * Monitor queue metrics
     * Throughput, P95 latency, failed ratio
     */
    'health_monitor_queues' => env('BADDYBUGS_HEALTH_MONITOR_QUEUES', true),

    /*
     * Heartbeat interval (seconds)
     * How often to send uptime heartbeat
     */
    'health_heartbeat_interval' => env('BADDYBUGS_HEALTH_HEARTBEAT_INTERVAL', 60),

    /*
    |--------------------------------------------------------------------------
    | Git & Deployment Correlation
    |--------------------------------------------------------------------------
    |
    | Track deployments and correlate issues with code changes.
    | Links errors to specific commits and deployments.
    |
    */

    /*
     * Enable git correlation
     * Tags events with commit hash and deployment info
     */
    'git_correlation_enabled' => env('BADDYBUGS_GIT_CORRELATION_ENABLED', true),

    /*
     * Auto-detect git commit hash
     * Tries to read from .git/ folder if env not set
     */
    'git_auto_detect_commit' => env('BADDYBUGS_GIT_AUTO_DETECT_COMMIT', true),

    /*
     * Git commit hash (override auto-detection)
     * Set during deployment: GIT_COMMIT=$(git rev-parse HEAD)
     */
    'git_commit_hash' => env('GIT_COMMIT', env('BADDYBUGS_GIT_COMMIT')),

    /*
     * Deployment tag
     * Custom tag for this deployment (e.g., v1.2.3, build-456)
     */
    'git_deployment_tag' => env('DEPLOYMENT_TAG', env('BADDYBUGS_DEPLOYMENT_TAG')),

    /*
     * Deployed at timestamp
     * When this version was deployed
     */
    'git_deployed_at' => env('DEPLOYED_AT', env('BADDYBUGS_DEPLOYED_AT')),

    /*
     * Deployed by
     * Who deployed this version
     */
    'git_deployed_by' => env('DEPLOYED_BY', env('BADDYBUGS_DEPLOYED_BY')),

    /*
    |--------------------------------------------------------------------------
    | Additional Collectors
    |--------------------------------------------------------------------------
    */

    /*
     * Track notifications
     * Monitors notification delivery (email, SMS, Slack, etc.)
     */
    'track_notifications' => env('BADDYBUGS_TRACK_NOTIFICATIONS', true),

    /*
     * Track view rendering time
     * Monitors slow views
     */
    'track_view_rendering' => env('BADDYBUGS_TRACK_VIEW_RENDERING', true),

    /*
     * Slow view threshold (milliseconds)
     */
    'slow_view_threshold' => env('BADDYBUGS_SLOW_VIEW_THRESHOLD', 200),

    /*
     * Track individual middleware timing
     * Shows which middleware is slow
     */
    'track_middleware_timing' => env('BADDYBUGS_TRACK_MIDDLEWARE_TIMING', true),

    /*
     * Enrich user context
     * Captures roles, permissions, tenant_id
     */
    'enrich_user_context' => env('BADDYBUGS_ENRICH_USER_CONTEXT', true),

    /*
     * User context fields to capture
     * Additional user model attributes to include
     */
    'user_context_fields' => ['id', 'email', 'name', 'roles', 'permissions', 'tenant_id'],

    /*
    |--------------------------------------------------------------------------
    | Regression Risk Analysis
    |--------------------------------------------------------------------------
    |
    | Automatically detect performance degradation and error rate increases
    | after deployments. The agent enriches all events with deployment context
    | and can track baseline metrics for comparison.
    |
    | Use Case: "Alert me if my latest deploy increased errors by 20%"
    |
    */

    /*
     * Enable regression analysis
     * Adds deployment context to all events for pre/post deploy comparison
     */
    'regression_analysis_enabled' => env('BADDYBUGS_REGRESSION_ANALYSIS', true),

    /*
     * Deployment hash source
     * 
     * Options:
     * - 'env': Read from APP_DEPLOYMENT_HASH environment variable (recommended for CI/CD)
     * - 'header': Read from X-Deployment-ID request header (for canary/blue-green)
     * - 'git': Auto-detect from .git/HEAD (local/staging only, slower)
     * - 'auto': Try env, then header, then git (in that order)
     * 
     * Example CI/CD:
     *   APP_DEPLOYMENT_HASH="${GITHUB_SHA}" or "${CI_COMMIT_SHA}"
     */
    'deployment_hash_source' => env('BADDYBUGS_DEPLOYMENT_HASH_SOURCE', 'env'),

    /*
     * Deployment hash from environment
     * Set this during deploy via your CI/CD pipeline
     * 
     * Examples:
     *   APP_DEPLOYMENT_HASH=abc123def456
     *   APP_DEPLOYMENT_HASH=$GITHUB_SHA
     */
    'deployment_hash' => env('APP_DEPLOYMENT_HASH'),

    /*
     * Deployment tag (semantic version)
     * Optional: Set deployment version/tag for easier identification
     * 
     * Examples:
     *   APP_DEPLOYMENT_TAG=v2.1.0
     *   APP_DEPLOYMENT_TAG=release-2024-01
     */
    'deployment_tag' => env('APP_DEPLOYMENT_TAG'),

    /*
     * Deployment metadata
     * Additional deployment context (released_by, notes, etc.)
     */
    'deployment_released_by' => env('APP_DEPLOYMENT_RELEASED_BY'),
    'deployment_notes' => env('APP_DEPLOYMENT_NOTES'),

    /*
     * Auto-detect deployment changes
     * When enabled, automatically sends "deployment_started" event when 
     * deployment_hash changes between requests (requires caching)
     */
    'auto_detect_deployment' => env('BADDYBUGS_AUTO_DETECT_DEPLOYMENT', true),

    /*
     * Baseline comparison window (days)
     * How many days of historical data to use for regression comparison
     * 
     * - 1: Compare to yesterday (fast, good for high-traffic apps)
     * - 7: Compare to last week (default, balanced)
     * - 30: Compare to last month (comprehensive, slower queries)
     */
    'regression_baseline_days' => env('BADDYBUGS_REGRESSION_BASELINE_DAYS', 7),

    /*
     * Baseline metrics to capture
     * Which metrics to track for regression detection
     * 
     * Available: latency, error_rate, query_count, memory_usage, cpu_time
     */
    'regression_baseline_metrics' => [
        'latency',       // Average response time
        'error_rate',    // Percentage of 5xx errors
        'query_count',   // Average DB queries per request
        'memory_usage',  // Peak memory usage
    ],

    /*
     * Capture baseline snapshots
     * Creates periodic snapshots of metrics for comparison
     * Recommended: true in production, false in development
     */
    'regression_capture_baselines' => env('BADDYBUGS_REGRESSION_CAPTURE_BASELINES', true),

    /*
     * Baseline snapshot interval (requests)
     * Take a baseline snapshot every N requests
     * Higher = less overhead, lower = more granular detection
     */
    'regression_baseline_snapshot_interval' => env('BADDYBUGS_REGRESSION_BASELINE_INTERVAL', 100),

    /*
     * Deployment context header name
     * Custom header name for receiving deployment ID from load balancer/proxy
     * Useful for canary deployments or blue-green strategies
     */
    'deployment_header_name' => env('BADDYBUGS_DEPLOYMENT_HEADER', 'X-Deployment-ID'),

    /*
     * Mark events as pre/post deploy
     * Automatically tags events based on deployment timeline
     * Useful for A/B comparison in dashboard
     */
    'tag_deployment_phase' => env('BADDYBUGS_TAG_DEPLOYMENT_PHASE', true),

    /*
     * Deployment warm-up period (minutes)
     * Ignore first N minutes after deploy for regression calculation
     * Allows caches to warm up and prevents false positives
     */
    'regression_warmup_period' => env('BADDYBUGS_REGRESSION_WARMUP_MINUTES', 5),

    /*
     * Alert thresholds (for future alerting integration)
     * Define when to trigger regression alerts
     * 
     * Note: Alerting logic is on dashboard/backend, these are just hints
     */
    'regression_alert_latency_increase' => 50,    // Alert if latency increases >50%
    'regression_alert_error_rate_increase' => 20,  // Alert if errors increase >20%
    'regression_alert_memory_increase' => 30,      // Alert if memory increases >30%

    'llm_rates' => [
        'openai' => [
            'gpt-4' => ['in' => 0.03 / 1000, 'out' => 0.06 / 1000],
            'gpt-4-turbo' => ['in' => 0.01 / 1000, 'out' => 0.03 / 1000],
            'gpt-4o' => ['in' => 0.005 / 1000, 'out' => 0.015 / 1000],
            'gpt-4o-mini' => ['in' => 0.0003 / 1000, 'out' => 0.0010 / 1000],
            'gpt-4.1-mini' => ['in' => 0.0005 / 1000, 'out' => 0.0020 / 1000],
            'gpt-4.1' => ['in' => 0.005 / 1000, 'out' => 0.015 / 1000],
            'gpt-3.5-turbo' => ['in' => 0.0005 / 1000, 'out' => 0.0015 / 1000],
        ],
        'anthropic' => [
            'claude-4.5-opus' => ['in' => 0.005 / 1000, 'out' => 0.025 / 1000],
            'claude-4.5-sonnet' => ['in' => 0.003 / 1000, 'out' => 0.015 / 1000],
            'claude-4.5-haiku' => ['in' => 0.001 / 1000, 'out' => 0.005 / 1000],
            'claude-4.1-opus' => ['in' => 0.015 / 1000, 'out' => 0.075 / 1000],
            'claude-3-sonnet' => ['in' => 0.003 / 1000, 'out' => 0.015 / 1000],
            'claude-3-haiku' => ['in' => 0.00025 / 1000, 'out' => 0.00125 / 1000],
        ],
        'google' => [
            'gemini-3-pro' => ['in' => 0.002 / 1000, 'out' => 0.012 / 1000],
            'gemini-3-flash' => ['in' => 0.0005 / 1000, 'out' => 0.0030 / 1000],
            'gemini-2.5-pro' => ['in' => 0.00125 / 1000, 'out' => 0.01 / 1000],
            'gemini-2.5-flash' => ['in' => 0.0003 / 1000, 'out' => 0.0025 / 1000],
            'gemini-2.0-flash' => ['in' => 0.000175 / 1000, 'out' => 0.00075 / 1000],
            'gemini-1.5-pro' => ['in' => 0.001 / 1000, 'out' => 0.006 / 1000],
            'gemini-1.5-flash' => ['in' => 0.0001 / 1000, 'out' => 0.0005 / 1000],
            'gemma-2-9b-it' => ['in' => 0.0, 'out' => 0.0],
            'gemma-2-27b-it' => ['in' => 0.0, 'out' => 0.0],
        ],
        'meta' => [
            'llama-3.1-8b-instruct' => ['in' => 0.0, 'out' => 0.0],
            'llama-3.1-70b-instruct' => ['in' => 0.0, 'out' => 0.0],
            'llama-3.2-3b-instruct' => ['in' => 0.0, 'out' => 0.0],
            'llama-3.2-11b-vision-instruct' => ['in' => 0.0, 'out' => 0.0],
            'llama-2-13b-chat' => ['in' => 0.0, 'out' => 0.0],
        ],
        'mistral' => [
            'mistral-large' => ['in' => 0.002 / 1000, 'out' => 0.006 / 1000],
            'mistral-medium' => ['in' => 0.0004 / 1000, 'out' => 0.002 / 1000],
            'mistral-small' => ['in' => 0.0, 'out' => 0.0],
            'mistral-nemo' => ['in' => 0.0, 'out' => 0.0],
        ],
        'cohere' => [
            'command' => ['in' => 0.0, 'out' => 0.0],
            'command-r' => ['in' => 0.00015 / 1000, 'out' => 0.0006 / 1000],
            'command-r-plus' => ['in' => 0.0025 / 1000, 'out' => 0.01 / 1000],
            'command-a' => ['in' => 0.0025 / 1000, 'out' => 0.01 / 1000],
            'command-light' => ['in' => 0.0, 'out' => 0.0],
        ],
        'perplexity' => [
            'pplx-7b-instruct' => ['in' => 0.0, 'out' => 0.0],
            'pplx-70b-instruct' => ['in' => 0.0, 'out' => 0.0],
            'pplx-llama-3-70b-instruct' => ['in' => 0.0, 'out' => 0.0],
            'pplx-gemma-2-9b-instruct' => ['in' => 0.0, 'out' => 0.0],
            'sonar-pro' => ['in' => 0.003 / 1000, 'out' => 0.015 / 1000],
            'sonar-deep-research' => ['in' => 0.003 / 1000, 'out' => 0.015 / 1000],
        ],
        'databricks' => [
            'dbrx-instruct' => ['in' => 0.0, 'out' => 0.0],
            'dbrx' => ['in' => 0.0, 'out' => 0.0],
        ],
        'ai21' => [
            'jamba-1.5-large' => ['in' => 0.002 / 1000, 'out' => 0.008 / 1000],
            'jamba-1.5-mini' => ['in' => 0.0002 / 1000, 'out' => 0.0004 / 1000],
        ],
        'alibaba' => [
            'qwen2.5-72b-instruct' => ['in' => 0.0016 / 1000, 'out' => 0.0064 / 1000],
            'qwen2.5-7b-instruct' => ['in' => 0.0, 'out' => 0.0],
            'qwen2-72b-instruct' => ['in' => 0.0, 'out' => 0.0],
        ],
        'microsoft' => [
            'phi-3-medium' => ['in' => 0.0, 'out' => 0.0],
            'phi-3-mini' => ['in' => 0.0, 'out' => 0.0],
            'phi-3-vision' => ['in' => 0.0, 'out' => 0.0],
            'phi-4-mini' => ['in' => 0.000075 / 1000, 'out' => 0.0003 / 1000],
        ],
        'moonshot' => [
            'moonshot-v1-8k' => ['in' => 0.00015 / 1000, 'out' => 0.0025 / 1000],
            'moonshot-v1-32k' => ['in' => 0.00015 / 1000, 'out' => 0.0025 / 1000],
            'moonshot-v1-128k' => ['in' => 0.00015 / 1000, 'out' => 0.0025 / 1000],
            'kimi' => ['in' => 0.00015 / 1000, 'out' => 0.0025 / 1000],
            'kimi-k2' => ['in' => 0.00015 / 1000, 'out' => 0.0025 / 1000],
        ],
        'xai' => [
            'grok-4.1-fast' => ['in' => 0.0002 / 1000, 'out' => 0.0005 / 1000],
            'grok-4' => ['in' => 0.003 / 1000, 'out' => 0.015 / 1000],
            'grok-2' => ['in' => 0.003 / 1000, 'out' => 0.015 / 1000],
            'grok-2-mini' => ['in' => 0.0, 'out' => 0.0],
        ],
        'deepseek' => [
            'deepseek-chat' => ['in' => 0.00028 / 1000, 'out' => 0.00042 / 1000],
            'deepseek-coder' => ['in' => 0.00028 / 1000, 'out' => 0.00042 / 1000],
            'deepseek-reasoner' => ['in' => 0.001 / 1000, 'out' => 0.002 / 1000],
            'deepseek-v3' => ['in' => 0.00028 / 1000, 'out' => 0.00042 / 1000],
            'deepseek-v3.2-exp' => ['in' => 0.00028 / 1000, 'out' => 0.00042 / 1000],
        ],
        'global' => [
            'gpt-4' => ['in' => 0.03 / 1000, 'out' => 0.06 / 1000],
            'gpt-4-turbo' => ['in' => 0.01 / 1000, 'out' => 0.03 / 1000],
            'gpt-4o' => ['in' => 0.005 / 1000, 'out' => 0.015 / 1000],
            'gpt-4o-mini' => ['in' => 0.0003 / 1000, 'out' => 0.0010 / 1000],
            'gpt-3.5-turbo' => ['in' => 0.0005 / 1000, 'out' => 0.0015 / 1000],
            'claude-4.5-opus' => ['in' => 0.005 / 1000, 'out' => 0.025 / 1000],
            'claude-4.5-sonnet' => ['in' => 0.003 / 1000, 'out' => 0.015 / 1000],
            'claude-4.5-haiku' => ['in' => 0.001 / 1000, 'out' => 0.005 / 1000],
            'claude-3-opus' => ['in' => 0.015 / 1000, 'out' => 0.075 / 1000],
            'gemini-3-flash' => ['in' => 0.0005 / 1000, 'out' => 0.0030 / 1000],
            'gemini-2.5' => ['in' => 0.00125 / 1000, 'out' => 0.01 / 1000],
            'gemma-2' => ['in' => 0.0, 'out' => 0.0],
            'llama-3' => ['in' => 0.0, 'out' => 0.0],
            'llama-3.1' => ['in' => 0.0, 'out' => 0.0],
            'llama-3.2' => ['in' => 0.0, 'out' => 0.0],
            'mistral-large' => ['in' => 0.002 / 1000, 'out' => 0.006 / 1000],
            'command' => ['in' => 0.0, 'out' => 0.0],
            'jamba' => ['in' => 0.0, 'out' => 0.0],
            'qwen' => ['in' => 0.0, 'out' => 0.0],
            'phi-3' => ['in' => 0.0, 'out' => 0.0],
            'phi-4-mini' => ['in' => 0.000075 / 1000, 'out' => 0.0003 / 1000],
            'moonshot' => ['in' => 0.00015 / 1000, 'out' => 0.0025 / 1000],
            'kimi' => ['in' => 0.00015 / 1000, 'out' => 0.0025 / 1000],
            'grok' => ['in' => 0.003 / 1000, 'out' => 0.015 / 1000],
            'deepseek' => ['in' => 0.00028 / 1000, 'out' => 0.00042 / 1000],
            'deepseek-chat' => ['in' => 0.00028 / 1000, 'out' => 0.00042 / 1000],
            'deepseek-coder' => ['in' => 0.00028 / 1000, 'out' => 0.00042 / 1000],
            'deepseek-reasoner' => ['in' => 0.001 / 1000, 'out' => 0.002 / 1000],
            'deepseek-v3' => ['in' => 0.00028 / 1000, 'out' => 0.00042 / 1000],
            'deepseek-v3.2-exp' => ['in' => 0.00028 / 1000, 'out' => 0.00042 / 1000],
            'pplx' => ['in' => 0.0, 'out' => 0.0],
            'dbrx' => ['in' => 0.0, 'out' => 0.0],
            'sonar-pro' => ['in' => 0.003 / 1000, 'out' => 0.015 / 1000],
            'sonar-deep-research' => ['in' => 0.003 / 1000, 'out' => 0.015 / 1000],
        ],
    ],
];


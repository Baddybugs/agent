# BaddyBugs Agent

The intelligent observability agent for Laravel applications. BaddyBugs Agent collects logs, exceptions, queries, jobs, and session replays to provide deep insights into your application's health and performance.

## Requirements

- PHP ^8.2
- Laravel 10.x, 11.x, or 12.x
- `guzzlehttp/guzzle` ^7.8

## Installation

Install the package via composer:

```bash
composer require baddybugs/agent
```

## Configuration

### 1. Publish Configuration

Publish the configuration file to customize the agent's behavior:

```bash
php artisan vendor:publish --tag=baddybugs-config
```

### 2. Environment Setup

Add the following variables to your `.env` file. access your BaddyBugs dashboard to get your API Key.

```env
BADDYBUGS_ENABLED=true
BADDYBUGS_API_KEY=your_api_key_here
BADDYBUGS_ENDPOINT=https://api.baddybugs.com/v1/ingest
BADDYBUGS_APP_NAME="My Laravel App"
BADDYBUGS_ENV=production
```

### 3. Key Configuration Options

The `config/baddybugs.php` file allows you to fine-tune every aspect of the agent.

- **Collectors**: Enable/disable specific collectors (Requests, Queries, Jobs, etc.).
- **Sampling**: Adjust sampling rates for high-volume events (default 1.0 = 100%).
- **Privacy**: Configure redaction for sensitive keys (passwords, tokens) and session replay masking.
- **Session Replay**: Enable/disable session recording and set privacy modes ('strict', 'moderate').
- **Feature Tracking**: Enable/disable product analytics.

## Usage

Once installed and configured, BaddyBugs automatically monitors your application. However, you can enhance the data with manual instrumentation.

### Context

Add custom context to all subsequent events in the current request. This is useful for tagging events with user IDs, tenant IDs, or feature flags.

```php
use BaddyBugs\Agent\Facades\BaddyBugs;

// Add global context
BaddyBugs::context([
    'tenant_id' => 123,
    'plan' => 'enterprise',
    'feature_flag_x' => true,
]);
```

### User Tracking

Identify the authenticated user for better session correlation. By default, BaddyBugs attempts to resolve the standard Laravel user. You can customize this:

```php
BaddyBugs::user(function ($user) {
    return [
        'id' => $user->custom_id,
        'email' => $user->email,
        'role' => $user->role,
    ];
});
```

### Manual Recording

Record custom events or exceptions manually.

```php
// Record a custom log/event
BaddyBugs::record('payment', 'processed', [
    'amount' => 99.00,
    'currency' => 'USD'
]);

// Track a custom metric
BaddyBugs::reportHealth('scheduler', [
    'last_run' => now()->toIso8601String(), 
    'status' => 'healthy'
]);
```

## Filtering

Control exactly what gets sent to BaddyBugs using granular filters.

### Global Filter
Run a check for every single event type. Return `false` to discard the event.

```php
BaddyBugs::filter(function ($type, $name, $payload) {
    // Ignore all events from the 'health-check' endpoint
    if ($payload['url'] ?? '' === '/health-check') {
        return false;
    }
    return true;
});
```

### Type-Specific Filters

**Exceptions:**
Filter exceptions based on the actual exception instance.

```php
BaddyBugs::filterExceptions(function (\Throwable $e) {
    // Ignore generic 404s
    if ($e instanceof \Symfony\Component\HttpKernel\Exception\NotFoundHttpException) {
        return false;
    }
    return true;
});
```

**Queries:**
Filter database queries based on SQL, bindings, execution time, and connection.

```php
BaddyBugs::filterQueries(function ($sql, $bindings, $time, $connection) {
    // Only record queries that take longer than 1 second
    return $time > 1000;
});
```

**Jobs:**
Filter queued jobs based on the job event.

```php
BaddyBugs::filterJobs(function ($event) {
    // Ignore a high-frequency internal job
    return $event->job->resolveName() !== 'App\Jobs\Ping';
});
```

**Mail:**
Filter outgoing emails.

```php
BaddyBugs::filterMail(function ($event) {
    // Don't log password reset emails
    return !str_contains($event->message->getSubject(), 'Reset Password');
});
```

## Advanced Features

### Session Replay
Record user sessions to replay exactly what users saw and did.

1. Enable in `.env`:
   ```env
   BADDYBUGS_SESSION_REPLAY_ENABLED=true
   ```
2. Configure Privacy in `config/baddybugs.php`:
   - `strict` (Default): Masks all text, blocks passwords & sensitive fields.
   - `moderate`: Masks only inputs.

### Regression Analysis
BaddyBugs can correlate errors with specific deployments and deployments with git commits.

Ensure the following env vars are present during deployment:

```env
BADDYBUGS_RELEASE=v1.2.0
BADDYBUGS_GIT_SHA=a1b2c3d
```

### Security Scanning
The agent passively scans for security issues like:
- SQL Injection attempts in request parameters.
- Exposed sensitive data (Credit Cards, API Keys) in logs/payloads.
- Vulnerable Composer dependencies (requires `BADDYBUGS_SECURITY_GITHUB_TOKEN`).

### Performance Profiling
Detailed breakdown of request lifecycles (Boot, Middleware, Controller, View).
- Enable `BADDYBUGS_PROFILING_ENABLED=true` to see flamegraphs and phase breakdowns key transactions.

## Frontend & Livewire Monitoring

BaddyBugs provides **zero-configuration** monitoring for standard Laravel, Livewire, and FilamentPHP frontends.

### 1. Enable Frontend Monitoring
In your `.env`:
```env
BADDYBUGS_FRONTEND_ENABLED=true
```

### 2. Add Blade Directive
Add the `@baddybugs` directive to your main layout (e.g., `resources/views/layouts/app.blade.php`), just before the closing `</body>` tag:

```blade
    <!-- ... other scripts ... -->
    @baddybugs
</body>
</html>
```

### 3. FilamentPHP Integration
For Filament panels, publish the Filament config or register the plugin. **However, BaddyBugs automatically injects itself** into Filament's `renderHook` if the collector is enabled, so usually **no extra steps are needed**.

If you need manual control in Filament:
```php
public function panel(Panel $panel): Panel
{
    return $panel
        ->renderHook('panels::body.end', fn () => Blade::render('@baddybugs'));
}
```

### 4. What is collected?
- **Web Vitals**: LCP, CLS, INP monitoring automatically initiated.
- **Trace ID Correlation**: Frontend headers are tagged with the backend Trace ID for end-to-end tracing.
- **Livewire**: Deep integration automatically captures:
  - `component.hydrate` / `dehydrate` statistics.
  - Failed Livewire updates.
  - Slow Livewire component renders.
- **Errors**: Uncaught Javascript errors (via window.onerror).

> **Note for SPA Users**: If you are using **Inertia.js, Vue, or React** separately from Blade/Livewire, please install our dedicated JS SDK: `npm install @baddybugs/js-sdk`.

## Additional Monitoring Capabilities

### LLM Observability
Track costs, usage, and performance of LLM requests (OpenAI, Anthropic, Mistral, etc.).

```php
use BaddyBugs\Agent\Facades\BaddyBugs;

BaddyBugs::trackLLM(
    provider: 'openai',
    model: 'gpt-4',
    prompt: $prompt,
    response: $response,
    usage: ['prompt_tokens' => 50, 'completion_tokens' => 100],
    durationMs: 1200,
    costUsd: 0.006 // Optional: Auto-calculated for known models
);
```

### Eloquent Model Monitoring
BaddyBugs automatically adds Breadcrumbs for all model creation, updates, and deletions.
- To record full model events (with changes), enable `models_detailed => true` in config.

### Outgoing HTTP Requests
The agent automatically captures all outgoing HTTP requests made via Laravel's `Http` client.

**Automatic Coverage:**
- **Laravel `Http::*`**: Fully automatic.
- **Failures**: 4xx/5xx errors are force-sampled and recorded.
- **Slowness**: Requests slower than `http_client_slow_threshold_ms` are recorded.
- **Trace Propagation**: Adds `X-Baddybugs-Trace-Id` to outgoing requests for distributed tracing.

**Manual Integration for Other Clients:**

For direct **Guzzle** usage (without Laravel's `Http` facade):
```php
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use BaddyBugs\Agent\Collectors\HttpClientCollector;

$stack = HandlerStack::create();
$stack->push(HttpClientCollector::guzzleMiddleware());
$client = new Client(['handler' => $stack]);
```

For **Symfony HttpClient**, **Buzz**, **HTTPlug**, or any **PSR-18** client:
```php
use BaddyBugs\Agent\Http\TracedHttpClient;
use Symfony\Component\HttpClient\Psr18Client;

$symfonyClient = new Psr18Client();
$tracedClient = new TracedHttpClient($symfonyClient);

// All requests are now monitored
$response = $tracedClient->sendRequest($request);
```

For **cURL** or `file_get_contents` (Manual Recording):
```php
use BaddyBugs\Agent\Collectors\HttpClientCollector;

$start = microtime(true);
$ch = curl_init($url);
// ... curl options ...
$result = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

HttpClientCollector::recordManual('GET', $url, $status, microtime(true) - $start);
```


### Scheduled Tasks & Commands
- **Schedule**: Automatically monitors scheduled task executions, failures, and runtimes.
- **Commands**: Captures artisan command execution, exit codes, and durations.

### Authorization & Security
The agent automatically monitors your application's `Gate` and Policy checks.
- **Gates**: Logs every `Gate::allows()` or `Gate::denies()` check, including the target model and arguments.
- **Policies**: Tracks policy authorizations to help you audit who accessed what resource.

### Redis & Cache Deep Dive
Beyond standard cache hits/misses, the agent now monitors raw **Redis** commands.
- **RedisCollector**: Captures `HGETALL`, `LPUSH`, and detailed command execution times to find bottlenecks in your pub/sub or custom Redis usage.

### OpenTelemetry Interoperability
BaddyBugs is designed to work with the modern observability ecosystem.
- **W3C Trace Context**: The agent automatically parses incoming `traceparent` headers to correlate distributed traces.
- **Propagation**: Outgoing HTTP requests via `Http` facade or `TracedHttpClient` automatically inject standard W3C `traceparent` headers.

### CI/CD Test Suite Integration
Turn BaddyBugs into a CI monitoring tool by capturing test results and failures.

1. Add `BADDYBUGS_TEST_MONITORING=true` to your `.env.testing`.
2. Add the trait to your `tests/TestCase.php`:

```php
use BaddyBugs\Agent\Traits\MonitorsTests;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication, MonitorsTests;
}
```

Now every test run (Pass/Fail) is sent to the dashboard, correlated with any logs or SQL queries triggered during that test.



**Logs not appearing?**
1. Check `BADDYBUGS_ENABLED=true` in `.env`.
2. Check `storage/logs/laravel.log` for any "BaddyBugs connection error".
3. Verify your `BADDYBUGS_API_KEY`.

**Performance issues?**
- Enable "Performance Mode" in production for high-traffic apps: `BADDYBUGS_PERFORMANCE_MODE=true`.
- Adjust sampling rates in `config/baddybugs.php`.

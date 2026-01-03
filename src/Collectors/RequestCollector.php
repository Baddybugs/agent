<?php

namespace BaddyBugs\Agent\Collectors;

use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use BaddyBugs\Agent\Facades\BaddyBugs;
use BaddyBugs\Agent\Support\MemoryProfiler;

class RequestCollector implements CollectorInterface
{
    protected ?MemoryProfiler $memoryProfiler = null;

    public function boot(): void
    {
        // Start memory profiling at boot
        if (config('baddybugs.memory_profiling_enabled', true)) {
            $this->memoryProfiler = new MemoryProfiler();
        }

        Event::listen(RequestHandled::class, function (RequestHandled $event) {
            $this->collect($event->request, $event->response);
        });
    }

    protected function collect($request, $response): void
    {
        try {
            if ($this->shouldIgnore($request)) {
                return;
            }

            // Extract frontend session ID
            if ($sessionId = $request->header('X-Baddybugs-Session-Id')) {
                BaddyBugs::setSessionId($sessionId);
            }

            $startTime = defined('LARAVEL_START') ? LARAVEL_START : $request->server('REQUEST_TIME_FLOAT', microtime(true));
            $duration = (microtime(true) - $startTime) * 1000;

            $payload = [
                'method' => $request->method(),
                'uri' => BaddyBugs::performUrlRedaction($request->path()),
                'url' => BaddyBugs::performUrlRedaction($request->url()),
                'status' => $response->getStatusCode(),
                'duration_ms' => $duration,
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'inputs' => $request->input(),
                'headers' => $this->filterHeaders($request->headers->all()),
                'response_status' => $response->getStatusCode(),
                'route' => $request->route() ? $request->route()->getName() : null,
                'controller' => $request->route() ? $request->route()->getActionName() : null,
                'user' => null,
            ];

            // UC #2: Add memory profiling data
            if ($this->memoryProfiler) {
                $memoryData = $this->memoryProfiler->analyze();
                $payload['memory_peak'] = $memoryData['peak_memory'];
                $payload['memory_used'] = $memoryData['total_used'];
                $payload['is_memory_heavy'] = $memoryData['is_heavy'];
                $payload['memory_suggestions'] = $memoryData['suggestions'];
            }

            // Safely attempt to get user info
            try {
                if ($user = $request->user()) {
                    $payload['user'] = BaddyBugs::resolveUser($user);
                }
            } catch (\Throwable $e) {
                // Ignore user extraction errors
            }

            // Add response size
            $payload['response_size'] = strlen($response->getContent());
            $payload['content_length'] = $response->headers->get('Content-Length', strlen($response->getContent()));
            
            // Add server info
            $payload['server'] = $request->server('SERVER_SOFTWARE');

            BaddyBugs::record('request', $request->method() . ' ' . $request->path(), $payload);
        } catch (\Throwable $e) {
            // Fail silently to prevent agent from crashing the app
        }
    }

    protected function shouldIgnore($request): bool
    {
        $patterns = config('baddybugs.ignore_paths', []);
        foreach ($patterns as $pattern) {
            if ($request->is($pattern)) {
                return true;
            }
        }
        return false;
    }

    protected function filterHeaders(array $headers): array
    {
        $ignore = config('baddybugs.redact_headers', []);
        
        return array_filter($headers, function ($key) use ($ignore) {
             return !in_array(strtolower($key), $ignore);
        }, ARRAY_FILTER_USE_KEY);
    }
}


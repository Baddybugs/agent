<?php

namespace BaddyBugs\Agent\Collectors;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Event;
use BaddyBugs\Agent\Facades\BaddyBugs;
use Illuminate\Support\Str;

class QueryCollector implements CollectorInterface
{
    protected array $queryCounts = [];

    public function boot(): void
    {
        Event::listen(QueryExecuted::class, function (QueryExecuted $event) {
            $this->collect($event);
        });
    }

    // Recursion guard for when agent uses DB (e.g. via Cache database driver)
    protected static bool $isCollecting = false;

    protected function collect(QueryExecuted $query): void
    {
        if (self::$isCollecting) {
            return;
        }

        if (BaddyBugs::shouldFilterQuery($query->sql, $query->bindings, $query->time, $query->connectionName)) {
            return;
        }

        self::$isCollecting = true;

        try {
            // N+1 detection: Simple hash comparison - O(1) operation
            if (config('baddybugs.detect_n_plus_one')) {
                $this->checkNPlusOne($query);
            }

            $isSlow = $query->time > config('baddybugs.slow_query_threshold', 100);

            // Breadcrumb: Simple array push - < 0.001ms
            \BaddyBugs\Agent\Breadcrumbs::query(
                Str::limit($query->sql, 100),
                $query->time
            );

            // Build payload - all in-memory operations
            $payload = [
                'sql' => $query->sql,
                'bindings' => $query->bindings,
                'time' => $query->time,
                'connection' => $query->connectionName,
                'slow' => $isSlow,
                // debug_backtrace with limit is fast (~0.05ms)
                'file' => $this->getCaller(),
            ];

            // record() just pushes to in-memory array - NO HTTP call here
            // HTTP calls happen in terminable middleware AFTER user gets response
            BaddyBugs::record('query', 'sql', $payload);
        } catch (\Throwable $e) {
            // Fail silently
        } finally {
            self::$isCollecting = false;
        }
    }

    protected function checkNPlusOne(QueryExecuted $query): void
    {
        $hash = md5($query->sql); // Simple hash of SQL without bindings
        if (!isset($this->queryCounts[$hash])) {
            $this->queryCounts[$hash] = 0;
        }
        $this->queryCounts[$hash]++;

        // If we hit exactly 5, 10, 20... trigger an N+1 warning event
        // This avoids spamming every single duplicate, but warns on significant counts.
        $count = $this->queryCounts[$hash];
        if ($count > 1 && in_array($count, [5, 10, 20, 50])) {
            BaddyBugs::record('issue', 'n_plus_one', [
                'sql' => $query->sql,
                'count' => $count,
                'location' => $this->getCaller(),
                'snippet' => $this->getSnippet($this->getCaller()),
                'suggestion' => 'Check if you can use Eager Loading (with()) for this relationship.'
            ]);
        }
    }

    protected function getSnippet(?string $location): ?array
    {
        if (!$location) return null;
        
        try {
            [$file, $line] = explode(':', $location);
            if (!file_exists($file)) return null;

            $lines = file($file);
            $start = max(0, $line - 5);
            $end = min(count($lines), $line + 5);
            $snippet = [];

            for ($i = $start; $i < $end; $i++) {
                $snippet[$i + 1] = rtrim($lines[$i]);
            }
            return $snippet;
        } catch (\Throwable $e) {
            return null;
        }
    }

    protected function getCaller(): ?string
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 10);
        foreach ($trace as $frame) {
            if (isset($frame['file']) && 
                !Str::contains($frame['file'], 'vendor/laravel') && 
                !Str::contains($frame['file'], 'baddybugs/agent')) {
                return $frame['file'] . ':' . ($frame['line'] ?? 0);
            }
        }
        return null;
    }
}


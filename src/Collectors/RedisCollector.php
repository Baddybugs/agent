<?php

namespace BaddyBugs\Agent\Collectors;

use Illuminate\Redis\Events\CommandExecuted;
use Illuminate\Support\Facades\Event;
use BaddyBugs\Agent\Facades\BaddyBugs;

/**
 * Redis Collector
 *
 * Monitors direct Redis commands execution.
 * Useful for debugging performance issues in caching, queues, or raw Redis usage.
 */
class RedisCollector implements CollectorInterface
{
    public function boot(): void
    {
        Event::listen(CommandExecuted::class, [$this, 'handleCommandExecuted']);
    }

    public function handleCommandExecuted(CommandExecuted $event): void
    {
        // Ignore internal commands or high-frequency/low-value commands if needed
        if ($this->shouldIgnore($event->command)) {
            return;
        }

        $durationMs = $event->time; // Laravel provides time in ms
        $connectionName = $event->connectionName;
        
        // Format parameters for display (truncate large values)
        $parameters = array_map(function ($param) {
            if (is_string($param) && strlen($param) > 100) {
                return substr($param, 0, 100) . '...';
            }
            return $param;
        }, $event->parameters);

        $commandStr = strtoupper($event->command) . ' ' . implode(' ', $parameters);

        BaddyBugs::record('redis', $commandStr, [
            'command' => $event->command,
            'parameters' => $parameters,
            'connection' => $connectionName,
            'duration_ms' => $durationMs,
        ]);
    }

    protected function shouldIgnore(string $command): bool
    {
        // Example: ignore PING or basic auth commands if sensitive
        $ignoredCommands = ['PING', 'SELECT', 'AUTH'];
        return in_array(strtoupper($command), $ignoredCommands);
    }
}

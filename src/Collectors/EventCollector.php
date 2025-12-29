<?php

namespace BaddyBugs\Agent\Collectors;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use BaddyBugs\Agent\Facades\BaddyBugs;

class EventCollector implements CollectorInterface
{
    public function boot(): void
    {
        Event::listen('*', function (string $eventName, array $data) {
            $this->collect($eventName, $data);
        });
    }

    // Recursion guard
    protected static bool $isCollecting = false;

    protected function collect(string $eventName, array $data): void
    {
        if (self::$isCollecting) {
            return;
        }

        self::$isCollecting = true;

        try {
            if ($this->shouldIgnore($eventName)) {
                return;
            }
            
            // Ignore BaddyBugs internal cache events specifically if wildcard didn't catch them
            // The stacktrace showed `Illuminate\Cache\Events\RetrievingKey` with key `baddybugs:rate_limited`
            // But we can just ignore all Cache events to be safe and performant.

            // Format payload roughly
            $payload = [];
            foreach ($data as $index => $item) {
                if ($item instanceof \Illuminate\Cache\Events\CacheEvent && str_starts_with($item->key, 'baddybugs:')) {
                    // Double check: if it's our own cache key, strict ignore
                    return;
                }

                if (is_object($item)) {
                    $payload["arg_{$index}"] = get_class($item);
                } elseif (is_array($item)) {
                     // simplified
                } else {
                    $payload["arg_{$index}"] = (string) $item;
                }
            }

            BaddyBugs::record('event', $eventName, $payload);
        } catch (\Throwable $e) {
            // Fail silently
        } finally {
            self::$isCollecting = false;
        }
    }

    protected function shouldIgnore(string $eventName): bool
    {
        // Ignore internal Laravel events that are high frequency or covered by other collectors
        $ignored = [
            'bootstrapped:*',
            'bootstrapping:*',
            'illuminate.log',
            'illuminate.query',
            'illuminate.queue.*',
            'illuminate.console.*',
            'Illuminate\Cache\*', // CRITICAL FIX: Ignore cache events to prevent loop
            'eloquent.*',
            'kernel.handled',
            'reflection.*',
            'composing:*',
            'creating:*',
            'BaddyBugs\*', // Ignore our own events
        ];

        if (Str::is($ignored, $eventName)) {
            return true;
        }

        return false;
    }
}


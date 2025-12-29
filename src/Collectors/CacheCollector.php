<?php

namespace BaddyBugs\Agent\Collectors;

use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\KeyForgotten;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Support\Facades\Event;
use BaddyBugs\Agent\Facades\BaddyBugs;

class CacheCollector implements CollectorInterface
{
    public function boot(): void
    {
        Event::listen(CacheHit::class, fn ($event) => $this->record('hit', $event->key, $event->tags ?? []));
        Event::listen(CacheMissed::class, fn ($event) => $this->record('miss', $event->key, $event->tags ?? []));
        Event::listen(KeyWritten::class, fn ($event) => $this->record('write', $event->key, $event->tags ?? [], $event->value, $event->seconds));
        Event::listen(KeyForgotten::class, fn ($event) => $this->record('forget', $event->key, $event->tags ?? []));
    }

    protected function record(string $type, string $key, array $tags, $value = null, $seconds = null): void
    {
        // Don't record internal telescope/baddybugs keys
        if (str_contains($key, 'baddybugs') || str_contains($key, 'telescope')) {
            return;
        }

        BaddyBugs::record('cache', $key, [
            'action' => $type,
            'tags' => $tags,
            'expiration' => $seconds,
            // 'value' => $value // Value might be huge or sensitive
        ]);
    }
}


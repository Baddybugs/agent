<?php

namespace BaddyBugs\Agent\Collectors;

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use BaddyBugs\Agent\Facades\BaddyBugs;
use BaddyBugs\Agent\Breadcrumbs;
use BaddyBugs\Agent\Support\SecretsDetector;

class LogCollector implements CollectorInterface
{
    protected array $collectLevels = ['emergency', 'alert', 'critical', 'error', 'warning'];
    protected SecretsDetector $scrubber;

    public function __construct()
    {
        $this->scrubber = new SecretsDetector();
    }

    public function boot(): void
    {
        $this->collectLevels = config('baddybugs.log_levels', $this->collectLevels);

        Event::listen(MessageLogged::class, function (MessageLogged $event) {
            $this->collect($event);
        });
    }

    // Prevent infinite recursion if logging triggers another log event
    protected static bool $isCollecting = false;

    protected function collect(MessageLogged $event): void
    {
        if (self::$isCollecting) {
            return;
        }

        self::$isCollecting = true;

        try {
            // Skip if this is an exception (handled by ExceptionCollector)
            if (isset($event->context['exception'])) {
                return;
            }

            // Improve filtering: Ignore BaddyBugs internal logs to break loops
            if (str_contains($event->message, 'BaddyBugs:')) {
                return;
            }

            // Scrub message for PII and secrets
            $scrubbedMessage = $this->scrubber->scrub($event->message);

            // Add to breadcrumbs for all levels (with scrubbed message)
            Breadcrumbs::add('log', $scrubbedMessage, [
                'level' => $event->level,
            ], $this->mapLevel($event->level));

            // Only fully record significant log levels
            if (!in_array($event->level, $this->collectLevels)) {
                return;
            }

            BaddyBugs::record('log', $event->level, [
                'message' => $scrubbedMessage, // Scrubbed message
                'level' => $event->level,
                'context' => $this->sanitizeContext($event->context),
            ]);
        } catch (\Throwable $e) {
            // Fail silently
        } finally {
            self::$isCollecting = false;
        }
    }

    protected function sanitizeContext(array $context): array
    {
        // Remove objects and large data, then scrub sensitive fields
        $sanitized = collect($context)
            ->filter(fn ($value) => is_scalar($value) || is_array($value))
            ->take(10)
            ->toArray();
        
        // Scrub sensitive fields from context
        return $this->scrubber->scrubSensitiveFields($sanitized);
    }

    protected function mapLevel(string $level): string
    {
        return match ($level) {
            'emergency', 'alert', 'critical', 'error' => 'error',
            'warning' => 'warning',
            'notice', 'info' => 'info',
            default => 'debug',
        };
    }
}

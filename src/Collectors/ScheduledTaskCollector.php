<?php

namespace BaddyBugs\Agent\Collectors;

use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Support\Facades\Event;
use BaddyBugs\Agent\Facades\BaddyBugs;

class ScheduledTaskCollector implements CollectorInterface
{
    /**
     * Boot the collector and listen to scheduled task events.
     */
    public function boot(): void
    {
        // Capture when a task starts
        Event::listen(ScheduledTaskStarting::class, function (ScheduledTaskStarting $event) {
            // Context correlation: Set a new trace ID for this scheduled run?
            // In a long-running daemon (schedule:work), we need to reset the trace ID.
            if (app()->bound(\BaddyBugs\Agent\BaddyBugs::class)) {
                $instance = app(\BaddyBugs\Agent\BaddyBugs::class);
                // Create a deterministic but unique trace for this execution if needed, or just random
                $instance->setTraceId((string) \Illuminate\Support\Str::orderedUuid());
                
                // Add context that this is a scheduled task execution
                $instance->context([
                    'scheduled_task' => $event->task->command ?? 'closure',
                ]);
            }

            BaddyBugs::record('scheduled_task', $event->task->command ?? 'unknown', [
                'event' => 'starting',
                'expression' => $event->task->expression,
                'timezone' => $event->task->timezone,
                'user' => $event->task->user,
                'description' => $event->task->description,
                'frequency_seconds' => $this->parseFrequencySeconds($event->task->expression),
            ]);
        });

        // Capture when a task finishes successfully
        Event::listen(ScheduledTaskFinished::class, function (ScheduledTaskFinished $event) {
            BaddyBugs::record('scheduled_task', $event->task->command ?? 'unknown', [
                'event' => 'finished',
                'runtime' => $event->runtime,
                'exit_code' => $event->task->exitCode ?? 0,
                'frequency_seconds' => $this->parseFrequencySeconds($event->task->expression),
            ]);
        });

        // Capture when a task fails
        Event::listen(ScheduledTaskFailed::class, function (ScheduledTaskFailed $event) {
            BaddyBugs::record('scheduled_task', $event->task->command ?? 'unknown', [
                'event' => 'failed',
                'runtime' => $event->runtime,
                'exception' => $event->exception->getMessage(),
                'trace' => $event->exception->getTraceAsString(),
                'frequency_seconds' => $this->parseFrequencySeconds($event->task->expression),
            ]);
        });

        // Capture when a task is skipped
        Event::listen(ScheduledTaskSkipped::class, function (ScheduledTaskSkipped $event) {
            BaddyBugs::record('scheduled_task', $event->task->command ?? 'unknown', [
                'event' => 'skipped',
                'expression' => $event->task->expression,
                'frequency_seconds' => $this->parseFrequencySeconds($event->task->expression),
            ]);
        });
    }

    protected function parseFrequencySeconds(?string $expression): ?int
    {
        if (!$expression || !is_string($expression)) {
            return null;
        }
        
        $parts = preg_split('/\s+/', trim($expression));
        if (!$parts) {
            return null;
        }
        
        // Seconds-based cron has 6 parts
        if (count($parts) === 6) {
            $secondsField = $parts[0];
            
            if ($secondsField === '*') {
                return 1;
            }
            
            if (str_starts_with($secondsField, '*/')) {
                $n = (int) substr($secondsField, 2);
                return $n > 0 ? $n : null;
            }
            
            if (preg_match('/^\d+(,\d+)*$/', $secondsField)) {
                $values = array_map('intval', explode(',', $secondsField));
                sort($values);
                $minDelta = null;
                for ($i = 1; $i < count($values); $i++) {
                    $delta = $values[$i] - $values[$i - 1];
                    $minDelta = is_null($minDelta) ? $delta : min($minDelta, $delta);
                }
                if (count($values) > 1) {
                    $wrapDelta = (60 - end($values)) + $values[0];
                    $minDelta = is_null($minDelta) ? $wrapDelta : min($minDelta, $wrapDelta);
                }
                return $minDelta ?: null;
            }
        }
        
        // Not seconds-based or could not parse
        return null;
    }
}

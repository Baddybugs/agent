<?php

namespace BaddyBugs\Agent\Collectors;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\Event;
use BaddyBugs\Agent\Facades\BaddyBugs;

class CommandCollector implements CollectorInterface
{
    public function boot(): void
    {
        Event::listen(CommandStarting::class, function (CommandStarting $event) {
            if ($this->shouldIgnore($event->command)) {
                return;
            }
            
            BaddyBugs::startTimer('cmd_' . $event->command);
            BaddyBugs::record('command', $event->command, [
                'status' => 'starting',
                // Avoid capturing arguments/options if they might be sensitive.
                // For now, we capture basic args.
            ]);
        });

        Event::listen(CommandFinished::class, function (CommandFinished $event) {
            if ($this->shouldIgnore($event->command)) {
                return;
            }

            BaddyBugs::stopTimer('cmd_' . $event->command);
            BaddyBugs::record('command', $event->command, [
                'event' => 'finished',
                'exit_code' => $event->exitCode,
            ]);
        });
    }

    protected function shouldIgnore(?string $command): bool
    {
        if (!$command) {
            return true;
        }
        
        $ignored = config('baddybugs.ignore_commands', []);
        
        foreach ($ignored as $pattern) {
             if (fnmatch($pattern, $command)) {
                 return true;
             }
        }
        return false;
    }
}


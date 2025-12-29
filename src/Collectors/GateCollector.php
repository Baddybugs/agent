<?php

namespace BaddyBugs\Agent\Collectors;

use Illuminate\Auth\Access\Events\GateEvaluated;
use Illuminate\Support\Facades\Event;
use BaddyBugs\Agent\Facades\BaddyBugs;

/**
 * Gate Collector
 *
 * Monitors authorization checks in the application.
 * Captures all calls to Gate::allows(), Gate::denies(), @can, etc.
 *
 * Useful for auditing security and debugging permission issues.
 */
class GateCollector implements CollectorInterface
{
    public function boot(): void
    {
        Event::listen(GateEvaluated::class, [$this, 'handleGateEvaluated']);
    }

    public function handleGateEvaluated(GateEvaluated $event): void
    {
        // Ignore checks that shouldn't be recorded to reduce noise
        if ($this->shouldIgnore($event->ability)) {
            return;
        }

        $arguments = $this->formatArguments($event->arguments);
        $user = $event->user;
        
        // Determine the target model/class if possible
        $target = null;
        if (!empty($event->arguments)) {
            $firstArg = $event->arguments[0] ?? null;
            if (is_object($firstArg)) {
                $target = get_class($firstArg);
                if (method_exists($firstArg, 'getKey')) {
                    $target .= ':' . $firstArg->getKey();
                }
            } elseif (is_string($firstArg)) {
                $target = $firstArg;
            }
        }

        BaddyBugs::record('gate', $event->ability, [
            'ability' => $event->ability,
            'result' => $event->result ? 'allowed' : 'denied',
            'arguments' => $arguments,
            'user_id' => $user ? $user->getAuthIdentifier() : null,
            'target' => $target,
        ]);
    }

    protected function formatArguments($arguments): array
    {
        return collect($arguments)->map(function ($argument) {
            if (is_object($argument)) {
                $class = get_class($argument);
                return method_exists($argument, 'getKey') 
                    ? "{$class}:" . $argument->getKey() 
                    : $class;
            }
            return $argument;
        })->toArray();
    }

    protected function shouldIgnore(string $ability): bool
    {
        $ignoredAbilities = config('baddybugs.ignored_gates', []);
        return in_array($ability, $ignoredAbilities);
    }
}

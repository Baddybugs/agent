<?php

namespace BaddyBugs\Agent\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static void record(string $type, string $name, array $payload = [])
 * @method static mixed timer(string $name, callable $callback)
 * @method static \BaddyBugs\Agent\Utils\Timer startTimer(string $name)
 * @method static void stopTimer(string $name)
 * @method static string getTraceId()
 * @method static void setTraceId(string $traceId)
 * 
 * @see \BaddyBugs\Agent\BaddyBugs
 */
class BaddyBugs extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor(): string
    {
        return 'baddybugs';
    }
}


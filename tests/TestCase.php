<?php

namespace BaddyBugs\Agent\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use BaddyBugs\Agent\BaddyBugsAgentServiceProvider;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app)
    {
        return [
            BaddyBugsAgentServiceProvider::class,
        ];
    }
    
    protected function getEnvironmentSetUp($app)
    {
        // Setup default config
        $app['config']->set('baddybugs.enabled', true);
        $app['config']->set('baddybugs.buffer_driver', 'memory');
    }
}


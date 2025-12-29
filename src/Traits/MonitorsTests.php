<?php

namespace BaddyBugs\Agent\Traits;

use BaddyBugs\Agent\Facades\BaddyBugs;
use BaddyBugs\Agent\Collectors\TestCollector;

/**
 * Trait MonitorsTests
 *
 * Add this to your base TestCase.php to automatically capture test results
 * and correlate them with logs, queries, and exceptions in BaddyBugs.
 */
trait MonitorsTests
{
    /**
     * Boot the trait.
     */
    protected function setUpMonitorsTests(): void
    {
        if (!config('baddybugs.enabled') || !config('baddybugs.collectors.test')) {
            return;
        }

        $testName = get_class($this) . '::' . $this->name();
        
        // Resolve the collector manually as it might not be booted in test env by default
        if (app()->bound(TestCollector::class)) {
            app(TestCollector::class)->startTest($testName);
        } else {
            // Manual fallback if collector binding fails
             BaddyBugs::context(['test_name' => $testName]);
        }
    }

    /**
     * Teardown the trait.
     */
    protected function tearDownMonitorsTests(): void
    {
        if (!config('baddybugs.enabled') || !config('baddybugs.collectors.test')) {
            return;
        }
        
        $status = 'passed';
        if ($this->hasFailed()) {
            $status = 'failed';
        } catch (\Throwable $e) {
            $status = 'error';
        }
        
        // In PHPUnit 10+, hasFailed() behavior might differ slightly, 
        // but generally this works for Laravel 10/11 Wrapper.
        
        $exception = null;
        if ($status !== 'passed' && method_exists($this, 'getStatusMessage')) {
             // Try to extract exception if stored
        }

        if (app()->bound(TestCollector::class)) {
            app(TestCollector::class)->endTest($status);
        }
    }
    
    /**
     * Override setUp to hook in.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMonitorsTests();
    }

    /**
     * Override tearDown to hook in.
     */
    protected function tearDown(): void
    {
        $this->tearDownMonitorsTests();
        parent::tearDown();
    }
}

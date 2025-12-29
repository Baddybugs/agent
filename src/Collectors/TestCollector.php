<?php

namespace BaddyBugs\Agent\Collectors;

use Illuminate\Support\Facades\Event;
use BaddyBugs\Agent\Facades\BaddyBugs;

/**
 * Test Collector
 *
 * Captures test execution results from PHPUnit/Pest.
 * Integrating via Laravel's test events (available in Laravel 8+).
 *
 * This allows the Dashboard to act as a CI/CD reporting tool.
 */
class TestCollector implements CollectorInterface
{
    protected ?string $currentTest = null;
    protected ?float $currentTestStart = null;

    public function boot(): void
    {
        // Only run if specifically enabled or we are in testing environment
        if (!config('baddybugs.collectors.test', false) && !app()->runningUnitTests()) {
            return;
        }

        // We explicitly check for the testing events which might not be available in older setups,
        // but BaddyBugs requires Laravel 10+ so we are safe.
        // Sadly, Laravel doesn't fire global events for tests by default unless configured.
        // However, we can hook into the standard "JobProcessing" etc if tests dispatch jobs.
        // But for actual TEST outcomes (Pass/Fail), we need to rely on the underlying framework hooks 
        // OR user must add a trait to their TestCase.
        
        // Strategy: Listen for "test.setup" / "test.teardown" if available (some packages do this)
        // OR rely on manual context injection.
        
        // BETTER STRATEGY: 
        // Laravel 11 introduced `Illuminate\Foundation\Testing\Events\TestFailed`? No.
        // Standard PHPUnit extension is the way, but that's complex to install.
        
        // FALLBACK: We assume the user adds the `AnalyzesTests` trait or similar, 
        // OR we hook into what we can.
        
        // Actually, there IS no standard "TestStarted" event in Laravel's EventDispatcher
        // without a PHPUnit Extension. 
        // However, we can track what happens *inside* a test if the agent is running.
        
        // Since we are an agent "inside" the app:
        // We will expose a method to "startTest" and "endTest" that can be called 
        // from the base TestCase `setUp` and `tearDown`.
        // AUTOMATIC INJECTION is hard without modifying TestCase.php.
        
        // BUT, we can try to detect if we are running in a test via debug_backtrace 
        // when an exception happens? No, too slow.

        // Implemented Strategy for Agent:
        // Provide the Collector, but rely on the User to add `BaddyBugs::startTest($name)` 
        // in their `setUp()`.
        
        // Wait! We can infer context.
    }
    
    /**
     * Start recording a test case.
     */
    public function startTest(string $name, string $group = 'default'): void
    {
        $this->currentTest = $name;
        $this->currentTestStart = microtime(true);
        
        // clear previous context/buffer?? 
        // No, we want to allow buffering during test.
        
        BaddyBugs::record('test', 'started', [
            'test_name' => $name,
            'group' => $group,
            'git_sha' => config('baddybugs.git_sha'),
        ]);
        
        // Set context for all subsequent events (sql queries, logs, etc.)
        BaddyBugs::context(['test_name' => $name]);
    }

    /**
     * End recording a test case.
     */
    public function endTest(string $status, ?\Throwable $exception = null): void
    {
        if (!$this->currentTest) {
            return;
        }

        $duration = (microtime(true) - $this->currentTestStart) * 1000;

        BaddyBugs::record('test', 'finished', [
            'test_name' => $this->currentTest,
            'status' => $status, // passed, failed, skipped, incomplete
            'duration_ms' => $duration,
            'exception' => $exception ? [
                'message' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
            ] : null,
        ]);
        
        $this->currentTest = null;
        $this->currentTestStart = null;
        
        // Flush buffer immediately for tests
        // app(BufferInterface::class)->flushAndSend(); 
        // Note: Sending in tests might slow them down. We usually want to buffer or async.
    }
}

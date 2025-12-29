<?php

namespace BaddyBugs\Agent\Collectors;

use BaddyBugs\Agent\BaddyBugs;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Cache;

/**
 * Health & Background Monitoring Collector
 * 
 * Monitors:
 * - Scheduled tasks (cron jobs)
 * - Queue health metrics
 * - Stuck jobs detection
 * - Heartbeat system
 */
class HealthCollector implements CollectorInterface
{
    protected BaddyBugs $baddybugs;
    
    protected array $runningJobs = [];
    protected array $scheduledTaskTimings = [];
    protected float $lastHeartbeat;

    public function __construct(BaddyBugs $baddybugs)
    {
        $this->baddybugs = $baddybugs;
        $this->lastHeartbeat = microtime(true);
    }

    public function boot(): void
    {
        if (!config('baddybugs.health_monitoring_enabled', true)) {
            return;
        }

        // Monitor scheduled tasks
        if (config('baddybugs.health_monitor_schedule', true)) {
            $this->monitorScheduledTasks();
        }

        // Detect stuck jobs
        if (config('baddybugs.health_detect_stuck_jobs', true)) {
            $this->detectStuckJobs();
        }

        // Monitor queue metrics
        if (config('baddybugs.health_monitor_queues', true)) {
            $this->monitorQueues();
        }

        // Setup heartbeat
        $this->setupHeartbeat();
    }

    protected function monitorScheduledTasks(): void
    {
        Event::listen(ScheduledTaskStarting::class, function (ScheduledTaskStarting $event) {
            $taskName = $this->extractTaskName($event->task);
            
            $this->scheduledTaskTimings[$taskName] = [
                'started_at' => microtime(true),
                'command' => $event->task->command ?? null,
            ];

            $this->baddybugs->record('health', 'scheduled_task_started', [
                'task_name' => $taskName,
                'command' => $event->task->command ?? null,
                'expression' => $event->task->expression ?? null,
            ]);
        });

        Event::listen(ScheduledTaskFinished::class, function (ScheduledTaskFinished $event) {
            $taskName = $this->extractTaskName($event->task);
            $startTime = $this->scheduledTaskTimings[$taskName]['started_at'] ?? microtime(true);
            $duration = (microtime(true) - $startTime) * 1000;

            $this->baddybugs->record('health', 'scheduled_task_finished', [
                'task_name' => $taskName,
                'command' => $event->task->command ?? null,
                'duration_ms' => round($duration, 2),
                'exit_code' => method_exists($event, 'getExitCode') ? $event->getExitCode() : 0,
                'success' => true,
            ]);

            // Store last run time for monitoring
            Cache::put("baddybugs:schedule:{$taskName}:last_run", now(), 86400); // 24h
            
            unset($this->scheduledTaskTimings[$taskName]);
        });

        Event::listen(ScheduledTaskFailed::class, function (ScheduledTaskFailed $event) {
            $taskName = $this->extractTaskName($event->task);

            $this->baddybugs->record('health', 'scheduled_task_failed', [
                'task_name' => $taskName,
                'command' => $event->task->command ?? null,
                'exception' => $event->exception->getMessage() ?? null,
                'severity' => 'high',
            ]);
        });
    }

    protected function detectStuckJobs(): void
    {
        Event::listen(JobProcessing::class, function (JobProcessing $event) {
            $jobId = $event->job->getJobId();
            
            $this->runningJobs[$jobId] = [
                'started_at' => microtime(true),
                'job_name' => $event->job->resolveName(),
                'queue' => $event->job->getQueue(),
                'attempts' => $event->job->attempts(),
            ];
        });

        Event::listen([JobProcessed::class, JobFailed::class], function ($event) {
            $jobId = $event->job->getJobId();
            
            if (!isset($this->runningJobs[$jobId])) {
                return;
            }

            $duration = (microtime(true) - $this->runningJobs[$jobId]['started_at']) * 1000;
            $threshold = config('baddybugs.health_stuck_job_threshold', 3600) * 1000; // Convert to ms

            if ($duration > $threshold) {
                $this->baddybugs->record('health', 'stuck_job_detected', [
                    'job_id' => $jobId,
                    'job_name' => $this->runningJobs[$jobId]['job_name'],
                    'queue' => $this->runningJobs[$jobId]['queue'],
                    'duration_ms' => round($duration, 2),
                    'duration_seconds' => round($duration / 1000, 2),
                    'threshold_seconds' => config('baddybugs.health_stuck_job_threshold', 3600),
                    'severity' => 'critical',
                ]);
            }

            unset($this->runningJobs[$jobId]);
        });
    }

    protected function monitorQueues(): void
    {
        // Monitor queue performance on job completion
        Event::listen(JobProcessed::class, function (JobProcessed $event) {
            $this->recordQueueMetrics($event->job->getQueue(), true);
        });

        Event::listen(JobFailed::class, function (JobFailed $event) {
            $this->recordQueueMetrics($event->job->getQueue(), false);
        });
    }

    protected function recordQueueMetrics(string $queue, bool $success): void
    {
        $cacheKey = "baddybugs:queue:{$queue}:metrics";
        $metrics = Cache::get($cacheKey, [
            'total' => 0,
            'succeeded' => 0,
            'failed' => 0,
            'durations' => [],
        ]);

        $metrics['total']++;
        if ($success) {
            $metrics['succeeded']++;
        } else {
            $metrics['failed']++;
        }

        // Store for aggregation
        Cache::put($cacheKey, $metrics, 3600); // 1 hour

        // Report every N jobs or on failures
        if ($metrics['total'] % 100 === 0 || !$success) {
            $this->baddybugs->record('health', 'queue_metrics', [
                'queue' => $queue,
                'total_jobs' => $metrics['total'],
                'succeeded' => $metrics['succeeded'],
                'failed' => $metrics['failed'],
                'success_rate' => $metrics['total'] > 0 
                    ? round(($metrics['succeeded'] / $metrics['total']) * 100, 2) 
                    : 100,
                'failure_rate' => $metrics['total'] > 0 
                    ? round(($metrics['failed'] / $metrics['total']) * 100, 2) 
                    : 0,
            ]);
        }
    }

    protected function setupHeartbeat(): void
    {
        $interval = config('baddybugs.health_heartbeat_interval', 60);
        
        // Send heartbeat in terminate phase
        app()->terminating(function () use ($interval) {
            $elapsed = microtime(true) - $this->lastHeartbeat;
            
            if ($elapsed >= $interval) {
                $this->sendHeartbeat();
                $this->lastHeartbeat = microtime(true);
            }
        });
    }

    protected function sendHeartbeat(): void
    {
        $this->baddybugs->record('health', 'heartbeat', [
            'timestamp' => now()->toIso8601String(),
            'uptime' => $this->getUptime(),
            'memory_usage' => memory_get_usage(true),
            'memory_usage_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
            'peak_memory' => memory_get_peak_usage(true),
            'peak_memory_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
        ]);
    }

    protected function getUptime(): float
    {
        if (defined('LARAVEL_START')) {
            return microtime(true) - LARAVEL_START;
        }

        return 0.0;
    }

    protected function extractTaskName($task): string
    {
        if (isset($task->description)) {
            return $task->description;
        }

        if (isset($task->command)) {
            return $task->command;
        }

        return 'unknown_task';
    }

    /**
     * Get health status overview
     */
    public function getHealthStatus(): array
    {
        return [
            'running_jobs' => count($this->runningJobs),
            'scheduled_tasks_running' => count($this->scheduledTaskTimings),
            'last_heartbeat' => date('Y-m-d H:i:s', (int) $this->lastHeartbeat),
            'uptime' => $this->getUptime(),
        ];
    }
}

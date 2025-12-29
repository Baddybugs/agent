<?php

namespace BaddyBugs\Agent\Collectors;

use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use BaddyBugs\Agent\Facades\BaddyBugs;

class JobCollector implements CollectorInterface
{
    /**
     * @var array<string, float>
     */
    protected array $jobTimers = [];

    public function boot(): void
    {
        // Propagate Trace ID to queued jobs
        if (config('baddybugs.tracing')) {
            Queue::createPayloadUsing(function ($connection, $queue, $payload) {
                return ['baddybugs_trace_id' => BaddyBugs::getTraceId()];
            });
        }

        Event::listen(JobProcessing::class, [$this, 'handleProcessing']);
        Event::listen(JobProcessed::class, [$this, 'handleProcessed']);
        Event::listen(JobFailed::class, [$this, 'handleFailed']);
    }

    public function handleProcessing(JobProcessing $event): void
    {
        $jobName = $event->job->resolveName();
        $jobId = $event->job->getJobId();

        if ($this->shouldIgnore($jobName) || BaddyBugs::shouldFilterJob($event)) {
            return;
        }

        // Restore trace ID if present
        $payload = $event->job->payload();
        if (isset($payload['baddybugs_trace_id'])) {
            BaddyBugs::setTraceId($payload['baddybugs_trace_id']);
        }
        
        // Calculate Queue Wait Time (Latency)
        $waitTimeMs = null;
        if (isset($payload['pushedAt'])) {
            $pushedAt = (float) $payload['pushedAt'];
            $waitTimeMs = (microtime(true) - $pushedAt) * 1000;
        }

        // Start internal timer for duration
        $this->jobTimers[$jobId] = microtime(true);

        BaddyBugs::startTimer('job_' . $jobId);
        
        BaddyBugs::record('job', $jobName, [
            'status' => 'processing',
            'job_class' => $jobName, // Ensure job_class is available
            'queue' => $event->job->getQueue(),
            'connection' => $event->connectionName,
            'attempts' => $event->job->attempts(),
            'wait_time_ms' => $waitTimeMs, // NEW: Critical metric for scaling
            'payload' => $payload['data'] ?? [],
        ]);
    }

    protected function shouldIgnore(string $jobName): bool
    {
        $ignored = config('baddybugs.ignore_jobs', []);
        
        foreach ($ignored as $pattern) {
            if ($jobName === $pattern || fnmatch($pattern, $jobName)) {
                return true;
            }
        }
        
        return false;
    }

    public function handleProcessed(JobProcessed $event): void
    {
        $jobId = $event->job->getJobId();
        BaddyBugs::stopTimer('job_' . $jobId);

        // Calculate duration
        $durationMs = 0;
        if (isset($this->jobTimers[$jobId])) {
            $durationMs = (microtime(true) - $this->jobTimers[$jobId]) * 1000;
            unset($this->jobTimers[$jobId]);
        }

        BaddyBugs::record('job', $event->job->resolveName(), [
            'status' => 'processed',
            'job_class' => $event->job->resolveName(),
            'job_id' => $jobId,
            'duration_ms' => $durationMs,
        ]);
    }

    public function handleFailed(JobFailed $event): void
    {
        $jobId = $event->job->getJobId();
        BaddyBugs::stopTimer('job_' . $jobId);

        // Calculate duration
        $durationMs = 0;
        if (isset($this->jobTimers[$jobId])) {
            $durationMs = (microtime(true) - $this->jobTimers[$jobId]) * 1000;
            unset($this->jobTimers[$jobId]);
        }

        BaddyBugs::record('job', $event->job->resolveName(), [
            'status' => 'failed',
            'job_class' => $event->job->resolveName(),
            'job_id' => $jobId,
            'duration_ms' => $durationMs,
            'exception' => $event->exception->getMessage(),
            'trace' => $event->exception->getTraceAsString(),
            'exception_class' => get_class($event->exception),
            'exception_message' => $event->exception->getMessage(),
        ]);
    }
}


<?php

namespace BaddyBugs\Agent\Collectors;

use BaddyBugs\Agent\BaddyBugs;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Cache;

/**
 * Queue Metrics Collector
 * 
 * Tracks queue health and metrics:
 * - Queue depth per queue
 * - Processing rates
 * - Failed jobs count
 * - Oldest job age
 * - Worker availability
 */
class QueueMetricsCollector implements CollectorInterface
{
    protected BaddyBugs $baddybugs;

    public function __construct(BaddyBugs $baddybugs)
    {
        $this->baddybugs = $baddybugs;
    }

    public function boot(): void
    {
        if (!config('baddybugs.queue_metrics_enabled', true)) {
            return;
        }

        // Collect metrics periodically (every N requests)
        if ($this->shouldCollectMetrics()) {
            $this->collectQueueMetrics();
        }
    }

    protected function shouldCollectMetrics(): bool
    {
        // Collect every 10th request to avoid overhead
        try {
            $counter = Cache::increment('baddybugs:queue_metrics_counter', 1);
            return $counter % 10 === 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    protected function collectQueueMetrics(): void
    {
        try {
            $metrics = [
                'queues' => [],
                'total_pending' => 0,
                'total_failed' => 0,
                'timestamp' => now()->toIso8601String(),
            ];

            // Get configured queues
            $queues = $this->getConfiguredQueues();

            foreach ($queues as $queueName) {
                $queueMetrics = $this->getQueueStats($queueName);
                $metrics['queues'][$queueName] = $queueMetrics;
                $metrics['total_pending'] += $queueMetrics['pending_count'];
            }

            // Failed jobs count
            $metrics['total_failed'] = $this->getFailedJobsCount();

            // Redis queue specific metrics (if using Redis)
            if (config('queue.default') === 'redis') {
                $metrics['redis_memory_usage'] = $this->getRedisMemoryUsage();
            }

            $this->baddybugs->record('queue_metrics', 'snapshot', $metrics);
        } catch (\Throwable $e) {
            // Fail silently
        }
    }

    protected function getConfiguredQueues(): array
    {
        $default = config('queue.connections.' . config('queue.default') . '.queue', 'default');
        
        // Try to get from config or use default
        $queues = config('baddybugs.monitored_queues', [$default]);
        
        return is_array($queues) ? $queues : [$default];
    }

    protected function getQueueStats(string $queueName): array
    {
        $stats = [
            'name' => $queueName,
            'pending_count' => 0,
            'processing_count' => 0,
            'oldest_job_age_seconds' => null,
        ];

        try {
            $connection = Queue::connection();
            
            // Redis-specific
            if (method_exists($connection, 'size')) {
                $stats['pending_count'] = $connection->size($queueName);
            }

            // Get oldest job timestamp (Redis)
            if ($stats['pending_count'] > 0 && config('queue.default') === 'redis') {
                $redis = app('redis')->connection(config('queue.connections.redis.connection', 'default'));
                $oldestJob = $redis->lindex('queues:' . $queueName, 0);
                
                if ($oldestJob) {
                    $job = json_decode($oldestJob, true);
                    if (isset($job['pushedAt'])) {
                        $stats['oldest_job_age_seconds'] = time() - $job['pushedAt'];
                    }
                }
            }
        } catch (\Throwable $e) {
            // Queue doesn't exist or can't be accessed
        }

        return $stats;
    }

    protected function getFailedJobsCount(): int
    {
        try {
            // Try to count failed jobs from database
            if (schema()->hasTable('failed_jobs')) {
                return \DB::table('failed_jobs')->count();
            }
        } catch (\Throwable $e) {
            // Ignore
        }

        return 0;
    }

    protected function getRedisMemoryUsage(): ?int
    {
        try {
            $redis = app('redis')->connection(config('queue.connections.redis.connection', 'default'));
            $info = $redis->info('memory');
            
            if (isset($info['used_memory'])) {
                return (int) $info['used_memory'];
            }
        } catch (\Throwable $e) {
            // Ignore
        }

        return null;
    }
}

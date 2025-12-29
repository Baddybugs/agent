<?php

namespace BaddyBugs\Agent\Support;

class MemoryProfiler
{
    private array $snapshots = [];
    private int $startMemory;
    private int $peakMemory = 0;
    private array $allocations = [];

    public function __construct()
    {
        $this->startMemory = memory_get_usage(true);
        $this->peakMemory = memory_get_peak_usage(true);
    }

    /**
     * Take a memory snapshot at a specific point
     */
    public function snapshot(string $label): void
    {
        $current = memory_get_usage(true);
        $peak = memory_get_peak_usage(true);

        $this->snapshots[] = [
            'label' => $label,
            'memory' => $current,
            'peak' => $peak,
            'diff' => $current - $this->startMemory,
            'timestamp' => microtime(true),
        ];

        if ($peak > $this->peakMemory) {
            $this->peakMemory = $peak;
        }
    }

    /**
     * Track Eloquent model hydration
     */
    public function trackEloquentHydration(string $model, int $count): void
    {
        $memoryBefore = memory_get_usage(true);
        
        // We can't directly measure, but we can estimate
        $this->allocations[] = [
            'type' => 'eloquent',
            'model' => $model,
            'count' => $count,
            'memory_before' => $memoryBefore,
            'timestamp' => microtime(true),
        ];
    }

    /**
     * Detect heavy memory usage patterns
     */
    public function analyze(): array
    {
        $totalMemory = $this->peakMemory - $this->startMemory;
        
        // Heavy = more than 50MB used
        $isHeavy = $totalMemory > 50 * 1024 * 1024;

        // Find biggest memory jumps
        $biggestJump = 0;
        $biggestLabel = null;

        for ($i = 1; $i < count($this->snapshots); $i++) {
            $jump = $this->snapshots[$i]['memory'] - $this->snapshots[$i - 1]['memory'];
            if ($jump > $biggestJump) {
                $biggestJump = $jump;
                $biggestLabel = $this->snapshots[$i]['label'];
            }
        }

        return [
            'start_memory' => $this->startMemory,
            'peak_memory' => $this->peakMemory,
            'total_used' => $totalMemory,
            'is_heavy' => $isHeavy,
            'snapshots' => $this->snapshots,
            'allocations' => $this->allocations,
            'biggest_jump' => [
                'size' => $biggestJump,
                'label' => $biggestLabel,
            ],
            'suggestions' => $this->generateSuggestions($totalMemory, $this->allocations),
        ];
    }

    /**
     * Generate optimization suggestions
     */
    private function generateSuggestions(int $totalMemory, array $allocations): array
    {
        $suggestions = [];

        // Heavy memory usage
        if ($totalMemory > 50 * 1024 * 1024) {
            $suggestions[] = [
                'type' => 'high_memory',
                'severity' => 'warning',
                'message' => sprintf('Request used %s MB of memory', round($totalMemory / 1024 / 1024, 2)),
                'recommendation' => 'Consider using cursor() for large datasets or chunking the query',
            ];
        }

        // Too many Eloquent models loaded
        $totalEloquent = 0;
        foreach ($allocations as $allocation) {
            if ($allocation['type'] === 'eloquent') {
                $totalEloquent += $allocation['count'];
            }
        }

        if ($totalEloquent > 1000) {
            $suggestions[] = [
                'type' => 'too_many_models',
                'severity' => 'critical',
                'message' => sprintf('%d Eloquent models loaded in memory', $totalEloquent),
                'recommendation' => 'Use DB::table() for non-eloquent operations or implement pagination',
            ];
        }

        return $suggestions;
    }

    /**
     * Format bytes to human readable
     */
    public static function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $power = $bytes > 0 ? floor(log($bytes, 1024)) : 0;
        
        return round($bytes / pow(1024, $power), 2) . ' ' . $units[$power];
    }

    /**
     * Get flamegraph data structure
     */
    public function getFlamegraphData(): array
    {
        $nodes = [];
        
        foreach ($this->snapshots as $i => $snapshot) {
            $nodes[] = [
                'name' => $snapshot['label'],
                'value' => $snapshot['diff'],
                'children' => [],
            ];
        }

        return [
            'name' => 'Request',
            'value' => $this->peakMemory - $this->startMemory,
            'children' => $nodes,
        ];
    }
}

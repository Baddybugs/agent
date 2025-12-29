<?php

namespace Baddybugs\Agent\Collectors;

use Illuminate\Support\Facades\Event;

class MemoryCollector implements CollectorInterface
{
    protected array $snapshots = [];
    protected bool $enabled = true;
    protected int $sampleInterval = 100; // Sample every N requests
    protected int $requestCount = 0;
    protected float $startMemory = 0;
    protected array $checkpoints = [];

    public function register(): void
    {
        if (!$this->enabled) {
            return;
        }

        $this->startMemory = memory_get_usage(true);

        // Register shutdown function to capture final memory state
        register_shutdown_function(function () {
            $this->captureSnapshot('shutdown');
        });
    }

    /**
     * Set a memory checkpoint with a label
     */
    public function checkpoint(string $label): void
    {
        $this->checkpoints[$label] = [
            'memory' => memory_get_usage(true),
            'peak' => memory_get_peak_usage(true),
            'timestamp' => microtime(true),
        ];
    }

    /**
     * Capture a memory snapshot
     */
    public function captureSnapshot(string $trigger = 'manual'): array
    {
        $snapshot = [
            'trigger' => $trigger,
            'timestamp' => microtime(true),
            'memory_usage' => memory_get_usage(true),
            'memory_peak' => memory_get_peak_usage(true),
            'memory_real' => memory_get_usage(false),
            'memory_limit' => $this->getMemoryLimit(),
            'start_memory' => $this->startMemory,
            'memory_delta' => memory_get_usage(true) - $this->startMemory,
            'checkpoints' => $this->checkpoints,
        ];

        $this->snapshots[] = $snapshot;

        return $snapshot;
    }

    /**
     * Get memory limit in bytes
     */
    protected function getMemoryLimit(): int
    {
        $limit = ini_get('memory_limit');
        if ($limit === '-1') {
            return -1;
        }

        $unit = strtolower(substr($limit, -1));
        $value = (int) $limit;

        switch ($unit) {
            case 'g':
                $value *= 1024;
            case 'm':
                $value *= 1024;
            case 'k':
                $value *= 1024;
        }

        return $value;
    }

    /**
     * Check if memory usage is concerning
     */
    public function isMemoryConcerning(): bool
    {
        $limit = $this->getMemoryLimit();
        if ($limit === -1) {
            return false;
        }

        $usage = memory_get_usage(true);
        return ($usage / $limit) > 0.8; // More than 80% used
    }

    /**
     * Get memory analysis
     */
    public function analyze(): array
    {
        $currentMemory = memory_get_usage(true);
        $peakMemory = memory_get_peak_usage(true);
        $limit = $this->getMemoryLimit();

        $analysis = [
            'current' => $currentMemory,
            'peak' => $peakMemory,
            'limit' => $limit,
            'start' => $this->startMemory,
            'delta' => $currentMemory - $this->startMemory,
            'usage_percentage' => $limit > 0 ? ($currentMemory / $limit) * 100 : 0,
            'peak_percentage' => $limit > 0 ? ($peakMemory / $limit) * 100 : 0,
            'is_concerning' => $this->isMemoryConcerning(),
            'snapshots' => $this->snapshots,
            'checkpoints' => $this->checkpoints,
        ];

        // Detect potential leaks
        if (count($this->snapshots) >= 2) {
            $first = reset($this->snapshots);
            $last = end($this->snapshots);
            $growth = $last['memory_usage'] - $first['memory_usage'];
            $analysis['memory_growth'] = $growth;
            $analysis['potential_leak'] = $growth > (10 * 1024 * 1024); // 10MB growth
        }

        return $analysis;
    }

    /**
     * Collect memory data for the request
     */
    public function collect(): array
    {
        $analysis = $this->analyze();

        return [
            'memory_usage' => $analysis['current'],
            'memory_peak' => $analysis['peak'],
            'memory_limit' => $analysis['limit'],
            'memory_start' => $analysis['start'],
            'memory_delta' => $analysis['delta'],
            'usage_percentage' => round($analysis['usage_percentage'], 2),
            'is_concerning' => $analysis['is_concerning'],
            'potential_leak' => $analysis['potential_leak'] ?? false,
            'checkpoints' => $this->formatCheckpoints(),
        ];
    }

    /**
     * Format checkpoints for output
     */
    protected function formatCheckpoints(): array
    {
        $formatted = [];
        $previousMemory = $this->startMemory;

        foreach ($this->checkpoints as $label => $checkpoint) {
            $formatted[] = [
                'label' => $label,
                'memory' => $checkpoint['memory'],
                'peak' => $checkpoint['peak'],
                'delta' => $checkpoint['memory'] - $previousMemory,
                'timestamp' => $checkpoint['timestamp'],
            ];
            $previousMemory = $checkpoint['memory'];
        }

        return $formatted;
    }

    /**
     * Get object memory usage estimation (PHP 8+)
     */
    public function estimateObjectMemory($object): int
    {
        if (!is_object($object)) {
            return 0;
        }

        // Rough estimation using serialization
        try {
            return strlen(serialize($object));
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Track large objects
     */
    public function trackLargeObjects(int $threshold = 1048576): array
    {
        $largeObjects = [];

        foreach (get_defined_vars() as $name => $value) {
            if (is_object($value) || is_array($value)) {
                $size = $this->estimateObjectMemory($value);
                if ($size > $threshold) {
                    $largeObjects[] = [
                        'name' => $name,
                        'type' => is_object($value) ? get_class($value) : 'array',
                        'size' => $size,
                    ];
                }
            }
        }

        return $largeObjects;
    }

    /**
     * Reset the collector
     */
    public function reset(): void
    {
        $this->snapshots = [];
        $this->checkpoints = [];
        $this->startMemory = memory_get_usage(true);
    }

    /**
     * Enable/disable the collector
     */
    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
    }

    /**
     * Check if collector is enabled
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }
}

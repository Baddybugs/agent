<?php

namespace Baddybugs\Agent\Integrations;

class CustomMetrics
{
    protected static ?CustomMetrics $instance = null;
    protected array $metrics = [];
    protected array $counters = [];
    protected array $gauges = [];
    protected array $histograms = [];
    protected bool $enabled = true;

    /**
     * Get singleton instance
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Record a counter metric (incrementing value)
     */
    public function counter(string $name, int $value = 1, array $tags = []): self
    {
        if (!$this->enabled) {
            return $this;
        }

        $key = $this->buildKey($name, $tags);
        
        if (!isset($this->counters[$key])) {
            $this->counters[$key] = [
                'name' => $name,
                'value' => 0,
                'tags' => $tags,
                'created_at' => microtime(true),
            ];
        }
        
        $this->counters[$key]['value'] += $value;
        $this->counters[$key]['updated_at'] = microtime(true);

        return $this;
    }

    /**
     * Record a gauge metric (point-in-time value)
     */
    public function gauge(string $name, float $value, array $tags = []): self
    {
        if (!$this->enabled) {
            return $this;
        }

        $key = $this->buildKey($name, $tags);
        
        $this->gauges[$key] = [
            'name' => $name,
            'value' => $value,
            'tags' => $tags,
            'timestamp' => microtime(true),
        ];

        return $this;
    }

    /**
     * Record a histogram metric (distribution of values)
     */
    public function histogram(string $name, float $value, array $tags = []): self
    {
        if (!$this->enabled) {
            return $this;
        }

        $key = $this->buildKey($name, $tags);
        
        if (!isset($this->histograms[$key])) {
            $this->histograms[$key] = [
                'name' => $name,
                'values' => [],
                'tags' => $tags,
                'created_at' => microtime(true),
            ];
        }
        
        $this->histograms[$key]['values'][] = $value;
        $this->histograms[$key]['updated_at'] = microtime(true);

        return $this;
    }

    /**
     * Record a timing metric (duration in milliseconds)
     */
    public function timing(string $name, float $durationMs, array $tags = []): self
    {
        return $this->histogram("{$name}.timing", $durationMs, array_merge($tags, ['unit' => 'ms']));
    }

    /**
     * Measure the execution time of a callback
     */
    public function measure(string $name, callable $callback, array $tags = []): mixed
    {
        $start = microtime(true);
        
        try {
            $result = $callback();
            $this->counter("{$name}.success", 1, $tags);
            return $result;
        } catch (\Throwable $e) {
            $this->counter("{$name}.error", 1, $tags);
            throw $e;
        } finally {
            $duration = (microtime(true) - $start) * 1000;
            $this->timing($name, $duration, $tags);
        }
    }

    /**
     * Increment a counter
     */
    public function increment(string $name, int $by = 1, array $tags = []): self
    {
        return $this->counter($name, $by, $tags);
    }

    /**
     * Decrement a counter (records negative value)
     */
    public function decrement(string $name, int $by = 1, array $tags = []): self
    {
        return $this->counter($name, -$by, $tags);
    }

    /**
     * Record a business metric
     */
    public function business(string $name, float $value, array $metadata = []): self
    {
        $this->metrics[] = [
            'type' => 'business',
            'name' => $name,
            'value' => $value,
            'metadata' => $metadata,
            'timestamp' => microtime(true),
        ];

        return $this;
    }

    /**
     * Build a unique key for a metric with tags
     */
    protected function buildKey(string $name, array $tags): string
    {
        if (empty($tags)) {
            return $name;
        }

        ksort($tags);
        $tagString = implode(',', array_map(
            fn($k, $v) => "{$k}={$v}",
            array_keys($tags),
            array_values($tags)
        ));

        return "{$name}:{$tagString}";
    }

    /**
     * Collect all metrics for sending
     */
    public function collect(): array
    {
        $collected = [
            'counters' => array_values($this->counters),
            'gauges' => array_values($this->gauges),
            'histograms' => array_map(function ($h) {
                return [
                    'name' => $h['name'],
                    'tags' => $h['tags'],
                    'count' => count($h['values']),
                    'sum' => array_sum($h['values']),
                    'min' => min($h['values']),
                    'max' => max($h['values']),
                    'avg' => count($h['values']) > 0 ? array_sum($h['values']) / count($h['values']) : 0,
                    'p50' => $this->percentile($h['values'], 50),
                    'p95' => $this->percentile($h['values'], 95),
                    'p99' => $this->percentile($h['values'], 99),
                ];
            }, $this->histograms),
            'business' => $this->metrics,
        ];

        return $collected;
    }

    /**
     * Calculate percentile
     */
    protected function percentile(array $values, int $percentile): float
    {
        if (empty($values)) {
            return 0;
        }

        sort($values);
        $index = (count($values) - 1) * ($percentile / 100);
        $lower = floor($index);
        $upper = ceil($index);
        
        if ($lower === $upper) {
            return $values[(int)$lower];
        }

        $fraction = $index - $lower;
        return $values[(int)$lower] * (1 - $fraction) + $values[(int)$upper] * $fraction;
    }

    /**
     * Reset all metrics
     */
    public function reset(): self
    {
        $this->counters = [];
        $this->gauges = [];
        $this->histograms = [];
        $this->metrics = [];
        
        return $this;
    }

    /**
     * Enable/disable metrics collection
     */
    public function setEnabled(bool $enabled): self
    {
        $this->enabled = $enabled;
        return $this;
    }

    /**
     * Check if metrics collection is enabled
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Get count of collected metrics
     */
    public function count(): int
    {
        return count($this->counters) + count($this->gauges) + count($this->histograms) + count($this->metrics);
    }
}

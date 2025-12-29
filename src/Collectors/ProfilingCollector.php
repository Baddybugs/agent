<?php

namespace BaddyBugs\Agent\Collectors;

use BaddyBugs\Agent\Facades\BaddyBugs;

class ProfilingCollector implements CollectorInterface
{
    protected array $timers = [];

    public function boot(): void
    {
        // Auto-start a "request" timer if not in console
        if (!app()->runningInConsole()) {
            $this->startTimer('total_execution');
        }

        app()->terminating(function () {
            if (isset($this->timers['total_execution'])) {
                $this->stopTimer('total_execution');
            }
        });
    }

    /**
     * Start a custom timer.
     */
    public function startTimer(string $name): void
    {
        $this->timers[$name] = [
            'start' => hrtime(true),
            'memory_start' => memory_get_usage(),
        ];
    }

    /**
     * Stop a custom timer and record the segment.
     */
    public function stopTimer(string $name): void
    {
        if (!isset($this->timers[$name])) {
            return;
        }

        $end = hrtime(true);
        $start = $this->timers[$name]['start'];
        $memoryEnd = memory_get_usage();
        $memoryStart = $this->timers[$name]['memory_start'];

        $durationMs = ($end - $start) / 1e6; // nanoseconds to milliseconds
        $memoryDiff = $memoryEnd - $memoryStart;

        BaddyBugs::record('profiling_segment', $name, [
            'duration_ms' => round($durationMs, 4),
            'memory_delta' => $memoryDiff,
            'is_slow' => $durationMs > config('baddybugs.profiling_slow_threshold', 100),
        ]);

        unset($this->timers[$name]);
    }

    /**
     * Collect system profiling metrics
     */
    public static function collect(): array
    {
        return [
            'cpu' => self::getCpuUsage(),
            'memory' => self::getMemoryUsage(),
            'disk' => self::getDiskUsage(),
            'connections' => self::getConnectionCount(),
            'opcache' => self::getOpcacheStats(),
            'php' => self::getPhpStats(),
        ];
    }

    /**
     * Get CPU usage percentage (Linux/Unix)
     */
    protected static function getCpuUsage(): ?float
    {
        if (!function_exists('sys_getloadavg')) {
            return null;
        }

        $load = sys_getloadavg();
        $cpuCount = self::getCpuCount();
        
        // Calculate CPU usage as percentage (1-minute load average)
        return $cpuCount > 0 ? round(($load[0] / $cpuCount) * 100, 2) : null;
    }

    /**
     * Get number of CPU cores
     */
    protected static function getCpuCount(): int
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $process = @popen('wmic cpu get NumberOfCores', 'rb');
            if ($process) {
                fgets($process);
                $cores = (int) fgets($process);
                pclose($process);
                return $cores ?: 1;
            }
        } elseif (is_file('/proc/cpuinfo')) {
            $cpuinfo = file_get_contents('/proc/cpuinfo');
            preg_match_all('/^processor/m', $cpuinfo, $matches);
            return count($matches[0]) ?: 1;
        }

        return 1;
    }

    /**
     * Get memory usage
     */
    protected static function getMemoryUsage(): array
    {
        return [
            'current' => memory_get_usage(true),
            'peak' => memory_get_peak_usage(true),
            'limit' => self::getMemoryLimit(),
            'percentage' => self::getMemoryPercentage(),
        ];
    }

    /**
     * Get PHP memory limit in bytes
     */
    protected static function getMemoryLimit(): int
    {
        $limit = ini_get('memory_limit');
        
        if ($limit === '-1') {
            return -1; // Unlimited
        }

        return self::convertToBytes($limit);
    }

    /**
     * Get memory usage percentage
     */
    protected static function getMemoryPercentage(): ?float
    {
        $limit = self::getMemoryLimit();
        
        if ($limit === -1) {
            return null; // Unlimited
        }

        $usage = memory_get_usage(true);
        return round(($usage / $limit) * 100, 2);
    }

    /**
     * Get disk usage for storage path
     */
    protected static function getDiskUsage(): array
    {
        $path = storage_path();
        
        return [
            'free' => disk_free_space($path),
            'total' => disk_total_space($path),
            'used' => disk_total_space($path) - disk_free_space($path),
            'percentage' => round(((disk_total_space($path) - disk_free_space($path)) / disk_total_space($path)) * 100, 2),
        ];
    }

    /**
     * Get database connection count
     */
    protected static function getConnectionCount(): int
    {
        try {
            $connections = \DB::select('SELECT COUNT(*) as count FROM pg_stat_activity');
            return $connections[0]->count ?? 0;
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Get OPcache statistics
     */
    protected static function getOpcacheStats(): ?array
    {
        if (!function_exists('opcache_get_status')) {
            return null;
        }

        $status = opcache_get_status(false);
        
        if (!$status) {
            return null;
        }

        return [
            'enabled' => $status['opcache_enabled'] ?? false,
            'memory_usage' => $status['memory_usage'] ?? [],
            'hits' => $status['opcache_statistics']['hits'] ?? 0,
            'misses' => $status['opcache_statistics']['misses'] ?? 0,
            'hit_rate' => $status['opcache_statistics']['opcache_hit_rate'] ?? 0,
        ];
    }

    /**
     * Get PHP statistics
     */
    protected static function getPhpStats(): array
    {
        return [
            'version' => PHP_VERSION,
            'sapi' => PHP_SAPI,
            'max_execution_time' => ini_get('max_execution_time'),
            'upload_max_filesize' => ini_get('upload_max_filesize'),
            'post_max_size' => ini_get('post_max_size'),
        ];
    }

    /**
     * Convert memory string to bytes
     */
    protected static function convertToBytes(string $value): int
    {
        $value = trim($value);
        $last = strtolower($value[strlen($value) - 1]);
        $value = (int) $value;

        switch ($last) {
            case 'g':
                $value *= 1024;
            case 'm':
                $value *= 1024;
            case 'k':
                $value *= 1024;
        }

        return $value;
    }
}

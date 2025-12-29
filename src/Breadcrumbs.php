<?php

namespace BaddyBugs\Agent;

class Breadcrumbs
{
    protected static array $crumbs = [];
    protected static int $maxCrumbs = 50;

    /**
     * Add a breadcrumb to the trail.
     */
    public static function add(string $category, string $message, array $data = [], string $level = 'info'): void
    {
        self::$crumbs[] = [
            'timestamp' => microtime(true),
            'category' => $category,
            'message' => $message,
            'data' => $data,
            'level' => $level, // debug, info, warning, error
        ];

        // Keep only the last N breadcrumbs
        if (count(self::$crumbs) > self::$maxCrumbs) {
            self::$crumbs = array_slice(self::$crumbs, -self::$maxCrumbs);
        }
    }

    /**
     * Get all breadcrumbs.
     */
    public static function all(): array
    {
        return self::$crumbs;
    }

    /**
     * Clear the breadcrumb trail.
     */
    public static function clear(): void
    {
        self::$crumbs = [];
    }

    /**
     * Set the maximum number of breadcrumbs to keep.
     */
    public static function setMaxCrumbs(int $max): void
    {
        self::$maxCrumbs = $max;
    }

    /**
     * Helper to add a navigation breadcrumb.
     */
    public static function navigation(string $from, string $to): void
    {
        self::add('navigation', "Navigated from {$from} to {$to}", [
            'from' => $from,
            'to' => $to,
        ]);
    }

    /**
     * Helper to add a user action breadcrumb.
     */
    public static function action(string $action, array $data = []): void
    {
        self::add('user', $action, $data);
    }

    /**
     * Helper to add a query breadcrumb.
     */
    public static function query(string $sql, float $duration): void
    {
        self::add('query', $sql, ['duration_ms' => $duration], $duration > 100 ? 'warning' : 'info');
    }

    /**
     * Helper to add an HTTP breadcrumb.
     */
    public static function http(string $method, string $url, int $status): void
    {
        $level = $status >= 400 ? 'error' : 'info';
        self::add('http', "{$method} {$url}", ['status' => $status], $level);
    }
}

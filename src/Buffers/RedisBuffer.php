<?php

namespace BaddyBugs\Agent\Buffers;

use Illuminate\Support\Facades\Redis;

class RedisBuffer implements BufferInterface
{
    protected string $key = 'baddybugs:buffer';
    protected int $maxSize = 10000;

    public function __construct()
    {
        $this->key = config('baddybugs.redis_key', 'baddybugs:buffer');
        $this->maxSize = config('baddybugs.redis_max_size', 10000);
    }

    public function push(array $entry): void
    {
        try {
            $connection = config('baddybugs.redis_connection', 'default');
            $redis = Redis::connection($connection);
            
            // Use LPUSH for fast insertion
            $redis->lpush($this->key, json_encode($entry));
            
            // Trim to prevent unbounded growth
            $redis->ltrim($this->key, 0, $this->maxSize - 1);
        } catch (\Throwable $e) {
            // Fail silently - monitoring should never break the app
        }
    }

    public function flush(): array
    {
        try {
            $connection = config('baddybugs.redis_connection', 'default');
            $redis = Redis::connection($connection);
            
            $items = [];
            $batchSize = config('baddybugs.batch_size', 100);
            
            // Atomically pop items
            for ($i = 0; $i < $batchSize; $i++) {
                $item = $redis->rpop($this->key);
                if ($item === null) {
                    break;
                }
                $decoded = json_decode($item, true);
                if ($decoded) {
                    $items[] = $decoded;
                }
            }
            
            return $items;
        } catch (\Throwable $e) {
            return [];
        }
    }

    public function count(): int
    {
        try {
            $connection = config('baddybugs.redis_connection', 'default');
            return Redis::connection($connection)->llen($this->key);
        } catch (\Throwable $e) {
            return 0;
        }
    }
}

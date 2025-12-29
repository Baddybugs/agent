<?php

namespace BaddyBugs\Agent\Buffers;

class FileBuffer implements BufferInterface
{
    protected string $path;

    public function __construct()
    {
        $this->path = config('baddybugs.storage_path');
        if (!file_exists($this->path)) {
             @mkdir($this->path, 0755, true);
        }
    }

    public function push(array $entry): void
    {
        // Write to a rotating file to avoid massive single files
        // e.g. buffer-TIMESTAMP.jsonl
        // But valid JSONL is easy to append.
        $filename = $this->path . '/events.jsonl';
        file_put_contents($filename, json_encode($entry) . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    public function flush(): array
    {
        // File buffer is designed for asynchronous sending via the artisan command.
        // Therefore, we do not return items here to be sent synchronously.
        return [];
    }
}


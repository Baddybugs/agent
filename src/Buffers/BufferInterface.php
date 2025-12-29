<?php

namespace BaddyBugs\Agent\Buffers;

interface BufferInterface
{
    /**
     * Add an entry to the buffer.
     */
    public function push(array $entry): void;

    /**
     * flush the buffer contents.
     */
    public function flush(): array;
}


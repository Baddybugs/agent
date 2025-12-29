<?php

namespace BaddyBugs\Agent\Buffers;

use BaddyBugs\Agent\Sender\SenderInterface;

class MemoryBuffer implements BufferInterface
{
    protected array $buffer = [];
    protected SenderInterface $sender;

    public function __construct(SenderInterface $sender)
    {
        $this->sender = $sender;
    }

    public function push(array $entry): void
    {
        // Safety: Prevent unlimited memory growth if flush fails or loops
        if (count($this->buffer) >= 5000) {
            return;
        }

        $this->buffer[] = $entry;
        
        if (count($this->buffer) >= config('baddybugs.batch_size', 100)) {
            $this->flushAndSend();
        }
    }

    public function flush(): array
    {
        $items = $this->buffer;
        $this->buffer = [];
        return $items;
    }

    public function flushAndSend(): void
    {
        if (empty($this->buffer)) {
            return;
        }

        $this->sender->send($this->flush());
    }
    
    public function __destruct()
    {
        $this->flushAndSend();
    }
}


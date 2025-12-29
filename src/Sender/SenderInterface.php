<?php

namespace BaddyBugs\Agent\Sender;

interface SenderInterface
{
    /**
     * Send a batch of collected data.
     */
    public function send(array $batch): bool;
}


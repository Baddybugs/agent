<?php

namespace BaddyBugs\Agent\Commands;

use Illuminate\Console\Command;

class AgentCommand extends Command
{
    protected $signature = 'baddybugs:agent 
                            {--daemon : Run as a long-running daemon process} 
                            {--sleep=60 : Seconds to sleep between checks in daemon mode}';
                            
    protected $description = 'Start the BaddyBugs background agent to process buffered events.';

    public function handle(): int
    {
        if (!$this->option('daemon')) {
            $this->call('baddybugs:send');
            return 0;
        }

        $this->info("Starting BaddyBugs Agent daemon [PID: " . getmypid() . "]...");
        
        // Loop infinitely
        while (true) {
            
            // Run the send command
            // We use call so it runs in the same process, but in a real daemon we might spawn a process
            // to avoid memory leaks over long periods, or ensure we handle garbage collection.
            try {
                $this->call('baddybugs:send');
            } catch (\Throwable $e) {
                $this->error("Agent error: " . $e->getMessage());
            }

            $sleep = (int) $this->option('sleep');
            $this->info("Sleeping for {$sleep} seconds...");
            sleep($sleep);
        }

        return 0;
    }
}


<?php

namespace BaddyBugs\Agent\Support;

class ErrorFingerprinter
{
    /**
     * Generate a smart fingerprint for error grouping
     * 
     * @param \Throwable $exception
     * @return string
     */
    public function generate(\Throwable $exception): string
    {
        $class = get_class($exception);
        $message = $exception->getMessage();
        $file = $exception->getFile();
        
        // Normalize message (replace variable parts)
        $normalized = $this->normalizeMessage($message);
        
        // Use class + normalized message + relative file path
        // Exclude line number for stability across code changes
        $baseFingerprint = $class . '::' . $normalized . '::' . basename($file);
        
        return md5($baseFingerprint);
    }

    /**
     * Normalize error message - replace variable parts with placeholders
     */
    protected function normalizeMessage(string $message): string
    {
        $normalized = $message;

        // Replace numbers
        $normalized = preg_replace('/\b\d+\b/', '?', $normalized);

        // Replace quoted strings
        $normalized = preg_replace('/"[^"]*"/', '"?"', $normalized);
        $normalized = preg_replace("/'[^']*'/", "'?'", $normalized);

        // Replace UUIDs
        $normalized = preg_replace('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', 'UUID', $normalized);

        // Normalize whitespace
        $normalized = preg_replace('/\s+/', ' ', trim($normalized));

        return $normalized;
    }
}

<?php

namespace BaddyBugs\Agent\Handlers;

use BaddyBugs\Agent\BaddyBugs;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\LogRecord;
use Monolog\Level;

/**
 * BaddyBugs Monolog Handler
 * 
 * Captures all Laravel logs and sends them to BaddyBugs with:
 * - Auto-enriched context (trace_id, user_id, url, etc.)
 * - Structured logging support
 * - Pattern detection (repeated errors)
 * - Log correlation with traces
 */
class BaddyBugsLogHandler extends AbstractProcessingHandler
{
    protected BaddyBugs $baddybugs;
    
    protected array $logCounts = [];
    protected float $patternWindowStart;

    public function __construct(
        BaddyBugs $baddybugs,
        int|string|Level $level = Level::Warning,
        bool $bubble = true
    ) {
        parent::__construct($level, $bubble);
        
        $this->baddybugs = $baddybugs;
        $this->patternWindowStart = microtime(true);
    }

    /**
     * Writes the record down to the log of the implementing handler
     */
    protected function write(LogRecord $record): void
    {
        if (!$this->shouldCapture($record)) {
            return;
        }

        // Build log entry
        $logData = $this->buildLogData($record);

        // Detect patterns (repeated errors)
        if (config('baddybugs.logs_pattern_detection', true)) {
            $this->detectPatterns($logData);
        }

        // Record the log
        $this->baddybugs->record('log', $this->getLogType($record->level), $logData);
    }

    protected function shouldCapture(LogRecord $record): bool
    {
        if (!config('baddybugs.logs_enabled', true)) {
            return false;
        }

        // Check minimum level
        $minLevel = $this->getLevelValue(config('baddybugs.logs_min_level', 'warning'));
        
        return $record->level->value >= $minLevel;
    }

    protected function getLevelValue(string $levelName): int
    {
        return match (strtolower($levelName)) {
            'debug' => Level::Debug->value,
            'info' => Level::Info->value,
            'notice' => Level::Notice->value,
            'warning' => Level::Warning->value,
            'error' => Level::Error->value,
            'critical' => Level::Critical->value,
            'alert' => Level::Alert->value,
            'emergency' => Level::Emergency->value,
            default => Level::Warning->value,
        };
    }

    protected function buildLogData(LogRecord $record): array
    {
        $data = [
            'level' => $record->level->getName(),
            'level_value' => $record->level->value,
            'message' => $record->message,
            'channel' => $record->channel,
            'datetime' => $record->datetime->format('Y-m-d H:i:s.u'),
            'timestamp' => $record->datetime->getTimestamp(),
        ];

        // Add context from log record
        if (!empty($record->context)) {
            $data['context'] = $this->sanitizeContext($record->context);
        }

        // Add extra data
        if (!empty($record->extra)) {
            $data['extra'] = $record->extra;
        }

        // Auto-enrich context
        if (config('baddybugs.logs_auto_context', true)) {
            $data = $this->enrichContext($data);
        }

        // Structured logging
        if (config('baddybugs.logs_structured', true)) {
            $data['structured'] = true;
        }

        return $data;
    }

    protected function enrichContext(array $data): array
    {
        // Add trace_id for correlation
        $data['trace_id'] = $this->baddybugs->getTraceId();

        // Add request context
        if (app()->has('request')) {
            $request = request();
            
            $data['url'] = $request->fullUrl();
            $data['method'] = $request->method();
            $data['ip'] = $request->ip();
            $data['user_agent'] = $request->userAgent();
        }

        // Add user context
        if (auth()->check()) {
            $data['user_id'] = auth()->id();
            $data['user_email'] = optional(auth()->user())->email;
        }

        // Add environment
        $data['environment'] = app()->environment();

        // Add memory usage
        $data['memory_usage'] = memory_get_usage(true);
        $data['memory_usage_mb'] = round(memory_get_usage(true) / 1024 / 1024, 2);

        return $data;
    }

    protected function sanitizeContext(array $context): array
    {
        // Remove sensitive data from context
        $redactKeys = config('baddybugs.redact_keys', []);
        
        array_walk_recursive($context, function (&$value, $key) use ($redactKeys) {
            if (in_array(strtolower($key), $redactKeys)) {
                $value = '********';
            }
        });

        return $context;
    }

    protected function detectPatterns(array $logData): void
    {
        $threshold = config('baddybugs.logs_pattern_threshold', 10);
        $window = config('baddybugs.logs_pattern_window', 60); // seconds

        // Create a signature for this log
        $signature = $this->createLogSignature($logData);

        // Reset counts if window expired
        if ((microtime(true) - $this->patternWindowStart) > $window) {
            $this->logCounts = [];
            $this->patternWindowStart = microtime(true);
        }

        // Increment count
        $this->logCounts[$signature] = ($this->logCounts[$signature] ?? 0) + 1;

        // Alert if threshold exceeded
        if ($this->logCounts[$signature] === $threshold) {
            $this->baddybugs->record('log', 'pattern_detected', [
                'pattern' => $signature,
                'occurrences' => $threshold,
                'window_seconds' => $window,
                'sample_message' => $logData['message'],
                'level' => $logData['level'],
                'severity' => 'high',
            ]);
        }
    }

    protected function createLogSignature(array $logData): string
    {
        // Create signature from message and level
        $message = $logData['message'];
        
        // Normalize message (remove dynamic parts like IDs, timestamps)
        $normalized = preg_replace('/\d+/', '#', $message);
        $normalized = preg_replace('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', 'UUID', $normalized);
        
        return md5($logData['level'] . ':' . $normalized);
    }

    protected function getLogType(Level $level): string
    {
        return match ($level) {
            Level::Debug => 'debug',
            Level::Info => 'info',
            Level::Notice => 'notice',
            Level::Warning => 'warning',
            Level::Error => 'error',
            Level::Critical => 'critical',
            Level::Alert => 'alert',
            Level::Emergency => 'emergency',
            default => 'info',
        };
    }

    /**
     * Get pattern statistics
     */
    public function getPatternStats(): array
    {
        return [
            'total_patterns' => count($this->logCounts),
            'patterns' => $this->logCounts,
            'window_start' => date('Y-m-d H:i:s', (int) $this->patternWindowStart),
        ];
    }
}

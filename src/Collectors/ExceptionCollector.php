<?php

namespace BaddyBugs\Agent\Collectors;

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use BaddyBugs\Agent\Facades\BaddyBugs;
use BaddyBugs\Agent\Support\SecretsDetector;
use BaddyBugs\Agent\Support\ErrorFingerprinter;

class ExceptionCollector implements CollectorInterface
{
    protected SecretsDetector $scrubber;
    protected ErrorFingerprinter $fingerprinter;

    public function __construct()
    {
        $this->scrubber = new SecretsDetector();
        $this->fingerprinter = new ErrorFingerprinter();
    }

    public function boot(): void
    {
        Event::listen(MessageLogged::class, function (MessageLogged $event) {
            if (isset($event->context['exception']) && $event->context['exception'] instanceof \Throwable) {
                $this->collectException($event->context['exception']);
            }
        });
    }

    protected function collectException(\Throwable $exception): void
    {
        if (BaddyBugs::shouldFilterException($exception)) {
            return;
        }

        try {
            // Scrub the exception message and trace for PII and secrets
            $scrubbedMessage = $this->scrubber->scrub($exception->getMessage());
            
            // Generate smart fingerprint for grouping similar errors
            $fingerprint = $this->fingerprinter->generate($exception);

            BaddyBugs::record('exception', get_class($exception), [
                'message' => $scrubbedMessage, // Scrubbed message
                'code' => $exception->getCode(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'fingerprint' => $fingerprint,
                'source' => $this->getSourceSnippet($exception->getFile(), $exception->getLine()),
                'trace' => $this->formatTrace($exception),
                'breadcrumbs' => \BaddyBugs\Agent\Breadcrumbs::all(),
            ]);
        } catch (\Throwable $e) {
            // Fail silently - if we can't record the exception, we shouldn't cause another one
        }
    }
    
    protected function getSourceSnippet(string $path, int $line, int $radius = 10): ?array
    {
        if (!file_exists($path) || !is_readable($path)) {
            return null;
        }

        try {
            $file = new \SplFileObject($path);
            $target = $line - 1; // 0-indexed
            
            $start = max(0, $target - $radius);
            $end = $target + $radius;
            
            $code = [];
            $file->seek($start);
            
            while (!$file->eof() && $file->key() <= $end) {
                // Formatting: line_number => content
                $code[$file->key() + 1] = rtrim($file->current());
                $file->next();
            }
            
            return $code;
        } catch (\Throwable $e) {
            return null;
        }
    }

    protected function formatTrace(\Throwable $exception): array
    {
        return collect($exception->getTrace())
            ->take(30)
            ->map(function ($frame) {
                $file = $frame['file'] ?? '';
                $line = $frame['line'] ?? 0;
                
                return [
                    'file' => $file,
                    'line' => $line,
                    'function' => $frame['function'] ?? '',
                    'class' => $frame['class'] ?? '',
                    'code_snippet' => ($file && $line) ? $this->getSourceSnippet($file, (int) $line, 10) : null,
                ];
            })
            ->toArray();
    }
}


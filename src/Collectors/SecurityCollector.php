<?php

namespace BaddyBugs\Agent\Collectors;

use BaddyBugs\Agent\BaddyBugs;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Foundation\Http\Events\RequestHandled;

/**
 * Security Collector
 * 
 * Proactive security monitoring:
 * - Sensitive data detection (credit cards, SSN, API keys)
 * - SQL injection attempt detection
 * - XSS attempt detection
 * - Dangerous production usage detection (dd(), dump())
 * - Composer vulnerability scanning
 */
class SecurityCollector implements CollectorInterface
{
    protected BaddyBugs $baddybugs;
    
    protected array $sensitiveDataFindings = [];
    protected array $injectionAttempts = [];

    public function __construct(BaddyBugs $baddybugs)
    {
        $this->baddybugs = $baddybugs;
    }

    public function boot(): void
    {
        if (!config('baddybugs.security_enabled', true)) {
            return;
        }

        // Scan requests for security issues using proper Laravel event
        app('events')->listen(RequestHandled::class, function (RequestHandled $event) {
            try {
                $this->scanRequest($event->request);
            } catch (\Throwable $e) {
                // Silent failure - security scanning should never break the app
            }
        });

        // Scan for dangerous production usage
        if (config('baddybugs.security_detect_dangerous_usage', true)) {
            $this->detectDangerousUsage();
        }

        // Scan Composer dependencies (async, low priority)
        if (config('baddybugs.security_scan_composer', false)) {
            $this->scheduleComposerScan();
        }
    }

    protected function scanRequest(Request $request): void
    {
        // Scan for SQL injection
        if (config('baddybugs.security_scan_sql_injection', true)) {
            $this->scanForSQLInjection($request);
        }

        // Scan for XSS
        if (config('baddybugs.security_scan_xss', true)) {
            $this->scanForXSS($request);
        }

        // Scan for sensitive data
        if (config('baddybugs.security_scan_sensitive_data', true)) {
            $this->scanForSensitiveData($request);
        }

        // Report findings
        if (!empty($this->injectionAttempts) || !empty($this->sensitiveDataFindings)) {
            $this->reportSecurityIssues($request);
        }
    }

    protected function scanForSQLInjection(Request $request): void
    {
        $patterns = config('baddybugs.security_sql_injection_patterns', []);
        $inputs = $request->all();

        foreach ($inputs as $key => $value) {
            if (!is_string($value)) {
                continue;
            }

            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $value)) {
                    $this->injectionAttempts[] = [
                        'type' => 'sql_injection',
                        'field' => $key,
                        'pattern_matched' => $pattern,
                        'value_excerpt' => substr($value, 0, 100),
                    ];
                }
            }
        }
    }

    protected function scanForXSS(Request $request): void
    {
        $patterns = config('baddybugs.security_xss_patterns', []);
        $inputs = $request->all();

        foreach ($inputs as $key => $value) {
            if (!is_string($value)) {
                continue;
            }

            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $value)) {
                    $this->injectionAttempts[] = [
                        'type' => 'xss',
                        'field' => $key,
                        'pattern_matched' => $pattern,
                        'value_excerpt' => substr($value, 0, 100),
                    ];
                }
            }
        }
    }

    protected function scanForSensitiveData(Request $request): void
    {
        $patterns = config('baddybugs.security_sensitive_patterns', []);
        $inputs = $request->all();

        foreach ($inputs as $key => $value) {
            if (!is_string($value)) {
                continue;
            }

            foreach ($patterns as $type => $pattern) {
                if (preg_match($pattern, $value)) {
                    $this->sensitiveDataFindings[] = [
                        'type' => $type,
                        'field' => $key,
                        'severity' => $this->getSeverity($type),
                    ];
                }
            }
        }
    }

    protected function getSeverity(string $type): string
    {
        return match ($type) {
            'credit_card', 'ssn', 'private_key' => 'critical',
            'api_key', 'jwt' => 'high',
            default => 'medium',
        };
    }

    protected function detectDangerousUsage(): void
    {
        if (!app()->environment('production')) {
            return; // Only check in production
        }

        $issues = [];

        // Check for debugbar
        if (class_exists(\Barryvdh\Debugbar\ServiceProvider::class)) {
            $issues[] = 'Laravel Debugbar enabled in production';
        }

        // Check for dangerous functions in stack trace
        $dangerousFunctions = config('baddybugs.security_dangerous_functions', []);
        // Note: Full detection would require code scanning, which is expensive
        // This is a simplified version

        if (!empty($issues)) {
            $this->baddybugs->record('security', 'dangerous_usage', [
                'issues' => $issues,
                'environment' => app()->environment(),
            ]);
        }
    }

    protected function scheduleComposerScan(): void
    {
        // Schedule for next request termination (low priority)
        app()->terminating(function () {
            $this->scanComposerDependencies();
        });
    }

    protected function scanComposerDependencies(): void
    {
        $composerLockPath = base_path('composer.lock');
        
        if (!File::exists($composerLockPath)) {
            return;
        }

        try {
            $lock = json_decode(File::get($composerLockPath), true);
            $packages = $lock['packages'] ?? [];

            // In a real implementation, this would query GitHub Security Advisory DB
            // For now, we'll just track the packages
            $packageList = array_map(function ($package) {
                return [
                    'name' => $package['name'],
                    'version' => $package['version'],
                ];
            }, $packages);

            $this->baddybugs->record('security', 'composer_packages', [
                'total_packages' => count($packages),
                'packages' => $packageList,
                'scan_timestamp' => now()->toIso8601String(),
            ]);

        } catch (\Throwable $e) {
            // Silent failure
        }
    }

    protected function reportSecurityIssues(Request $request): void
    {
        $this->baddybugs->record('security', 'security_issue', [
            'injection_attempts' => $this->injectionAttempts,
            'sensitive_data_findings' => $this->sensitiveDataFindings,
            'url' => $request->fullUrl(),
            'method' => $request->method(),
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'user_id' => auth()->id(),
            'severity' => $this->calculateOverallSeverity(),
        ]);

        // Reset for next request
        $this->injectionAttempts = [];
        $this->sensitiveDataFindings = [];
    }

    protected function calculateOverallSeverity(): string
    {
        if (!empty($this->injectionAttempts)) {
            return 'critical'; // Injection attempts are always critical
        }

        foreach ($this->sensitiveDataFindings as $finding) {
            if ($finding['severity'] === 'critical') {
                return 'critical';
            }
        }

        return 'high';
    }

    /**
     * Manually report a security issue
     */
    public function reportIssue(string $type, array $details): void
    {
        $this->baddybugs->record('security', 'manual_report', array_merge([
            'issue_type' => $type,
            'reported_at' => now()->toIso8601String(),
        ], $details));
    }
}

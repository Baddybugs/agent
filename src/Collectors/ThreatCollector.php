<?php

namespace BaddyBugs\Agent\Collectors;

use BaddyBugs\Agent\BaddyBugs;

/**
 * Threat Collector
 * 
 * Detects security threats and suspicious patterns:
 * - SQL injection attempts
 * - XSS attempts
 * - Path traversal
 * - Bot detection
 * - Brute force patterns
 * - Unusual request patterns
 */
class ThreatCollector implements CollectorInterface
{
    protected BaddyBugs $baddybugs;
    protected array $threats = [];

    public function __construct(BaddyBugs $baddybugs)
    {
        $this->baddybugs = $baddybugs;
    }

    public function boot(): void
    {
        if (!config('baddybugs.threat_detection_enabled', true)) {
            return;
        }

        app()->terminating(function () {
            $this->analyzeRequest();
        });
    }

    protected function analyzeRequest(): void
    {
        $request = request();
        $threats = [];

        // SQL Injection detection
        $sqlInjectionScore = $this->detectSQLInjection($request);
        if ($sqlInjectionScore > 0) {
            $threats[] = [
                'type' => 'sql_injection',
                'severity' => $this->getSeverity($sqlInjectionScore),
                'score' => $sqlInjectionScore,
                'details' => 'Potential SQL injection attempt detected',
            ];
        }

        // XSS detection
        $xssScore = $this->detectXSS($request);
        if ($xssScore > 0) {
            $threats[] = [
                'type' => 'xss',
                'severity' => $this->getSeverity($xssScore),
                'score' => $xssScore,
                'details' => 'Potential XSS attempt detected',
            ];
        }

        // Path traversal
        $pathTraversalScore = $this->detectPathTraversal($request);
        if ($pathTraversalScore > 0) {
            $threats[] = [
                'type' => 'path_traversal',
                'severity' => $this->getSeverity($pathTraversalScore),
                'score' => $pathTraversalScore,
                'details' => 'Potential path traversal attempt detected',
            ];
        }

        // Bot detection
        $botScore = $this->detectBot($request);
        if ($botScore > 0.7) {
            $threats[] = [
                'type' => 'bot',
                'severity' => 'low',
                'score' => $botScore,
                'details' => 'Likely bot traffic detected',
            ];
        }

        // Suspicious patterns
        $suspiciousPatterns = $this->detectSuspiciousPatterns($request);
        if (!empty($suspiciousPatterns)) {
            $threats[] = [
                'type' => 'suspicious_pattern',
                'severity' => 'medium',
                'patterns' => $suspiciousPatterns,
                'details' => 'Suspicious request patterns detected',
            ];
        }

        // Record if threats detected
        if (!empty($threats)) {
            $this->baddybugs->record('security_threat', 'detection', [
                'threats_detected' => count($threats),
                'threats' => $threats,
                'url' => $request->path(),
                'method' => $request->method(),
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'user_id' => $request->user() ? $request->user()->id : null,
            ]);
        }
    }

    protected function detectSQLInjection($request): float
    {
        $score = 0;
        $patterns = [
            '/(\bUNION\b.*\bSELECT\b)/i' => 0.9,
            '/(\bOR\b\s+1\s*=\s*1)/i' => 0.8,
            '/(\bDROP\b\s+\bTABLE\b)/i' => 1.0,
            '/(\bINSERT\b\s+\bINTO\b)/i' => 0.7,
            '/(;|\-\-)/' => 0.3,
            '/(\bEXEC\b|\bEXECUTE\b)/i' => 0.8,
            '/(\bSELECT\b.*\bFROM\b.*\bWHERE\b)/i' => 0.5,
        ];

        $allInput = json_encode($request->all());

        foreach ($patterns as $pattern => $weight) {
            if (preg_match($pattern, $allInput)) {
                $score = max($score, $weight);
            }
        }

        return $score;
    }

    protected function detectXSS($request): float
    {
        $score = 0;
        $patterns = [
            '/<script[^>]*>.*?<\/script>/i' => 1.0,
            '/javascript:/i' => 0.8,
            '/on\w+\s*=\s*["\']?/i' => 0.7, // onclick, onload, etc.
            '/<iframe/i' => 0.9,
            '/eval\(/i' => 0.8,
            '/<embed/i' => 0.7,
            '/<object/i' => 0.7,
        ];

        $allInput = json_encode($request->all());

        foreach ($patterns as $pattern => $weight) {
            if (preg_match($pattern, $allInput)) {
                $score = max($score, $weight);
            }
        }

        return $score;
    }

    protected function detectPathTraversal($request): float
    {
        $score = 0;
        $patterns = [
            '/\.\.\//' => 0.9,
            '/\.\.\\\\/' => 0.9,
            '/%2e%2e%2f/i' => 1.0,
            '/%2e%2e%5c/i' => 1.0,
            '/\/etc\/passwd/' => 1.0,
            '/\/windows\/system32/i' => 1.0,
        ];

        $allInput = $request->path() . json_encode($request->all());

        foreach ($patterns as $pattern => $weight) {
            if (preg_match($pattern, $allInput)) {
                $score = max($score, $weight);
            }
        }

        return $score;
    }

    protected function detectBot($request): float
    {
        $userAgent = strtolower($request->userAgent() ?? '');
        $botScore = 0;

        // Known bot patterns
        $botPatterns = [
            'bot', 'crawler', 'spider', 'scraper', 'curl', 'wget',
            'python', 'java', 'go-http', 'axios', 'postman'
        ];

        foreach ($botPatterns as $pattern) {
            if (str_contains($userAgent, $pattern)) {
                $botScore += 0.3;
            }
        }

        // Missing common browser headers
        if (!$request->header('Accept-Language')) {
            $botScore += 0.2;
        }

        if (!$request->header('Accept-Encoding')) {
            $botScore += 0.2;
        }

        return min($botScore, 1.0);
    }

    protected function detectSuspiciousPatterns($request): array
    {
        $patterns = [];

        // Excessive parameter count
        if (count($request->all()) > 50) {
            $patterns[] = 'excessive_parameters';
        }

        // Very long parameter values
        foreach ($request->all() as $key => $value) {
            if (is_string($value) && strlen($value) > 10000) {
                $patterns[] = 'oversized_parameter';
                break;
            }
        }

        // Suspicious file extensions in URLs
        if (preg_match('/\.(exe|bat|cmd|sh|php|asp|jsp)$/i', $request->path())) {
            $patterns[] = 'suspicious_extension';
        }

        // Multiple encodings
        if (preg_match('/%[0-9a-f]{2}%[0-9a-f]{2}/i', $request->fullUrl())) {
            $patterns[] = 'multiple_encoding';
        }

        return $patterns;
    }

    protected function getSeverity(float $score): string
    {
        if ($score >= 0.8) return 'critical';
        if ($score >= 0.6) return 'high';
        if ($score >= 0.4) return 'medium';
        return 'low';
    }
}

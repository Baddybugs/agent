<?php

namespace BaddyBugs\Agent\Support;

class PiiScrubber
{
    /**
     * Patterns for detecting and scrubbing PII (Personally Identifiable Information)
     */
    private array $patterns = [
        // Credit Cards (Visa, MasterCard, Amex, Discover)
        'credit_card' => [
            'pattern' => '/\b(?:\d{4}[\s-]?){3}\d{4}\b/',
            'replacement' => '****-****-****-****',
        ],
        
        // Social Security Numbers (US format)
        'ssn' => [
            'pattern' => '/\b\d{3}-\d{2}-\d{4}\b/',
            'replacement' => '***-**-****',
        ],
        
        // Email Addresses
        'email' => [
            'pattern' => '/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Z|a-z]{2,}\b/',
            'replacement' => '***@***.***',
        ],
        
        // Phone Numbers (various formats)
        'phone' => [
            'pattern' => '/\b(?:\+?\d{1,3}[\s.-]?)?\(?\d{3}\)?[\s.-]?\d{3}[\s.-]?\d{4}\b/',
            'replacement' => '***-***-****',
        ],
        
        // IP Addresses (IPv4)
        'ip_address' => [
            'pattern' => '/\b(?:\d{1,3}\.){3}\d{1,3}\b/',
            'replacement' => '***.***.***.***',
        ],
    ];

    /**
     * Scrub PII from a string
     */
    public function scrub(?string $text): string
    {
        if (empty($text)) {
            return $text ?? '';
        }

        foreach ($this->patterns as $config) {
            $text = preg_replace($config['pattern'], $config['replacement'], $text);
        }

        return $text;
    }

    /**
     * Scrub PII from an array recursively
     */
    public function scrubArray(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($value)) {
                $data[$key] = $this->scrub($value);
            } elseif (is_array($value)) {
                $data[$key] = $this->scrubArray($value);
            }
        }

        return $data;
    }

    /**
     * Scrub specific sensitive fields from arrays
     */
    public function scrubSensitiveFields(array $data): array
    {
        $sensitiveKeys = [
            'password',
            'password_confirmation',
            'api_key',
            'api_token',
            'token',
            'secret',
            'credit_card',
            'ccv',
            'ssn',
            'social_security',
        ];

        foreach ($data as $key => $value) {
            $lowerKey = strtolower($key);
            
            foreach ($sensitiveKeys as $sensitiveKey) {
                if (str_contains($lowerKey, strtolower($sensitiveKey))) {
                    $data[$key] = '***REDACTED***';
                    continue 2;
                }
            }

            if (is_array($value)) {
                $data[$key] = $this->scrubSensitiveFields($value);
            }
        }

        return $data;
    }
}

<?php

namespace BaddyBugs\Agent\Support;

class SecretsDetector extends PiiScrubber
{
    /**
     * Specific patterns for API keys, tokens, and secrets
     */
    private array $secretPatterns = [
        // GitHub Personal Access Token
        'github_token' => [
            'pattern' => '/\bghp_[a-zA-Z0-9]{36}\b/',
            'replacement' => '***GITHUB_TOKEN***',
        ],
        
        // AWS Access Key
        'aws_access_key' => [
            'pattern' => '/\bAKIA[0-9A-Z]{16}\b/',
            'replacement' => '***AWS_ACCESS_KEY***',
        ],
        
        // Stripe API Key (Live)
        'stripe_live' => [
            'pattern' => '/\bsk_live_[a-zA-Z0-9]{24,}\b/',
            'replacement' => '***STRIPE_LIVE_KEY***',
        ],
        
        // Google API Key
        'google_api' => [
            'pattern' => '/\bAIza[a-zA-Z0-9_-]{35}\b/',
            'replacement' => '***GOOGLE_API_KEY***',
        ],
        
        // Slack Token
        'slack_token' => [
            'pattern' => '/\bxox[baprs]-[a-zA-Z0-9-]{10,}\b/',
            'replacement' => '***SLACK_TOKEN***',
        ],
        
        // Generic JWT
        'jwt' => [
            'pattern' => '/\beyJ[a-zA-Z0-9_-]*\.eyJ[a-zA-Z0-9_-]*\.[a-zA-Z0-9_-]*\b/',
            'replacement' => '***JWT_TOKEN***',
        ],
        
        // Private Keys
        'private_key' => [
            'pattern' => '/-----BEGIN [A-Z]+ PRIVATE KEY-----[\s\S]*?-----END [A-Z]+ PRIVATE KEY-----/',
            'replacement' => '***PRIVATE_KEY***',
        ],
        
        // Bearer Token
        'bearer_token' => [
            'pattern' => '/\bBearer\s+[a-zA-Z0-9\-_.~+\/]+=*\b/',
            'replacement' => 'Bearer ***TOKEN***',
        ],
        
        // Database Connection String
        'db_connection' => [
            'pattern' => '/\b(?:mysql|postgres|mongodb):\/\/[^:]+:[^@]+@[^\s]+\b/',
            'replacement' => '***://***:***@***/***',
        ],
    ];

    public function __construct()
    {
        // Get parent patterns by creating a temporary instance
        $parentScrubber = new PiiScrubber();
        $parentPatterns = (fn() => $this->patterns)->call($parentScrubber);
        
        // Merge secret patterns with parent PII patterns
        $this->patterns = array_merge($parentPatterns, $this->secretPatterns);
    }
}

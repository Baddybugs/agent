<?php

namespace BaddyBugs\Agent\Collectors;

use BaddyBugs\Agent\BaddyBugs;
use Illuminate\Support\Facades\Event;

class LLMCollector
{
    /**
     * Boot the collector.
     * This collector is passive - it provides methods to record LLM events
     * manually or via a wrapper, rather than listening to framework events directly
     * since LLM libraries (OpenAI, etc) don't emit standard Laravel events.
     */
    public function boot()
    {
        // No auto-listeners for now.
    }

    /**
     * Record an LLM request interaction.
     *
     * @param string $provider (openai, anthropic, etc)
     * @param string $model (gpt-4, claude-3, etc)
     * @param string $prompt
     * @param string $response
     * @param array $usage ['prompt_tokens' => int, 'completion_tokens' => int]
     * @param float $durationMs
     * @param float|null $costUsd
     * @param string|null $error
     */
    public function record(
        string $provider,
        string $model,
        string $prompt,
        string $response,
        array $usage,
        float $durationMs,
        ?float $costUsd = null,
        ?string $error = null
    ) {
        $totalTokens = ($usage['prompt_tokens'] ?? 0) + ($usage['completion_tokens'] ?? 0);
        
        // Simple cost estimation fallback if not provided
        if ($costUsd === null) {
            $costUsd = $this->estimateCost($provider, $model, $usage);
        }

        BaddyBugs::record('llm_request', [
            'provider' => $provider,
            'model' => $model,
            'prompt' => $prompt,
            'response' => $response,
            'usage' => [
                'prompt_tokens' => $usage['prompt_tokens'] ?? 0,
                'completion_tokens' => $usage['completion_tokens'] ?? 0,
                'total_tokens' => $totalTokens,
            ],
            'cost' => $costUsd,
            'duration_ms' => $durationMs,
            'status' => $error ? 'failed' : 'success',
            'error' => $error,
        ]);
    }

    protected function estimateCost(string $provider, string $model, array $usage): float
    {
        $rates = config('baddybugs.llm_rates', []);
        $providerKey = strtolower($provider);
        $modelKey = strtolower($model);

        $aliases = [
            'kimi' => 'moonshot',
            'moonshot' => 'moonshot',
            'x.ai' => 'xai',
            'grok' => 'xai',
            'xai' => 'xai',
            'deepseek' => 'deepseek',
            'deepseek-ai' => 'deepseek',
            'openai' => 'openai',
            'anthropic' => 'anthropic',
            'google' => 'google',
            'vertex' => 'google',
            'gemini' => 'google',
            'meta' => 'meta',
            'llama' => 'meta',
            'mistral' => 'mistral',
            'cohere' => 'cohere',
            'azure' => 'openai',
            'azure-openai' => 'openai',
            'perplexity' => 'perplexity',
            'pplx' => 'perplexity',
            'databricks' => 'databricks',
            'ai21' => 'ai21',
            'alibaba' => 'alibaba',
            'qwen' => 'alibaba',
            'microsoft' => 'microsoft',
            'phi' => 'microsoft',
            'openrouter' => 'global',
            'bedrock' => 'global',
        ];
        $providerKey = $aliases[$providerKey] ?? $providerKey;

        $candidates = [];
        if (isset($rates[$providerKey]) && is_array($rates[$providerKey])) {
            $candidates = array_merge($candidates, $rates[$providerKey]);
        }
        if (isset($rates['global']) && is_array($rates['global'])) {
            $candidates = array_merge($candidates, $rates['global']);
        }

        foreach ($candidates as $key => $rate) {
            if (stripos($modelKey, $key) !== false && isset($rate['in'], $rate['out'])) {
                return 
                    (($usage['prompt_tokens'] ?? 0) * (float) $rate['in']) + 
                    (($usage['completion_tokens'] ?? 0) * (float) $rate['out']);
            }
        }

        $fallback = [
            'gpt-4' => ['in' => 0.03 / 1000, 'out' => 0.06 / 1000],
            'gpt-4-turbo' => ['in' => 0.01 / 1000, 'out' => 0.03 / 1000],
            'gpt-4o' => ['in' => 0.005 / 1000, 'out' => 0.015 / 1000],
            'gpt-4.1' => ['in' => 0.005 / 1000, 'out' => 0.015 / 1000],
            'gpt-3.5-turbo' => ['in' => 0.0005 / 1000, 'out' => 0.0015 / 1000],
            'claude-4.5-opus' => ['in' => 0.005 / 1000, 'out' => 0.025 / 1000],
            'claude-4.5-sonnet' => ['in' => 0.003 / 1000, 'out' => 0.015 / 1000],
            'claude-4.5-haiku' => ['in' => 0.001 / 1000, 'out' => 0.005 / 1000],
            'claude-3-opus' => ['in' => 0.015 / 1000, 'out' => 0.075 / 1000],
            'claude-3-sonnet' => ['in' => 0.003 / 1000, 'out' => 0.015 / 1000],
            'claude-3-haiku' => ['in' => 0.00025 / 1000, 'out' => 0.00125 / 1000],
            'gemini-3-flash' => ['in' => 0.0005 / 1000, 'out' => 0.0030 / 1000],
            'mistral-large' => ['in' => 0.002 / 1000, 'out' => 0.006 / 1000],
            'sonar-pro' => ['in' => 0.003 / 1000, 'out' => 0.015 / 1000],
            'grok-4.1-fast' => ['in' => 0.0002 / 1000, 'out' => 0.0005 / 1000],
            'deepseek-v3' => ['in' => 0.00028 / 1000, 'out' => 0.00042 / 1000],
        ];

        foreach ($fallback as $key => $rate) {
            if (stripos($modelKey, $key) !== false) {
                return 
                    (($usage['prompt_tokens'] ?? 0) * (float) $rate['in']) + 
                    (($usage['completion_tokens'] ?? 0) * (float) $rate['out']);
            }
        }

        return 0.0;
    }
}

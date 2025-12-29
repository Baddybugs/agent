<?php

namespace BaddyBugs\Agent\Support;

use Illuminate\Support\Facades\Auth;

/**
 * Session Replay Sampler
 * 
 * Determines whether a user session should be recorded based on configuration.
 * Uses deterministic or random sampling strategies.
 */
class SessionReplaySampler
{
    /**
     * Determine if the current session should be recorded.
     *
     * @return bool
     */
    public static function shouldRecordSession(): bool
    {
        // Check if session replay is enabled
        if (!config('baddybugs.session_replay_enabled', false)) {
            return false;
        }

        // Get sampling rate (default 1%)
        $samplingRate = (float) config('baddybugs.session_replay_sampling_rate', 0.01);

        // If sampling rate is 0, never record
        if ($samplingRate <= 0) {
            return false;
        }

        // If sampling rate is 1.0, always record (development mode)
        if ($samplingRate >= 1.0) {
            return true;
        }

        // Get sampling strategy
        $strategy = config('baddybugs.session_replay_sampling_strategy', 'deterministic');

        if ($strategy === 'deterministic') {
            return static::shouldRecordDeterministic($samplingRate);
        }

        // Random strategy (default fallback)
        return static::shouldRecordRandom($samplingRate);
    }

    /**
     * Deterministic sampling based on user ID or session ID.
     * Same user always gets same sampling decision.
     *
     * @param float $samplingRate
     * @return bool
     */
    protected static function shouldRecordDeterministic(float $samplingRate): bool
    {
        // Try to use user_id for authenticated users
        $userId = Auth::id();
        
        if ($userId) {
            // Hash user_id to get consistent decision
            $hash = crc32((string) $userId);
            $normalized = ($hash % 10000) / 10000; // 0.0 to 1.0
            
            return $normalized < $samplingRate;
        }

        // Fall back to session ID for guest users
        $sessionId = session()->getId();
        
        if ($sessionId) {
            $hash = crc32($sessionId);
            $normalized = ($hash % 10000) / 10000;
            
            return $normalized < $samplingRate;
        }

        // If no user or session, fall back to random
        return static::shouldRecordRandom($samplingRate);
    }

    /**
     * Random sampling - each request independently sampled.
     *
     * @param float $samplingRate
     * @return bool
     */
    protected static function shouldRecordRandom(float $samplingRate): bool
    {
        return (mt_rand() / mt_getrandmax()) < $samplingRate;
    }

    /**
     * Get the session replay configuration for frontend.
     *
     * @param string|null $traceId
     * @return array
     */
    public static function getFrontendConfig(?string $traceId = null): array
    {
        if (!config('baddybugs.session_replay_enabled', false)) {
            return ['enabled' => false];
        }

        $shouldRecord = static::shouldRecordSession();

        return [
            'enabled' => $shouldRecord,
            'trace_id' => $traceId,
            'sampling_rate' => config('baddybugs.session_replay_sampling_rate', 0.01),
            'privacy_mode' => config('baddybugs.session_replay_privacy_mode', 'strict'),
            'block_selectors' => static::parseSelectors(config('baddybugs.session_replay_block_selectors', '')),
            'mask_text_selectors' => static::parseSelectors(config('baddybugs.session_replay_mask_text_selectors', '')),
            'record_canvas' => config('baddybugs.session_replay_record_canvas', false),
            'record_network' => config('baddybugs.session_replay_record_network', true),
            'record_console' => config('baddybugs.session_replay_record_console', true),
            'record_performance' => config('baddybugs.session_replay_record_performance', true),
            'api_key' => config('baddybugs.api_key'),
            'project_id' => config('baddybugs.project_id'),
            'endpoint' => static::getSessionReplayEndpoint(),
            'user_id' => Auth::id(),
            'user_email' => optional(Auth::user())->email,
        ];
    }

    /**
     * Parse CSS selectors from config string.
     *
     * @param string $selectors
     * @return array
     */
    protected static function parseSelectors(string $selectors): array
    {
        if (empty($selectors)) {
            return [];
        }

        // Split by comma and trim
        $parsed = array_map('trim', explode(',', $selectors));
        
        // Remove empty values
        return array_filter($parsed);
    }

    /**
     * Get the session replay endpoint URL.
     *
     * @return string
     */
    protected static function getSessionReplayEndpoint(): string
    {
        // Check for custom session replay endpoint
        $customEndpoint = config('baddybugs.session_replay_endpoint');
        
        if ($customEndpoint) {
            return $customEndpoint;
        }

        // Use main endpoint + /sessions
        $mainEndpoint = config('baddybugs.endpoint', 'https://api.baddybugs.io/v1/ingest');
        
        // Remove trailing slash if present
        $mainEndpoint = rtrim($mainEndpoint, '/');
        
        return $mainEndpoint . '/sessions';
    }

    /**
     * Manually enable session replay for current session.
     * Useful for debugging specific user sessions.
     *
     * @return void
     */
    public static function enableForCurrentSession(): void
    {
        session()->put('baddybugs_force_session_replay', true);
    }

    /**
     * Manually disable session replay for current session.
     *
     * @return void
     */
    public static function disableForCurrentSession(): void
    {
        session()->forget('baddybugs_force_session_replay');
    }

    /**
     * Check if session replay is manually forced for current session.
     *
     * @return bool
     */
    public static function isForcedForCurrentSession(): bool
    {
        return session()->get('baddybugs_force_session_replay', false) === true;
    }
}

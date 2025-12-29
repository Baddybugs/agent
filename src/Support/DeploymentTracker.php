<?php

namespace BaddyBugs\Agent\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

/**
 * Deployment Tracker
 * 
 * Detects and tracks deployments for regression risk analysis.
 * Enriches all events with deployment context.
 */
class DeploymentTracker
{
    protected ?string $deploymentHash = null;
    protected ?string $deploymentTag = null;
    protected ?string $deploymentSource = null;
    protected ?float $deploymentTimestamp = null;
    protected bool $initialized = false;

    public function __construct()
    {
        $this->initialize();
    }

    /**
     * Initialize deployment tracking
     */
    protected function initialize(): void
    {
        if ($this->initialized) {
            return;
        }

        if (!config('baddybugs.regression_analysis_enabled', true)) {
            $this->initialized = true;
            return;
        }

        // Detect deployment hash based on configured source
        $this->detectDeploymentHash();

        // Get deployment tag
        $this->deploymentTag = config('baddybugs.deployment_tag');

        // Auto-detect deployment changes
        if (config('baddybugs.auto_detect_deployment', true)) {
            $this->detectDeploymentChange();
        }

        $this->initialized = true;
    }

    /**
     * Detect deployment hash from configured source
     */
    protected function detectDeploymentHash(): void
    {
        $source = config('baddybugs.deployment_hash_source', 'env');

        switch ($source) {
            case 'env':
                $this->detectFromEnv();
                break;

            case 'header':
                $this->detectFromHeader();
                break;

            case 'git':
                $this->detectFromGit();
                break;

            case 'auto':
                // Try in order: env, header, git
                $this->detectFromEnv() 
                    || $this->detectFromHeader() 
                    || $this->detectFromGit();
                break;

            default:
                $this->detectFromEnv();
                break;
        }
    }

    /**
     * Detect deployment hash from environment variable
     */
    protected function detectFromEnv(): bool
    {
        $hash = config('baddybugs.deployment_hash') ?? env('APP_DEPLOYMENT_HASH');

        if ($hash) {
            $this->deploymentHash = $hash;
            $this->deploymentSource = 'env';
            return true;
        }

        return false;
    }

    /**
     * Detect deployment hash from request header
     */
    protected function detectFromHeader(): bool
    {
        if (!app()->has('request')) {
            return false;
        }

        $headerName = config('baddybugs.deployment_header_name', 'X-Deployment-ID');
        $hash = request()->header($headerName);

        if ($hash) {
            $this->deploymentHash = $hash;
            $this->deploymentSource = 'header';
            return true;
        }

        return false;
    }

    /**
     * Detect deployment hash from git
     */
    protected function detectFromGit(): bool
    {
        $gitHeadPath = base_path('.git/HEAD');

        if (!File::exists($gitHeadPath)) {
            return false;
        }

        try {
            $head = trim(File::get($gitHeadPath));

            // HEAD is a ref (e.g., "ref: refs/heads/main")
            if (str_starts_with($head, 'ref:')) {
                $ref = trim(substr($head, 4));
                $refPath = base_path('.git/' . $ref);

                if (File::exists($refPath)) {
                    $this->deploymentHash = trim(File::get($refPath));
                    $this->deploymentSource = 'git';
                    return true;
                }
            }

            // HEAD is directly a commit hash
            if (preg_match('/^[0-9a-f]{40}$/i', $head)) {
                $this->deploymentHash = $head;
                $this->deploymentSource = 'git';
                return true;
            }
        } catch (\Throwable $e) {
            // Silent failure
        }

        return false;
    }

    /**
     * Detect if deployment has changed (for auto-detection)
     */
    protected function detectDeploymentChange(): void
    {
        if (!$this->deploymentHash) {
            return;
        }

        try {
            $cacheKey = 'baddybugs:current_deployment_hash';
            $previousHash = Cache::get($cacheKey);

            // First deployment or hash changed
            if ($previousHash !== $this->deploymentHash) {
                $this->deploymentTimestamp = microtime(true);

                // Store new deployment hash
                Cache::forever($cacheKey, $this->deploymentHash);

                // Store deployment timestamp
                Cache::put(
                    'baddybugs:deployment_timestamp:' . $this->deploymentHash,
                    $this->deploymentTimestamp,
                    86400 * 30 // 30 days
                );

                // Mark this as a new deployment (will trigger deployment_started event)
                Cache::put('baddybugs:new_deployment', true, 60); // 1 minute flag
            } else {
                // Get timestamp of current deployment
                $this->deploymentTimestamp = Cache::get(
                    'baddybugs:deployment_timestamp:' . $this->deploymentHash
                );
            }
        } catch (\Throwable $e) {
            // Silent failure - cache may not be configured
            // Fall back to current time as deployment timestamp
            $this->deploymentTimestamp = microtime(true);
        }
    }

    /**
     * Check if this is a new deployment
     */
    public function isNewDeployment(): bool
    {
        try {
            return Cache::get('baddybugs:new_deployment', false);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Mark new deployment as processed
     */
    public function markDeploymentProcessed(): void
    {
        try {
            Cache::forget('baddybugs:new_deployment');
        } catch (\Throwable $e) {
            // Silent failure
        }
    }

    /**
     * Get deployment hash
     */
    public function getDeploymentHash(): ?string
    {
        return $this->deploymentHash;
    }

    /**
     * Get short deployment hash (first 7 chars)
     */
    public function getShortDeploymentHash(): ?string
    {
        if (!$this->deploymentHash) {
            return null;
        }

        return substr($this->deploymentHash, 0, 7);
    }

    /**
     * Get deployment tag
     */
    public function getDeploymentTag(): ?string
    {
        return $this->deploymentTag;
    }

    /**
     * Get deployment source
     */
    public function getDeploymentSource(): ?string
    {
        return $this->deploymentSource;
    }

    /**
     * Get deployment timestamp
     */
    public function getDeploymentTimestamp(): ?float
    {
        return $this->deploymentTimestamp;
    }

    /**
     * Get deployment phase (pre/post deploy marker)
     */
    public function getDeploymentPhase(): ?string
    {
        if (!config('baddybugs.tag_deployment_phase', true)) {
            return null;
        }

        if (!$this->deploymentTimestamp) {
            return 'unknown';
        }

        $warmupPeriod = config('baddybugs.regression_warmup_period', 5) * 60; // minutes to seconds
        $timeSinceDeployment = microtime(true) - $this->deploymentTimestamp;

        if ($timeSinceDeployment < $warmupPeriod) {
            return 'warmup'; // Warming up, don't use for regression
        }

        return 'post_deploy'; // Stable post-deployment
    }

    /**
     * Get all deployment context for event enrichment
     */
    public function getContext(): array
    {
        $context = [];

        if ($this->deploymentHash) {
            $context['deployment_hash'] = $this->deploymentHash;
            $context['deployment_hash_short'] = $this->getShortDeploymentHash();
            $context['deployment_source'] = $this->deploymentSource;
        }

        if ($this->deploymentTag) {
            $context['deployment_tag'] = $this->deploymentTag;
        }

        if ($this->deploymentTimestamp) {
            $context['deployment_timestamp'] = $this->deploymentTimestamp;
            $context['deployment_at'] = date('Y-m-d H:i:s', (int) $this->deploymentTimestamp);
        }

        $phase = $this->getDeploymentPhase();
        if ($phase) {
            $context['deployment_phase'] = $phase;
        }

        // Add metadata if configured
        $releasedBy = config('baddybugs.deployment_released_by');
        if ($releasedBy) {
            $context['deployment_released_by'] = $releasedBy;
        }

        $notes = config('baddybugs.deployment_notes');
        if ($notes) {
            $context['deployment_notes'] = $notes;
        }

        return $context;
    }

    /**
     * Check if deployment context is available
     */
    public function hasContext(): bool
    {
        return $this->deploymentHash !== null;
    }

    /**
     * Get deployment metadata for deployment_started event
     */
    public function getDeploymentMetadata(): array
    {
        return array_merge($this->getContext(), [
            'baseline_days' => config('baddybugs.regression_baseline_days', 7),
            'baseline_metrics' => config('baddybugs.regression_baseline_metrics', []),
            'warmup_period_minutes' => config('baddybugs.regression_warmup_period', 5),
            'auto_detected' => $this->isNewDeployment(),
        ]);
    }
}

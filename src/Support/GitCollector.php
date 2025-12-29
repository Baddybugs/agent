<?php

namespace BaddyBugs\Agent\Support;

use Illuminate\Support\Facades\File;

/**
 * Git & Deployment Correlation
 * 
 * Auto-detects and tracks:
 * - Commit hash (from .git or env)
 * - Deployment tag
 * - Deployment timestamp
 * - Deployed by
 */
class GitCollector
{
    protected ?string $commitHash = null;
    protected ?string $deploymentTag = null;
    protected ?string $deployedAt = null;
    protected ?string $deployedBy = null;
    protected bool $initialized = false;

    public function __construct()
    {
        $this->initialize();
    }

    protected function initialize(): void
    {
        if ($this->initialized) {
            return;
        }

        if (!config('baddybugs.git_correlation_enabled', true)) {
            $this->initialized = true;
            return;
        }

        // Try to get commit hash
        $this->commitHash = $this->detectCommitHash();

        // Get deployment metadata from config
        $this->deploymentTag = config('baddybugs.git_deployment_tag');
        $this->deployedAt = config('baddybugs.git_deployed_at');
        $this->deployedBy = config('baddybugs.git_deployed_by');

        $this->initialized = true;
    }

    protected function detectCommitHash(): ?string
    {
        // Priority 1: Use configured hash
        $configuredHash = config('baddybugs.git_commit_hash');
        if ($configuredHash) {
            return $configuredHash;
        }

        // Priority 2: Auto-detect from .git if enabled
        if (config('baddybugs.git_auto_detect_commit', true)) {
            return $this->readCommitFromGit();
        }

        return null;
    }

    protected function readCommitFromGit(): ?string
    {
        $gitHeadPath = base_path('.git/HEAD');

        if (!File::exists($gitHeadPath)) {
            return null;
        }

        try {
            $head = trim(File::get($gitHeadPath));

            // HEAD is a ref (e.g., "ref: refs/heads/main")
            if (str_starts_with($head, 'ref:')) {
                $ref = trim(substr($head, 4));
                $refPath = base_path('.git/' . $ref);

                if (File::exists($refPath)) {
                    return trim(File::get($refPath));
                }
            }

            // HEAD is directly a commit hash
            if (preg_match('/^[0-9a-f]{40}$/i', $head)) {
                return $head;
            }
        } catch (\Throwable $e) {
            // Silent failure
        }

        return null;
    }

    /**
     * Get commit hash
     */
    public function getCommitHash(): ?string
    {
        return $this->commitHash;
    }

    /**
     * Get short commit hash (first 7 chars)
     */
    public function getShortCommitHash(): ?string
    {
        if (!$this->commitHash) {
            return null;
        }

        return substr($this->commitHash, 0, 7);
    }

    /**
     * Get deployment tag
     */
    public function getDeploymentTag(): ?string
    {
        return $this->deploymentTag;
    }

    /**
     * Get deployed at timestamp
     */
    public function getDeployedAt(): ?string
    {
        return $this->deployedAt;
    }

    /**
     * Get deployed by
     */
    public function getDeployedBy(): ?string
    {
        return $this->deployedBy;
    }

    /**
     * Get all git context for event enrichment
     */
    public function getContext(): array
    {
        $context = [];

        if ($this->commitHash) {
            $context['git_commit'] = $this->commitHash;
            $context['git_commit_short'] = $this->getShortCommitHash();
        }

        if ($this->deploymentTag) {
            $context['deployment_tag'] = $this->deploymentTag;
        }

        if ($this->deployedAt) {
            $context['deployed_at'] = $this->deployedAt;
        }

        if ($this->deployedBy) {
            $context['deployed_by'] = $this->deployedBy;
        }

        return $context;
    }

    /**
     * Check if git context is available
     */
    public function hasContext(): bool
    {
        return $this->commitHash !== null || 
               $this->deploymentTag !== null;
    }

    /**
     * Get branch name from .git
     */
    public function getBranch(): ?string
    {
        $gitHeadPath = base_path('.git/HEAD');

        if (!File::exists($gitHeadPath)) {
            return null;
        }

        try {
            $head = trim(File::get($gitHeadPath));

            if (str_starts_with($head, 'ref: refs/heads/')) {
                return trim(substr($head, 16)); // Remove "ref: refs/heads/"
            }
        } catch (\Throwable $e) {
            // Silent failure
        }

        return null;
    }

    /**
     * Get repository info
     */
    public function getRepositoryInfo(): array
    {
        $info = [
            'commit' => $this->getCommitHash(),
            'commit_short' => $this->getShortCommitHash(),
            'branch' => $this->getBranch(),
            'deployment_tag' => $this->getDeploymentTag(),
            'deployed_at' => $this->getDeployedAt(),
            'deployed_by' => $this->getDeployedBy(),
        ];

        return array_filter($info); // Remove null values
    }
}

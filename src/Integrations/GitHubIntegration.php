<?php

namespace Baddybugs\Agent\Integrations;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;

class GitHubIntegration
{
    protected ?string $token = null;
    protected ?string $repository = null;
    protected bool $enabled = false;

    public function __construct()
    {
        $this->token = config('baddybugs.github.token');
        $this->repository = config('baddybugs.github.repository');
        $this->enabled = !empty($this->token) && !empty($this->repository);
    }

    /**
     * Check if GitHub integration is enabled
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Get the current commit information
     */
    public function getCurrentCommit(): ?array
    {
        // Try to get from git command first
        $hash = $this->getGitHash();
        if (!$hash) {
            return null;
        }

        return [
            'hash' => $hash,
            'short_hash' => substr($hash, 0, 7),
            'author' => $this->getGitAuthor(),
            'message' => $this->getGitMessage(),
            'branch' => $this->getGitBranch(),
            'timestamp' => $this->getGitTimestamp(),
        ];
    }

    /**
     * Get git commit hash
     */
    protected function getGitHash(): ?string
    {
        $hash = trim(shell_exec('git rev-parse HEAD 2>/dev/null') ?? '');
        return $hash ?: null;
    }

    /**
     * Get git author
     */
    protected function getGitAuthor(): ?string
    {
        $author = trim(shell_exec('git log -1 --format=%an 2>/dev/null') ?? '');
        return $author ?: null;
    }

    /**
     * Get git commit message
     */
    protected function getGitMessage(): ?string
    {
        $message = trim(shell_exec('git log -1 --format=%s 2>/dev/null') ?? '');
        return $message ?: null;
    }

    /**
     * Get current git branch
     */
    protected function getGitBranch(): ?string
    {
        $branch = trim(shell_exec('git rev-parse --abbrev-ref HEAD 2>/dev/null') ?? '');
        return $branch ?: null;
    }

    /**
     * Get git commit timestamp
     */
    protected function getGitTimestamp(): ?string
    {
        $timestamp = trim(shell_exec('git log -1 --format=%ci 2>/dev/null') ?? '');
        return $timestamp ?: null;
    }

    /**
     * Create a GitHub issue from an exception
     */
    public function createIssue(array $exception): ?array
    {
        if (!$this->enabled) {
            return null;
        }

        $title = sprintf('[Bug] %s: %s', 
            $exception['class'] ?? 'Exception',
            substr($exception['message'] ?? 'Unknown error', 0, 100)
        );

        $body = $this->formatIssueBody($exception);

        try {
            $response = Http::withToken($this->token)
                ->post("https://api.github.com/repos/{$this->repository}/issues", [
                    'title' => $title,
                    'body' => $body,
                    'labels' => ['bug', 'baddybugs'],
                ]);

            if ($response->successful()) {
                return [
                    'id' => $response->json('id'),
                    'number' => $response->json('number'),
                    'url' => $response->json('html_url'),
                ];
            }
        } catch (\Exception $e) {
            logger()->warning("Failed to create GitHub issue: " . $e->getMessage());
        }

        return null;
    }

    /**
     * Format issue body
     */
    protected function formatIssueBody(array $exception): string
    {
        $body = "## Exception Details\n\n";
        $body .= "**Class:** `{$exception['class']}`\n";
        $body .= "**Message:** {$exception['message']}\n";
        $body .= "**File:** `{$exception['file']}:{$exception['line']}`\n";
        $body .= "**Occurrences:** {$exception['occurrences']} times\n";
        $body .= "**First seen:** {$exception['first_seen']}\n";
        $body .= "**Last seen:** {$exception['last_seen']}\n\n";

        if (!empty($exception['stack_trace'])) {
            $body .= "## Stack Trace\n\n```\n{$exception['stack_trace']}\n```\n\n";
        }

        if (!empty($exception['context'])) {
            $body .= "## Context\n\n```json\n" . json_encode($exception['context'], JSON_PRETTY_PRINT) . "\n```\n\n";
        }

        $body .= "---\n*Created by [Baddybugs](https://baddybugs.io)*";

        return $body;
    }

    /**
     * Link exception to existing issue
     */
    public function linkToIssue(string $issueNumber, array $exception): bool
    {
        if (!$this->enabled) {
            return false;
        }

        try {
            $comment = $this->formatIssueComment($exception);

            $response = Http::withToken($this->token)
                ->post("https://api.github.com/repos/{$this->repository}/issues/{$issueNumber}/comments", [
                    'body' => $comment,
                ]);

            return $response->successful();
        } catch (\Exception $e) {
            logger()->warning("Failed to add comment to GitHub issue: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Format issue comment
     */
    protected function formatIssueComment(array $exception): string
    {
        return sprintf(
            "🔄 **New occurrence detected**\n\n" .
            "This error occurred again at %s.\n" .
            "Total occurrences: %d\n\n" .
            "---\n*Updated by Baddybugs*",
            $exception['last_seen'] ?? now()->toIso8601String(),
            $exception['occurrences'] ?? 1
        );
    }

    /**
     * Get recent releases
     */
    public function getRecentReleases(int $limit = 5): array
    {
        if (!$this->enabled) {
            return [];
        }

        $cacheKey = "github:releases:{$this->repository}";
        
        return Cache::remember($cacheKey, 300, function () use ($limit) {
            try {
                $response = Http::withToken($this->token)
                    ->get("https://api.github.com/repos/{$this->repository}/releases", [
                        'per_page' => $limit,
                    ]);

                if ($response->successful()) {
                    return array_map(function ($release) {
                        return [
                            'id' => $release['id'],
                            'tag' => $release['tag_name'],
                            'name' => $release['name'],
                            'published_at' => $release['published_at'],
                            'url' => $release['html_url'],
                        ];
                    }, $response->json());
                }
            } catch (\Exception $e) {
                logger()->warning("Failed to fetch GitHub releases: " . $e->getMessage());
            }

            return [];
        });
    }

    /**
     * Get deployment info from GitHub Actions
     */
    public function getDeploymentInfo(): ?array
    {
        // Check environment variables first (set by GitHub Actions)
        $gitHubSha = getenv('GITHUB_SHA');
        $gitHubRef = getenv('GITHUB_REF');
        $gitHubRunId = getenv('GITHUB_RUN_ID');

        if ($gitHubSha) {
            return [
                'source' => 'github_actions',
                'commit_sha' => $gitHubSha,
                'ref' => $gitHubRef,
                'run_id' => $gitHubRunId,
                'repository' => getenv('GITHUB_REPOSITORY'),
                'actor' => getenv('GITHUB_ACTOR'),
            ];
        }

        // Fallback to git command
        return $this->getCurrentCommit();
    }
}

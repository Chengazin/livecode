<?php

namespace App\Services\ProjectInfo;

use App\Services\ProjectGitService;
use RuntimeException;

class ProjectInfoGitTreeService
{
    public function __construct(
        private readonly ProjectInfoContributorService $contributorService,
        private readonly ProjectInfoDateHelper $dateHelper
    ) {
    }

    /**
     * @param list<array{
     *   name: string,
     *   is_current: bool,
     *   is_default: bool,
     *   head_hash: string,
     *   last_commit_at: string|null
     * }> $branches
     * @param array<string, array{user_id: int, name: string, email: string, role: string}> $knownByEmail
     * @return list<array{
     *   name: string,
     *   is_current: bool,
     *   is_default: bool,
     *   head_hash: string,
     *   last_commit_at: string|null,
     *   commit_count: int,
     *   dates: list<array{
     *     date: string,
     *     count: int,
     *     commits: list<array{
     *       hash: string,
     *       short_hash: string,
     *       parents: list<string>,
     *       author_name: string,
     *       author_email: string,
     *       authored_at: string,
     *       subject: string,
     *       decorations: string,
     *       contributor_key: string,
     *       user_id: int|null,
     *       role: string
     *     }>
     *   }>
     * }>
     */
    public function buildGitBranchTree(
        ProjectGitService $git,
        string $repoPath,
        array $branches,
        array $knownByEmail,
        int $commitLimit,
        string $defaultBranch
    ): array {
        $normalizedBranches = [];
        foreach ($branches as $branch) {
            $name = trim((string) ($branch['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $normalizedBranches[] = [
                'name' => $name,
                'ref_name' => trim((string) ($branch['ref_name'] ?? $name)),
                'is_current' => (bool) ($branch['is_current'] ?? false),
                'is_default' => (bool) ($branch['is_default'] ?? false) || $name === $defaultBranch,
                'head_hash' => (string) ($branch['head_hash'] ?? ''),
                'last_commit_at' => $this->dateHelper->safeParseIsoDate((string) ($branch['last_commit_at'] ?? ''))?->toIso8601String(),
            ];
        }

        if (count($normalizedBranches) === 0 && $defaultBranch !== '') {
            $normalizedBranches[] = [
                'name' => $defaultBranch,
                'ref_name' => $defaultBranch,
                'is_current' => false,
                'is_default' => true,
                'head_hash' => '',
                'last_commit_at' => null,
            ];
        }

        $tree = [];
        foreach ($normalizedBranches as $branch) {
            $branchName = (string) $branch['name'];
            $branchRef = trim((string) ($branch['ref_name'] ?? $branchName));
            if ($branchName === '') {
                continue;
            }

            try {
                $branchCommits = $git->listCommits($repoPath, $commitLimit, $branchRef !== '' ? $branchRef : $branchName);
            } catch (RuntimeException $e) {
                if ($this->isNoCommitsGitError($e->getMessage())) {
                    $branchCommits = [];
                } else {
                    throw $e;
                }
            }

            $branchCommits = $this->contributorService->enrichCommitsWithKnownContributors($branchCommits, $knownByEmail);
            $tree[] = [
                'name' => $branchName,
                'is_current' => (bool) $branch['is_current'],
                'is_default' => (bool) $branch['is_default'],
                'head_hash' => (string) $branch['head_hash'],
                'last_commit_at' => $branch['last_commit_at'],
                'commit_count' => count($branchCommits),
                'dates' => $this->groupCommitsByDate($branchCommits),
            ];
        }

        usort($tree, function (array $left, array $right): int {
            $leftPriority = ((bool) $left['is_default'] ? 2 : 0) + ((bool) $left['is_current'] ? 1 : 0);
            $rightPriority = ((bool) $right['is_default'] ? 2 : 0) + ((bool) $right['is_current'] ? 1 : 0);
            if ($leftPriority !== $rightPriority) {
                return $rightPriority <=> $leftPriority;
            }

            $leftDate = (string) ($left['last_commit_at'] ?? '');
            $rightDate = (string) ($right['last_commit_at'] ?? '');
            if ($leftDate !== $rightDate) {
                return strcmp($rightDate, $leftDate);
            }

            return strcmp((string) $left['name'], (string) $right['name']);
        });

        return $tree;
    }

    public function isNoCommitsGitError(string $message): bool
    {
        $normalized = strtolower($message);

        return str_contains($normalized, 'does not have any commits yet')
            || str_contains($normalized, 'unknown revision or path not in the working tree')
            || str_contains($normalized, 'your current branch')
            || str_contains($normalized, 'has no commits yet');
    }

    /**
     * @param list<array{
     *   hash: string,
     *   short_hash: string,
     *   parents: list<string>,
     *   author_name: string,
     *   author_email: string,
     *   authored_at: string,
     *   subject: string,
     *   decorations: string,
     *   contributor_key: string,
     *   user_id: int|null,
     *   role: string
     * }> $commits
     * @return list<array{
     *   date: string,
     *   count: int,
     *   commits: list<array{
     *     hash: string,
     *     short_hash: string,
     *     parents: list<string>,
     *     author_name: string,
     *     author_email: string,
     *     authored_at: string,
     *     subject: string,
     *     decorations: string,
     *     contributor_key: string,
     *     user_id: int|null,
     *     role: string
     *   }>
     * }>
     */
    private function groupCommitsByDate(array $commits): array
    {
        $grouped = [];
        foreach ($commits as $commit) {
            $authoredAt = $this->dateHelper->safeParseIsoDate((string) ($commit['authored_at'] ?? ''));
            $dayKey = $authoredAt?->toDateString() ?? 'unknown';

            if (! isset($grouped[$dayKey])) {
                $grouped[$dayKey] = [
                    'date' => $dayKey,
                    'count' => 0,
                    'commits' => [],
                ];
            }

            $grouped[$dayKey]['commits'][] = [
                'hash' => (string) ($commit['hash'] ?? ''),
                'short_hash' => (string) ($commit['short_hash'] ?? ''),
                'parents' => array_values(array_filter(
                    is_array($commit['parents'] ?? null) ? $commit['parents'] : [],
                    fn (mixed $parent): bool => trim((string) $parent) !== ''
                )),
                'author_name' => (string) ($commit['author_name'] ?? ''),
                'author_email' => (string) ($commit['author_email'] ?? ''),
                'authored_at' => (string) ($commit['authored_at'] ?? ''),
                'subject' => (string) ($commit['subject'] ?? ''),
                'decorations' => (string) ($commit['decorations'] ?? ''),
                'contributor_key' => (string) ($commit['contributor_key'] ?? ''),
                'user_id' => isset($commit['user_id']) ? (int) $commit['user_id'] : null,
                'role' => (string) ($commit['role'] ?? 'external'),
            ];
            $grouped[$dayKey]['count'] = count($grouped[$dayKey]['commits']);
        }

        $keys = array_keys($grouped);
        usort($keys, function (string $left, string $right): int {
            if ($left === 'unknown') {
                return 1;
            }
            if ($right === 'unknown') {
                return -1;
            }

            return strcmp($right, $left);
        });

        $result = [];
        foreach ($keys as $key) {
            $result[] = $grouped[$key];
        }

        return $result;
    }
}

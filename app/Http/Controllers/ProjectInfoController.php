<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\ProjectParticipant;
use App\Services\ProjectAccessService;
use App\Services\ProjectGitService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class ProjectInfoController extends Controller
{
    public function show(
        Request $request,
        int $projectId,
        ProjectAccessService $access,
        ProjectGitService $git
    ) {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $project = Project::query()
            ->with([
                'owner:user_id,name,email',
            ])
            ->findOrFail($projectId);

        if (! $access->userHasAccess($project, $user)) {
            return response()->json(['message' => 'Access denied.'], 403);
        }

        $periodDays = $this->resolveIntQuery($request->query('period_days'), 30, 1, 365);
        $commitLimit = $this->resolveIntQuery($request->query('commit_limit'), 200, 1, 500);

        $participants = ProjectParticipant::query()
            ->where('project_id', $project->project_id)
            ->with([
                'user:user_id,name,email',
            ])
            ->orderByDesc('joined_at')
            ->orderByDesc('participant_id')
            ->get();
        $knownContributors = $this->buildKnownContributorMaps($project, $participants);

        $defaultBranch = trim((string) ($project->forgejo_default_branch ?: 'main'));
        if ($defaultBranch === '') {
            $defaultBranch = 'main';
        }
        $repoConfigured = (bool) $project->git_enabled
            && (
                trim((string) ($project->forgejo_repo_clone_url ?? '')) !== ''
                || trim((string) ($project->forgejo_repo_full_name ?? '')) !== ''
            );

        $commits = [];
        $gitError = '';
        $gitAvailable = false;
        $gitHasChanges = false;
        $gitTree = [];
        $gitTreeError = '';
        $gitBranches = [];
        $repoPath = Storage::disk('local')->path($project->project_path);
        if ($git->isRepository($repoPath)) {
            try {
                $gitHasChanges = $git->hasChanges($repoPath);
                $commits = $git->listCommits($repoPath, $commitLimit, $defaultBranch);
                $commits = $this->enrichCommitsWithKnownContributors($commits, $knownContributors['byEmail']);
                $gitAvailable = true;
            } catch (RuntimeException $e) {
                if ($this->isNoCommitsGitError($e->getMessage())) {
                    $gitAvailable = true;
                } else {
                    $gitError = $e->getMessage();
                }
            }
        }

        if ($gitAvailable) {
            if ($repoConfigured) {
                $cloneUrl = trim((string) ($project->forgejo_repo_clone_url ?? ''));
                if ($cloneUrl !== '') {
                    try {
                        $git->fetchRemoteBranches(
                            $repoPath,
                            $cloneUrl,
                            $user->forgejo_access_token ?: null
                        );
                    } catch (RuntimeException) {
                        // Fall back to local refs if remote fetch is unavailable.
                    }
                }
            }

            try {
                $branchLimit = 24;
                $branches = $git->listBranches($repoPath, $branchLimit);
                $gitBranches = array_values(array_map(function (array $branch) use ($defaultBranch): array {
                    $name = trim((string) ($branch['name'] ?? ''));

                    return [
                        'name' => $name,
                        'ref_name' => (string) ($branch['ref_name'] ?? $name),
                        'is_current' => (bool) ($branch['is_current'] ?? false),
                        'is_default' => $name !== '' && $name === $defaultBranch,
                        'head_hash' => (string) ($branch['head_hash'] ?? ''),
                        'last_commit_at' => $this->safeParseIsoDate((string) ($branch['last_commit_at'] ?? ''))?->toIso8601String(),
                    ];
                }, $branches));

                $gitTree = $this->buildGitBranchTree(
                    $git,
                    $repoPath,
                    $gitBranches,
                    $knownContributors['byEmail'],
                    min($commitLimit, 120),
                    $defaultBranch
                );
            } catch (RuntimeException $e) {
                $gitTreeError = $e->getMessage();
            }
        }

        $effectiveRole = $access->resolveEffectiveRole($project, $user);

        return response()->json([
            'project' => [
                'project_id' => (int) $project->project_id,
                'name' => (string) $project->name,
                'description' => (string) ($project->description ?? ''),
                'owner_id' => (int) $project->owner_id,
                'project_path' => (string) $project->project_path,
                'is_public' => (bool) $project->is_public,
                'git_enabled' => (bool) $project->git_enabled,
                'forgejo_repo_full_name' => (string) ($project->forgejo_repo_full_name ?? ''),
                'forgejo_repo_clone_url' => (string) ($project->forgejo_repo_clone_url ?? ''),
                'forgejo_repo_html_url' => (string) ($project->forgejo_repo_html_url ?? ''),
                'forgejo_default_branch' => $defaultBranch,
                'forgejo_last_push_at' => $project->forgejo_last_push_at?->toIso8601String(),
                'created_at' => $project->created_at?->toIso8601String(),
                'updated_at' => $project->updated_at?->toIso8601String(),
                'owner' => $project->owner ? [
                    'user_id' => (int) $project->owner->user_id,
                    'name' => (string) $project->owner->name,
                    'email' => (string) $project->owner->email,
                ] : null,
            ],
            'permissions' => [
                'effective_role' => $effectiveRole,
                'is_owner' => $access->isOwner($project, $user),
                'can_manage_settings' => $access->canManageSettings($project, $user),
                'can_manage_participants' => $access->canManageParticipants($project, $user),
                'can_write_project' => $access->canWriteProject($project, $user),
                'can_create_pull_request' => $repoConfigured && $access->canCreatePullRequest($project, $user),
                'can_connect_repository' => $access->canManageRepository($project, $user),
                'can_push_direct' => $repoConfigured && $access->canPushDirect($project, $user),
                'can_sync' => $repoConfigured && $access->canSyncRepository($project, $user),
            ],
            'participants' => $participants->map(function (ProjectParticipant $participant): array {
                return [
                    'participant_id' => (int) $participant->participant_id,
                    'project_id' => (int) $participant->project_id,
                    'user_id' => (int) $participant->user_id,
                    'role' => ProjectParticipant::normalizeRole((string) ($participant->role ?? ProjectParticipant::ROLE_DEVELOPER)),
                    'joined_at' => $participant->joined_at?->toIso8601String(),
                    'user' => $participant->user ? [
                        'user_id' => (int) $participant->user->user_id,
                        'name' => (string) $participant->user->name,
                        'email' => (string) $participant->user->email,
                    ] : null,
                ];
            })->values(),
            'roles' => ProjectParticipant::roles(),
            'stats' => $this->buildStats(
                $knownContributors['byEmail'],
                $commits,
                $periodDays
            ),
            'history' => [
                'git' => [
                    'available' => $gitAvailable,
                    'repo_connected' => $repoConfigured,
                    'default_branch' => $defaultBranch,
                    'commit_count' => count($commits),
                    'has_changes' => $gitHasChanges,
                    'error' => $gitError,
                    'commits' => $commits,
                    'branches' => $gitBranches,
                    'tree_available' => $gitAvailable,
                    'tree_error' => $gitTreeError,
                    'branch_tree' => $gitTree,
                ],
            ],
        ]);
    }

    /**
     * @param array<string, array{user_id: int, name: string, email: string, role: string}> $knownByEmail
     * @param list<array{
     *   hash: string,
     *   short_hash: string,
     *   parents: list<string>,
     *   author_name: string,
     *   author_email: string,
     *   authored_at: string,
     *   subject: string,
     *   decorations: string,
     *   contributor_key?: string,
     *   user_id?: int|null,
     *   role?: string
     * }> $commits
     * @return array{
     *   period_days: int,
     *   from: string,
     *   to: string,
     *   total_commits: int,
     *   timeline: list<array{
     *     date: string,
     *     commits: int,
     *     total: int
     *   }>,
     *   contributors: list<array{
     *     key: string,
     *     user_id: int|null,
     *     name: string,
     *     email: string,
     *     role: string,
     *     commit_count: int,
     *     last_activity_at: string|null
     *   }>
     * }
     */
    private function buildStats(
        array $knownByEmail,
        array $commits,
        int $periodDays
    ): array
    {
        $now = CarbonImmutable::now();
        $from = $now->subDays($periodDays);
        $timelineByDate = [];
        $cursor = $from->startOfDay();
        $lastDay = $now->startOfDay();
        while ($cursor->lessThanOrEqualTo($lastDay)) {
            $dayKey = $cursor->toDateString();
            $timelineByDate[$dayKey] = [
                'date' => $dayKey,
                'commits' => 0,
                'total' => 0,
            ];
            $cursor = $cursor->addDay();
        }

        $contributors = [];
        $totalCommits = 0;
        foreach ($commits as $commit) {
            $authoredAt = $this->safeParseIsoDate((string) ($commit['authored_at'] ?? ''));
            if ($authoredAt === null || $authoredAt->lt($from)) {
                continue;
            }

            $totalCommits++;
            $email = strtolower(trim((string) ($commit['author_email'] ?? '')));
            $known = $email !== '' ? ($knownByEmail[$email] ?? null) : null;

            $key = $known !== null
                ? 'user:'.$known['user_id']
                : 'external:'.($email !== '' ? $email : strtolower((string) ($commit['author_name'] ?? 'unknown')));

            if (! isset($contributors[$key])) {
                $contributors[$key] = [
                    'key' => $key,
                    'user_id' => $known['user_id'] ?? null,
                    'name' => $known['name'] ?? (string) ($commit['author_name'] ?? 'Unknown'),
                    'email' => $known['email'] ?? (string) ($commit['author_email'] ?? ''),
                    'role' => $known['role'] ?? 'external',
                    'commit_count' => 0,
                    'last_activity_at' => null,
                ];
            }

            $contributors[$key]['commit_count']++;
            $contributors[$key]['last_activity_at'] = $this->maxIsoDate(
                $contributors[$key]['last_activity_at'],
                $authoredAt->toIso8601String()
            );

            $dayKey = $authoredAt->toDateString();
            if (! isset($timelineByDate[$dayKey])) {
                $timelineByDate[$dayKey] = [
                    'date' => $dayKey,
                    'commits' => 0,
                    'total' => 0,
                ];
            }
            $timelineByDate[$dayKey]['commits']++;
        }

        $contributors = array_values(array_filter(
            $contributors,
            fn (array $item): bool => (int) $item['commit_count'] > 0
        ));

        usort($contributors, function (array $left, array $right): int {
            $leftScore = (int) $left['commit_count'];
            $rightScore = (int) $right['commit_count'];

            if ($leftScore !== $rightScore) {
                return $rightScore <=> $leftScore;
            }

            $leftDate = (string) ($left['last_activity_at'] ?? '');
            $rightDate = (string) ($right['last_activity_at'] ?? '');

            return strcmp($rightDate, $leftDate);
        });

        ksort($timelineByDate);
        $timeline = array_values(array_map(function (array $point): array {
            $point['total'] = (int) $point['commits'];

            return $point;
        }, $timelineByDate));

        return [
            'period_days' => $periodDays,
            'from' => $from->toIso8601String(),
            'to' => $now->toIso8601String(),
            'total_commits' => $totalCommits,
            'timeline' => $timeline,
            'contributors' => $contributors,
        ];
    }

    /**
     * @param Collection<int, ProjectParticipant> $participants
     * @return array{
     *   byUserId: array<int, array{user_id: int, name: string, email: string, role: string}>,
     *   byEmail: array<string, array{user_id: int, name: string, email: string, role: string}>
     * }
     */
    private function buildKnownContributorMaps(Project $project, Collection $participants): array
    {
        $byUserId = [];
        $byEmail = [];

        $ownerId = (int) $project->owner_id;
        $ownerName = (string) ($project->owner?->name ?? 'Owner');
        $ownerEmail = (string) ($project->owner?->email ?? '');

        $byUserId[$ownerId] = [
            'user_id' => $ownerId,
            'name' => $ownerName,
            'email' => $ownerEmail,
            'role' => 'owner',
        ];

        if ($ownerEmail !== '') {
            $byEmail[strtolower($ownerEmail)] = $byUserId[$ownerId];
        }

        foreach ($participants as $participant) {
            $participantUserId = (int) $participant->user_id;
            $participantName = (string) ($participant->user?->name ?? ('User #'.$participantUserId));
            $participantEmail = (string) ($participant->user?->email ?? '');
            $participantRole = ProjectParticipant::normalizeRole((string) ($participant->role ?? ProjectParticipant::ROLE_DEVELOPER));

            $byUserId[$participantUserId] = [
                'user_id' => $participantUserId,
                'name' => $participantName,
                'email' => $participantEmail,
                'role' => $participantRole,
            ];

            if ($participantEmail !== '') {
                $byEmail[strtolower($participantEmail)] = $byUserId[$participantUserId];
            }
        }

        return [
            'byUserId' => $byUserId,
            'byEmail' => $byEmail,
        ];
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
     *   decorations: string
     * }> $commits
     * @param array<string, array{user_id: int, name: string, email: string, role: string}> $knownByEmail
     * @return list<array{
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
     * }>
     */
    private function enrichCommitsWithKnownContributors(array $commits, array $knownByEmail): array
    {
        $result = [];
        foreach ($commits as $commit) {
            $email = strtolower(trim((string) ($commit['author_email'] ?? '')));
            $known = $email !== '' ? ($knownByEmail[$email] ?? null) : null;

            $result[] = array_merge($commit, [
                'contributor_key' => $known !== null
                    ? 'user:'.$known['user_id']
                    : 'external:'.($email !== '' ? $email : strtolower((string) ($commit['author_name'] ?? 'unknown'))),
                'user_id' => $known['user_id'] ?? null,
                'role' => $known['role'] ?? 'external',
            ]);
        }

        return $result;
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
    private function buildGitBranchTree(
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
                'last_commit_at' => $this->safeParseIsoDate((string) ($branch['last_commit_at'] ?? ''))?->toIso8601String(),
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

            $branchCommits = $this->enrichCommitsWithKnownContributors($branchCommits, $knownByEmail);
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
            $authoredAt = $this->safeParseIsoDate((string) ($commit['authored_at'] ?? ''));
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

    private function resolveIntQuery(mixed $value, int $default, int $min, int $max): int
    {
        $resolved = filter_var($value, FILTER_VALIDATE_INT);
        if (! is_int($resolved)) {
            return $default;
        }

        return max($min, min($resolved, $max));
    }

    private function isNoCommitsGitError(string $message): bool
    {
        $normalized = strtolower($message);

        return str_contains($normalized, 'does not have any commits yet')
            || str_contains($normalized, 'unknown revision or path not in the working tree')
            || str_contains($normalized, 'your current branch')
            || str_contains($normalized, 'has no commits yet');
    }

    private function safeParseIsoDate(string $value): ?CarbonImmutable
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function maxIsoDate(?string $left, ?string $right): ?string
    {
        $leftDate = $this->safeParseIsoDate((string) $left);
        $rightDate = $this->safeParseIsoDate((string) $right);

        if ($leftDate === null) {
            return $rightDate?->toIso8601String();
        }

        if ($rightDate === null) {
            return $leftDate->toIso8601String();
        }

        return $leftDate->greaterThan($rightDate)
            ? $leftDate->toIso8601String()
            : $rightDate->toIso8601String();
    }
}

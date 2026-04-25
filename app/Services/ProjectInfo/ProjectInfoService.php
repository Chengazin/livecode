<?php

namespace App\Services\ProjectInfo;

use App\Models\Project;
use App\Models\ProjectParticipant;
use App\Models\User;
use App\Services\ProjectAccessService;
use App\Services\ProjectGitService;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class ProjectInfoService
{
    public function __construct(
        private readonly ProjectInfoContributorService $contributorService,
        private readonly ProjectInfoStatsService $statsService,
        private readonly ProjectInfoGitTreeService $gitTreeService,
        private readonly ProjectInfoDateHelper $dateHelper
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(
        Project $project,
        User $user,
        ProjectAccessService $access,
        ProjectGitService $git,
        int $periodDays,
        int $commitLimit
    ): array {
        $participants = ProjectParticipant::query()
            ->where('project_id', $project->project_id)
            ->with([
                'user:user_id,name,email',
            ])
            ->orderByDesc('joined_at')
            ->orderByDesc('participant_id')
            ->get();
        $knownContributors = $this->contributorService->buildKnownContributorMaps($project, $participants);

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
                $commits = $this->contributorService->enrichCommitsWithKnownContributors($commits, $knownContributors['byEmail']);
                $gitAvailable = true;
            } catch (RuntimeException $e) {
                if ($this->gitTreeService->isNoCommitsGitError($e->getMessage())) {
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
                        'last_commit_at' => $this->dateHelper->safeParseIsoDate((string) ($branch['last_commit_at'] ?? ''))?->toIso8601String(),
                    ];
                }, $branches));

                $gitTree = $this->gitTreeService->buildGitBranchTree(
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

        return [
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
                'can_manage_tasks' => $access->canManageTasks($project, $user),
                'can_take_tasks' => $access->canTakeTasks($project, $user),
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
            'stats' => $this->statsService->buildStats(
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
        ];
    }
}

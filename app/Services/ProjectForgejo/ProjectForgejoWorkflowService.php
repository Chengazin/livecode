<?php

namespace App\Services\ProjectForgejo;

use App\Models\Project;
use App\Models\User;
use App\Services\ForgejoService;
use App\Services\ProjectGitService;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class ProjectForgejoWorkflowService
{
    public function __construct(
        private readonly ProjectForgejoRepositoryHelper $repositoryHelper,
        private readonly ProjectForgejoGitErrorClassifier $gitErrors
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function connect(
        Project $project,
        User $user,
        array $data,
        ForgejoService $forgejo,
        ProjectGitService $git
    ): ProjectForgejoResult {
        try {
            if ($data['mode'] === 'create') {
                $repoName = $this->repositoryHelper->sanitizeRepoName((string) ($data['repo_name'] ?? $project->name));
                $repo = $forgejo->createRepository($user->forgejo_access_token, [
                    'name' => $repoName,
                    'private' => $data['private'] ?? true,
                    'description' => (string) ($project->description ?? ''),
                    'auto_init' => false,
                ]);
            } else {
                $reference = $this->repositoryHelper->parseRepositoryReference((string) ($data['repo_url'] ?? ''));
                if ($reference === null) {
                    return new ProjectForgejoResult(422, ['message' => 'Repository URL must be a valid HTTPS or SSH URL.']);
                }

                if (! $this->repositoryHelper->isTrustedForgejoHost($reference['host'])) {
                    return new ProjectForgejoResult(502, ['message' => 'Repository URL host does not match configured Forgejo host.']);
                }

                $repo = $forgejo->getRepository(
                    $user->forgejo_access_token,
                    $reference['owner'],
                    $reference['repo']
                );
            }
        } catch (RuntimeException $e) {
            return new ProjectForgejoResult(502, ['message' => $e->getMessage()]);
        }

        $repoFullName = (string) ($repo['full_name'] ?? '');
        $repoCloneUrl = $this->repositoryHelper->preferredRepositoryCloneUrl(
            $repoFullName,
            (string) ($repo['clone_url'] ?? '')
        );
        $permissions = is_array($repo['permissions'] ?? null) ? $repo['permissions'] : null;

        if ($repoFullName === '' || $repoCloneUrl === '') {
            return new ProjectForgejoResult(502, ['message' => 'Invalid repository response.']);
        }

        if (! $this->repositoryHelper->isTrustedForgejoCloneUrl($repoCloneUrl)) {
            return new ProjectForgejoResult(502, ['message' => 'Repository clone URL does not match configured Forgejo host.']);
        }

        if ($permissions !== null && empty($permissions['push'])) {
            return new ProjectForgejoResult(403, ['message' => 'Repository is read-only for this user.']);
        }

        $repoPath = Storage::disk('local')->path($project->project_path);
        $defaultBranch = (string) ($repo['default_branch'] ?? 'main');

        try {
            $git->initRepository($repoPath, $defaultBranch !== '' ? $defaultBranch : 'main');
            $git->setRemote($repoPath, 'origin', $repoCloneUrl);
        } catch (RuntimeException $e) {
            return new ProjectForgejoResult(502, ['message' => $e->getMessage()]);
        }

        $project->git_enabled = true;
        $project->forgejo_repo_id = (int) ($repo['id'] ?? 0) ?: null;
        $project->forgejo_repo_full_name = $repoFullName;
        $project->forgejo_repo_clone_url = $repoCloneUrl;
        $project->forgejo_repo_html_url = (string) ($repo['html_url'] ?? '');
        $project->forgejo_default_branch = $defaultBranch !== '' ? $defaultBranch : 'main';
        $project->forgejo_connected_at = now();
        $project->save();

        $fresh = $project->fresh();
        if (! $fresh) {
            return new ProjectForgejoResult(500, ['message' => 'Failed to reload project.']);
        }

        return new ProjectForgejoResult(200, $fresh->toArray());
    }

    public function save(
        Project $project,
        User $user,
        ?string $message,
        ProjectGitService $git
    ): ProjectForgejoResult {
        $commitMessage = $message;
        if (! $commitMessage) {
            $commitMessage = 'Manual save '.now()->toDateTimeString();
        }

        $repoPath = Storage::disk('local')->path($project->project_path);
        $git->initRepository($repoPath, $project->forgejo_default_branch ?: 'main');

        $author = [
            'name' => $user->name,
            'email' => $user->email,
        ];

        $pushBranch = $git->currentBranch($repoPath);
        if ($pushBranch === null) {
            return new ProjectForgejoResult(409, [
                'message' => 'Current workspace is on a detached commit. Create or switch to a branch before pushing to Forgejo.',
            ]);
        }

        $committed = $git->commitAll($repoPath, $commitMessage, $author, false);

        $remoteUrls = $this->repositoryHelper->buildRepositoryCloneUrlCandidates(
            (string) ($project->forgejo_repo_full_name ?? ''),
            (string) ($project->forgejo_repo_clone_url ?? '')
        );

        $remoteUrls = array_values(array_filter(
            $remoteUrls,
            fn (string $url): bool => $url !== '' && $this->repositoryHelper->isTrustedForgejoCloneUrl($url)
        ));

        if ($remoteUrls === []) {
            return new ProjectForgejoResult(502, ['message' => 'Repository clone URL does not match configured Forgejo host.']);
        }

        $usedRemoteUrl = '';
        $lastPushError = '';
        $pushOutput = '';
        foreach ($remoteUrls as $remoteUrl) {
            try {
                $pushOutput = $git->push(
                    $repoPath,
                    $remoteUrl,
                    $pushBranch,
                    $user->forgejo_access_token,
                    $author
                );
                $usedRemoteUrl = $remoteUrl;
                break;
            } catch (RuntimeException $e) {
                $lastPushError = $e->getMessage();

                if (! $committed && $this->gitErrors->isNoCommitRefspecError($lastPushError)) {
                    return new ProjectForgejoResult(200, ['status' => 'nothing_to_commit']);
                }

                if ($this->gitErrors->isNetworkUnreachableGitError($lastPushError)) {
                    continue;
                }

                return new ProjectForgejoResult(502, ['message' => $lastPushError]);
            }
        }

        if ($usedRemoteUrl === '') {
            return new ProjectForgejoResult(502, ['message' => $lastPushError !== '' ? $lastPushError : 'git_push_failed']);
        }

        if (! $committed && $this->gitErrors->isPushNoopOutput($pushOutput)) {
            return new ProjectForgejoResult(200, ['status' => 'nothing_to_commit']);
        }

        if ($usedRemoteUrl !== (string) $project->forgejo_repo_clone_url) {
            $project->forgejo_repo_clone_url = $usedRemoteUrl;
        }

        $project->forgejo_last_push_at = now();
        $project->save();

        return new ProjectForgejoResult(200, [
            'status' => 'pushed',
            'message' => $commitMessage,
            'branch' => $pushBranch,
            'pushed_at' => $project->forgejo_last_push_at,
        ]);
    }

    public function sync(
        Project $project,
        User $user,
        ProjectGitService $git
    ): ProjectForgejoResult {
        $repoPath = Storage::disk('local')->path($project->project_path);
        $git->initRepository($repoPath, $project->forgejo_default_branch ?: 'main');

        $author = [
            'name' => $user->name,
            'email' => $user->email,
        ];

        $syncBranch = $git->currentBranch($repoPath);
        if ($syncBranch === null) {
            return new ProjectForgejoResult(409, [
                'message' => 'Current workspace is on a detached commit. Create or switch to a branch before syncing with Forgejo.',
            ]);
        }

        $remoteUrls = $this->repositoryHelper->buildRepositoryCloneUrlCandidates(
            (string) ($project->forgejo_repo_full_name ?? ''),
            (string) ($project->forgejo_repo_clone_url ?? '')
        );

        $remoteUrls = array_values(array_filter(
            $remoteUrls,
            fn (string $url): bool => $url !== '' && $this->repositoryHelper->isTrustedForgejoCloneUrl($url)
        ));

        if ($remoteUrls === []) {
            return new ProjectForgejoResult(502, ['message' => 'Repository clone URL does not match configured Forgejo host.']);
        }

        $usedRemoteUrl = '';
        $lastSyncError = '';
        $syncOutput = '';
        foreach ($remoteUrls as $remoteUrl) {
            try {
                $syncOutput = $git->pull(
                    $repoPath,
                    $remoteUrl,
                    $syncBranch,
                    $user->forgejo_access_token,
                    $author
                );
                $usedRemoteUrl = $remoteUrl;
                break;
            } catch (RuntimeException $e) {
                $lastSyncError = $e->getMessage();

                if ($this->gitErrors->isLocalChangesGitError($lastSyncError)) {
                    return new ProjectForgejoResult(409, [
                        'message' => 'Local changes conflict with incoming updates. Commit, stash, or discard local changes and retry sync.',
                    ]);
                }

                if ($this->gitErrors->isMissingRemoteBranchGitError($lastSyncError)) {
                    return new ProjectForgejoResult(409, [
                        'message' => 'Current branch does not exist in Forgejo yet. Push it first to publish the branch.',
                    ]);
                }

                if ($this->gitErrors->isNetworkUnreachableGitError($lastSyncError)) {
                    continue;
                }

                return new ProjectForgejoResult(502, ['message' => $lastSyncError]);
            }
        }

        if ($usedRemoteUrl === '') {
            return new ProjectForgejoResult(502, ['message' => $lastSyncError !== '' ? $lastSyncError : 'git_pull_failed']);
        }

        if ($usedRemoteUrl !== (string) $project->forgejo_repo_clone_url) {
            $project->forgejo_repo_clone_url = $usedRemoteUrl;
            $project->save();
        }

        return new ProjectForgejoResult(200, [
            'status' => $this->gitErrors->isPullNoopOutput($syncOutput) ? 'up_to_date' : 'synced',
            'output' => $syncOutput,
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function pullRequest(
        Project $project,
        User $user,
        array $data,
        ProjectGitService $git,
        ForgejoService $forgejo
    ): ProjectForgejoResult {
        $repoPath = Storage::disk('local')->path($project->project_path);
        $defaultBranch = trim((string) ($project->forgejo_default_branch ?: 'main'));
        if ($defaultBranch === '') {
            $defaultBranch = 'main';
        }

        $git->initRepository($repoPath, $defaultBranch);

        $author = [
            'name' => $user->name,
            'email' => $user->email,
        ];

        $commitMessage = trim((string) ($data['message'] ?? ''));
        if ($commitMessage === '') {
            $commitMessage = 'Pull request update '.now()->toDateTimeString();
        }

        $committed = $git->commitAll($repoPath, $commitMessage, $author, false);
        $headBranch = $this->repositoryHelper->generatePullRequestBranchName((int) $user->user_id);
        $refspec = 'HEAD:refs/heads/'.$headBranch;

        $remoteUrls = $this->repositoryHelper->buildRepositoryCloneUrlCandidates(
            (string) ($project->forgejo_repo_full_name ?? ''),
            (string) ($project->forgejo_repo_clone_url ?? '')
        );

        $remoteUrls = array_values(array_filter(
            $remoteUrls,
            fn (string $url): bool => $url !== '' && $this->repositoryHelper->isTrustedForgejoCloneUrl($url)
        ));

        if ($remoteUrls === []) {
            return new ProjectForgejoResult(502, ['message' => 'Repository clone URL does not match configured Forgejo host.']);
        }

        $usedRemoteUrl = '';
        $lastPushError = '';
        $pushOutput = '';
        foreach ($remoteUrls as $remoteUrl) {
            try {
                $pushOutput = $git->pushRefspec(
                    $repoPath,
                    $remoteUrl,
                    $refspec,
                    $user->forgejo_access_token,
                    $author,
                    false
                );
                $usedRemoteUrl = $remoteUrl;
                break;
            } catch (RuntimeException $e) {
                $lastPushError = $e->getMessage();

                if (! $committed && $this->gitErrors->isNoCommitRefspecError($lastPushError)) {
                    return new ProjectForgejoResult(200, ['status' => 'nothing_to_commit']);
                }

                if ($this->gitErrors->isNetworkUnreachableGitError($lastPushError)) {
                    continue;
                }

                return new ProjectForgejoResult(502, ['message' => $lastPushError]);
            }
        }

        if ($usedRemoteUrl === '') {
            return new ProjectForgejoResult(502, ['message' => $lastPushError !== '' ? $lastPushError : 'git_push_failed']);
        }

        if (! $committed && $this->gitErrors->isPushNoopOutput($pushOutput)) {
            return new ProjectForgejoResult(200, ['status' => 'nothing_to_commit']);
        }

        $repoReference = $this->repositoryHelper->resolveRepositoryOwnerAndName($project, $usedRemoteUrl);
        if ($repoReference === null) {
            return new ProjectForgejoResult(502, ['message' => 'Invalid repository response.']);
        }

        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            $title = 'Livecode changes by '.$user->name;
        }

        $body = trim((string) ($data['body'] ?? ''));
        if ($body === '') {
            $body = 'Created from Livecode project #'.$project->project_id.'.';
        }

        try {
            $pullRequest = $forgejo->createPullRequest(
                $user->forgejo_access_token,
                $repoReference['owner'],
                $repoReference['repo'],
                [
                    'title' => $title,
                    'body' => $body,
                    'head' => $headBranch,
                    'base' => $defaultBranch,
                ]
            );
        } catch (RuntimeException $e) {
            return new ProjectForgejoResult(502, ['message' => $e->getMessage()]);
        }

        if ($usedRemoteUrl !== (string) $project->forgejo_repo_clone_url) {
            $project->forgejo_repo_clone_url = $usedRemoteUrl;
        }

        $project->forgejo_last_push_at = now();
        $project->save();

        return new ProjectForgejoResult(201, [
            'status' => 'pull_request_created',
            'number' => (int) ($pullRequest['number'] ?? 0) ?: null,
            'html_url' => (string) ($pullRequest['html_url'] ?? ''),
            'head_branch' => $headBranch,
            'base_branch' => $defaultBranch,
            'title' => (string) ($pullRequest['title'] ?? $title),
        ]);
    }
}

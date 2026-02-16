<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\ProjectSnapshot;
use App\Services\ForgejoService;
use App\Services\ProjectAccessService;
use App\Services\ProjectGitService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use RuntimeException;

class ProjectForgejoController extends Controller
{
    public function connect(
        Request $request,
        int $projectId,
        ProjectAccessService $access,
        ForgejoService $forgejo,
        ProjectGitService $git
    ) {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $project = Project::query()->findOrFail($projectId);

        if (! $access->userHasAccess($project, $user)) {
            return response()->json(['message' => 'Access denied.'], 403);
        }

        if ((int) $project->owner_id !== (int) $user->user_id) {
            return response()->json(['message' => 'Only project owner can manage Forgejo settings.'], 403);
        }

        if (! $user->forgejo_access_token) {
            return response()->json(['message' => 'Forgejo is not connected.'], 409);
        }

        $data = $request->validate([
            'mode' => ['required', 'string', Rule::in(['create', 'existing'])],
            'repo_name' => ['nullable', 'string', 'max:255'],
            'private' => ['sometimes', 'boolean'],
            'repo_url' => ['required_if:mode,existing', 'string', 'max:2048'],
        ]);

        try {
            if ($data['mode'] === 'create') {
                $repoName = $this->sanitizeRepoName($data['repo_name'] ?? $project->name);
                $repo = $forgejo->createRepository($user->forgejo_access_token, [
                    'name' => $repoName,
                    'private' => $data['private'] ?? true,
                    'description' => (string) ($project->description ?? ''),
                    'auto_init' => false,
                ]);
            } else {
                $reference = $this->parseRepositoryReference((string) ($data['repo_url'] ?? ''));
                if ($reference === null) {
                    return response()->json(['message' => 'Repository URL must be a valid HTTPS or SSH URL.'], 422);
                }

                if (! $this->isTrustedForgejoHost($reference['host'])) {
                    return response()->json(['message' => 'Repository URL host does not match configured Forgejo host.'], 502);
                }

                $repo = $forgejo->getRepository(
                    $user->forgejo_access_token,
                    $reference['owner'],
                    $reference['repo']
                );
            }
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        $repoFullName = (string) ($repo['full_name'] ?? '');
        $repoCloneUrl = $this->preferredRepositoryCloneUrl(
            $repoFullName,
            (string) ($repo['clone_url'] ?? '')
        );
        $permissions = is_array($repo['permissions'] ?? null) ? $repo['permissions'] : null;

        if ($repoFullName === '' || $repoCloneUrl === '') {
            return response()->json(['message' => 'Invalid repository response.'], 502);
        }

        if (! $this->isTrustedForgejoCloneUrl($repoCloneUrl)) {
            return response()->json(['message' => 'Repository clone URL does not match configured Forgejo host.'], 502);
        }

        if ($permissions !== null && empty($permissions['push'])) {
            return response()->json(['message' => 'Repository is read-only for this user.'], 403);
        }

        $repoPath = Storage::disk('local')->path($project->project_path);
        $defaultBranch = (string) ($repo['default_branch'] ?? 'main');

        try {
            $git->initRepository($repoPath, $defaultBranch !== '' ? $defaultBranch : 'main');
            $git->setRemote($repoPath, 'origin', $repoCloneUrl);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        $project->git_enabled = true;
        $project->forgejo_repo_id = (int) ($repo['id'] ?? 0) ?: null;
        $project->forgejo_repo_full_name = $repoFullName;
        $project->forgejo_repo_clone_url = $repoCloneUrl;
        $project->forgejo_repo_html_url = (string) ($repo['html_url'] ?? '');
        $project->forgejo_default_branch = $defaultBranch !== '' ? $defaultBranch : 'main';
        $project->forgejo_connected_at = now();
        $project->save();

        return response()->json($project->fresh());
    }

    public function save(
        Request $request,
        int $projectId,
        ProjectAccessService $access,
        ProjectGitService $git
    ) {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $project = Project::query()->findOrFail($projectId);

        if (! $access->userHasAccess($project, $user)) {
            return response()->json(['message' => 'Access denied.'], 403);
        }

        if ((int) $project->owner_id !== (int) $user->user_id) {
            return response()->json(['message' => 'Only project owner can push to Forgejo.'], 403);
        }

        if (
            ! $project->git_enabled
            || (! $project->forgejo_repo_clone_url && ! $project->forgejo_repo_full_name)
        ) {
            return response()->json(['message' => 'Forgejo repository is not configured.'], 409);
        }

        if (! $user->forgejo_access_token) {
            return response()->json(['message' => 'Forgejo is not connected.'], 409);
        }

        $data = $request->validate([
            'message' => ['nullable', 'string', 'max:255'],
            'snapshot_id' => ['nullable', 'integer'],
        ]);

        $message = $data['message'] ?? null;

        if ($data['snapshot_id'] ?? null) {
            $snapshot = ProjectSnapshot::query()
                ->where('project_id', $project->project_id)
                ->findOrFail((int) $data['snapshot_id']);

            $message = $snapshot->message ?: ('Snapshot #'.$snapshot->snapshot_id);
        }

        if (! $message) {
            $message = 'Manual save '.now()->toDateTimeString();
        }

        $repoPath = Storage::disk('local')->path($project->project_path);
        $git->initRepository($repoPath, $project->forgejo_default_branch ?: 'main');

        $author = [
            'name' => $user->name,
            'email' => $user->email,
        ];

        $committed = $git->commitAll($repoPath, $message, $author, false);

        $remoteUrls = $this->buildRepositoryCloneUrlCandidates(
            (string) ($project->forgejo_repo_full_name ?? ''),
            (string) ($project->forgejo_repo_clone_url ?? '')
        );

        $remoteUrls = array_values(array_filter(
            $remoteUrls,
            fn (string $url): bool => $url !== '' && $this->isTrustedForgejoCloneUrl($url)
        ));

        if ($remoteUrls === []) {
            return response()->json(['message' => 'Repository clone URL does not match configured Forgejo host.'], 502);
        }

        $usedRemoteUrl = '';
        $lastPushError = '';
        $pushOutput = '';
        foreach ($remoteUrls as $remoteUrl) {
            try {
                $pushOutput = $git->push(
                    $repoPath,
                    $remoteUrl,
                    $project->forgejo_default_branch ?: 'main',
                    $user->forgejo_access_token,
                    $author
                );
                $usedRemoteUrl = $remoteUrl;
                break;
            } catch (RuntimeException $e) {
                $lastPushError = $e->getMessage();

                if (! $committed && $this->isNoCommitRefspecError($lastPushError)) {
                    return response()->json(['status' => 'nothing_to_commit']);
                }

                if ($this->isNetworkUnreachableGitError($lastPushError)) {
                    continue;
                }

                return response()->json(['message' => $lastPushError], 502);
            }
        }

        if ($usedRemoteUrl === '') {
            return response()->json(['message' => $lastPushError !== '' ? $lastPushError : 'git_push_failed'], 502);
        }

        if (! $committed && $this->isPushNoopOutput($pushOutput)) {
            return response()->json(['status' => 'nothing_to_commit']);
        }

        if ($usedRemoteUrl !== (string) $project->forgejo_repo_clone_url) {
            $project->forgejo_repo_clone_url = $usedRemoteUrl;
        }

        $project->forgejo_last_push_at = now();
        $project->save();

        return response()->json([
            'status' => 'pushed',
            'message' => $message,
            'pushed_at' => $project->forgejo_last_push_at,
        ]);
    }

    public function sync(
        Request $request,
        int $projectId,
        ProjectAccessService $access,
        ProjectGitService $git
    ) {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $project = Project::query()->findOrFail($projectId);

        if (! $access->userHasAccess($project, $user)) {
            return response()->json(['message' => 'Access denied.'], 403);
        }

        if ((int) $project->owner_id !== (int) $user->user_id) {
            return response()->json(['message' => 'Only project owner can sync with Forgejo.'], 403);
        }

        if (
            ! $project->git_enabled
            || (! $project->forgejo_repo_clone_url && ! $project->forgejo_repo_full_name)
        ) {
            return response()->json(['message' => 'Forgejo repository is not configured.'], 409);
        }

        if (! $user->forgejo_access_token) {
            return response()->json(['message' => 'Forgejo is not connected.'], 409);
        }

        $repoPath = Storage::disk('local')->path($project->project_path);
        $git->initRepository($repoPath, $project->forgejo_default_branch ?: 'main');

        $author = [
            'name' => $user->name,
            'email' => $user->email,
        ];

        $remoteUrls = $this->buildRepositoryCloneUrlCandidates(
            (string) ($project->forgejo_repo_full_name ?? ''),
            (string) ($project->forgejo_repo_clone_url ?? '')
        );

        $remoteUrls = array_values(array_filter(
            $remoteUrls,
            fn (string $url): bool => $url !== '' && $this->isTrustedForgejoCloneUrl($url)
        ));

        if ($remoteUrls === []) {
            return response()->json(['message' => 'Repository clone URL does not match configured Forgejo host.'], 502);
        }

        $usedRemoteUrl = '';
        $lastSyncError = '';
        $syncOutput = '';
        foreach ($remoteUrls as $remoteUrl) {
            try {
                $syncOutput = $git->pull(
                    $repoPath,
                    $remoteUrl,
                    $project->forgejo_default_branch ?: 'main',
                    $user->forgejo_access_token,
                    $author
                );
                $usedRemoteUrl = $remoteUrl;
                break;
            } catch (RuntimeException $e) {
                $lastSyncError = $e->getMessage();

                if ($this->isLocalChangesGitError($lastSyncError)) {
                    return response()->json([
                        'message' => 'Local changes conflict with incoming updates. Commit, stash, or discard local changes and retry sync.',
                    ], 409);
                }

                if ($this->isNetworkUnreachableGitError($lastSyncError)) {
                    continue;
                }

                return response()->json(['message' => $lastSyncError], 502);
            }
        }

        if ($usedRemoteUrl === '') {
            return response()->json(['message' => $lastSyncError !== '' ? $lastSyncError : 'git_pull_failed'], 502);
        }

        if ($usedRemoteUrl !== (string) $project->forgejo_repo_clone_url) {
            $project->forgejo_repo_clone_url = $usedRemoteUrl;
            $project->save();
        }

        return response()->json([
            'status' => $this->isPullNoopOutput($syncOutput) ? 'up_to_date' : 'synced',
            'output' => $syncOutput,
        ]);
    }

    private function sanitizeRepoName(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9._-]+/', '-', $value);
        $value = preg_replace('/-+/', '-', $value);
        $value = trim($value, '-');

        return $value === '' ? 'project' : $value;
    }

    private function isTrustedForgejoCloneUrl(string $cloneUrl): bool
    {
        $repoParts = parse_url($cloneUrl);

        if (! is_array($repoParts) || ! isset($repoParts['scheme'], $repoParts['host'])) {
            return false;
        }

        $scheme = strtolower((string) $repoParts['scheme']);
        if (! in_array($scheme, ['http', 'https'], true)) {
            return false;
        }

        return $this->isTrustedForgejoHost((string) $repoParts['host']);
    }

    private function isTrustedForgejoHost(string $host): bool
    {
        $host = strtolower(trim($host));
        if ($host === '') {
            return false;
        }

        return in_array($host, $this->trustedForgejoHosts(), true);
    }

    /**
     * @return list<string>
     */
    private function trustedForgejoHosts(): array
    {
        $hosts = [];
        $urls = [
            (string) config('services.forgejo.base_url', ''),
            (string) config('services.forgejo.public_url', ''),
            (string) config('services.forgejo.git_base_url', ''),
        ];

        foreach ($urls as $url) {
            $url = trim($url);
            if ($url === '') {
                continue;
            }

            $parts = parse_url($url);
            if (! is_array($parts) || ! isset($parts['host'])) {
                continue;
            }

            $candidate = strtolower(trim((string) $parts['host']));
            if ($candidate !== '') {
                $hosts[$candidate] = true;
            }
        }

        return array_keys($hosts);
    }

    private function forgejoGitBaseUrl(): string
    {
        return rtrim((string) config('services.forgejo.git_base_url', ''), '/');
    }

    /**
     * @return list<string>
     */
    private function buildRepositoryCloneUrlCandidates(string $repoFullName, string $fallbackCloneUrl): array
    {
        $repoFullName = trim($repoFullName, " \t\n\r\0\x0B/");
        $fallbackCloneUrl = trim($fallbackCloneUrl);
        $candidates = [];

        if ($repoFullName !== '') {
            $repoPath = str_ends_with(strtolower($repoFullName), '.git')
                ? $repoFullName
                : $repoFullName.'.git';

            $bases = [
                $this->forgejoGitBaseUrl(),
                rtrim((string) config('services.forgejo.base_url', ''), '/'),
                rtrim((string) config('services.forgejo.public_url', ''), '/'),
            ];

            foreach ($bases as $base) {
                $base = trim($base);
                if ($base === '') {
                    continue;
                }

                $candidates[] = $base.'/'.ltrim($repoPath, '/');
            }
        }

        if ($fallbackCloneUrl !== '') {
            $candidates[] = $fallbackCloneUrl;
        }

        $unique = [];
        foreach ($candidates as $candidate) {
            $value = trim($candidate);
            if ($value === '' || isset($unique[$value])) {
                continue;
            }
            $unique[$value] = true;
        }

        return array_keys($unique);
    }

    private function preferredRepositoryCloneUrl(string $repoFullName, string $fallbackCloneUrl): string
    {
        $candidates = $this->buildRepositoryCloneUrlCandidates($repoFullName, $fallbackCloneUrl);

        return $candidates[0] ?? '';
    }

    private function isNoCommitRefspecError(string $message): bool
    {
        $normalized = strtolower($message);

        return str_contains($normalized, 'src refspec')
            && str_contains($normalized, 'does not match any');
    }

    private function isPushNoopOutput(string $output): bool
    {
        $normalized = strtolower(trim($output));

        if ($normalized === '') {
            return false;
        }

        return str_contains($normalized, 'everything up-to-date')
            || str_contains($normalized, 'everything up to date');
    }

    private function isPullNoopOutput(string $output): bool
    {
        $normalized = strtolower(trim($output));

        if ($normalized === '') {
            return false;
        }

        return str_contains($normalized, 'already up to date')
            || str_contains($normalized, 'already up-to-date');
    }

    private function isNetworkUnreachableGitError(string $message): bool
    {
        $normalized = strtolower($message);

        return str_contains($normalized, 'couldn\'t connect to server')
            || str_contains($normalized, 'failed to connect to')
            || str_contains($normalized, 'could not resolve host')
            || str_contains($normalized, 'name or service not known')
            || str_contains($normalized, 'getaddrinfo() thread failed to start')
            || str_contains($normalized, 'timed out')
            || str_contains($normalized, 'connection refused');
    }

    private function isLocalChangesGitError(string $message): bool
    {
        $normalized = strtolower($message);

        return str_contains($normalized, 'your local changes to the following files would be overwritten by merge')
            || str_contains($normalized, 'the following untracked working tree files would be overwritten by merge')
            || str_contains($normalized, 'please commit your changes or stash them before you merge');
    }

    /**
     * @return array{host: string, owner: string, repo: string}|null
     */
    private function parseRepositoryReference(string $value): ?array
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $host = '';
        $path = '';

        if (preg_match('/^[^@\s]+@([^:\/\s]+):(.+)$/', $value, $matches) === 1) {
            $host = (string) $matches[1];
            $path = (string) $matches[2];
        } else {
            $parts = parse_url($value);
            if (! is_array($parts) || ! isset($parts['host'], $parts['path'])) {
                return null;
            }

            $scheme = strtolower((string) ($parts['scheme'] ?? ''));
            if ($scheme !== '' && ! in_array($scheme, ['http', 'https', 'ssh'], true)) {
                return null;
            }

            $host = (string) $parts['host'];
            $path = (string) $parts['path'];
        }

        $host = strtolower(trim($host));
        $path = trim($path);
        $path = ltrim($path, '/');
        $path = rtrim($path, '/');

        if ($host === '' || $path === '') {
            return null;
        }

        if (str_ends_with(strtolower($path), '.git')) {
            $path = substr($path, 0, -4);
        }

        $parts = array_values(array_filter(explode('/', $path)));
        if (count($parts) !== 2) {
            return null;
        }

        $owner = trim((string) $parts[0]);
        $repo = trim((string) $parts[1]);

        if ($owner === '' || $repo === '') {
            return null;
        }

        return [
            'host' => $host,
            'owner' => $owner,
            'repo' => $repo,
        ];
    }
}

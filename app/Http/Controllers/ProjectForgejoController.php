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

        if (! $user->forgejo_access_token) {
            return response()->json(['message' => 'Forgejo is not connected.'], 409);
        }

        $data = $request->validate([
            'mode' => ['required', 'string', Rule::in(['create', 'existing'])],
            'repo_name' => ['nullable', 'string', 'max:255'],
            'private' => ['sometimes', 'boolean'],
            'owner' => ['required_if:mode,existing', 'string', 'max:255'],
            'repo' => ['required_if:mode,existing', 'string', 'max:255'],
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
                $repo = $forgejo->getRepository(
                    $user->forgejo_access_token,
                    $data['owner'],
                    $data['repo']
                );
            }
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        $repoFullName = (string) ($repo['full_name'] ?? '');
        $repoCloneUrl = (string) ($repo['clone_url'] ?? '');
        $permissions = is_array($repo['permissions'] ?? null) ? $repo['permissions'] : null;

        if ($repoFullName === '' || $repoCloneUrl === '') {
            return response()->json(['message' => 'Invalid repository response.'], 502);
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

        if (! $project->git_enabled || ! $project->forgejo_repo_clone_url) {
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

        if (! $committed) {
            return response()->json(['status' => 'nothing_to_commit']);
        }

        try {
            $git->push(
                $repoPath,
                $project->forgejo_repo_clone_url,
                $project->forgejo_default_branch ?: 'main',
                $user->forgejo_access_token,
                $author
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        $project->forgejo_last_push_at = now();
        $project->save();

        return response()->json([
            'status' => 'pushed',
            'message' => $message,
            'pushed_at' => $project->forgejo_last_push_at,
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
}

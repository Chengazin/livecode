<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProjectForgejo\ConnectProjectForgejoRequest;
use App\Http\Requests\ProjectForgejo\PullRequestProjectForgejoRequest;
use App\Http\Requests\ProjectForgejo\SaveProjectForgejoRequest;
use App\Models\Project;
use App\Services\ForgejoService;
use App\Services\ProjectAccessService;
use App\Services\ProjectForgejo\ProjectForgejoWorkflowService;
use App\Services\ProjectGitService;
use Illuminate\Http\Request;

class ProjectForgejoController extends Controller
{
    public function connect(
        ConnectProjectForgejoRequest $request,
        int $projectId,
        ProjectAccessService $access,
        ForgejoService $forgejo,
        ProjectGitService $git,
        ProjectForgejoWorkflowService $workflow
    ) {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $project = Project::query()->findOrFail($projectId);

        if (! $access->userHasAccess($project, $user)) {
            return response()->json(['message' => 'Access denied.'], 403);
        }

        if (! $access->canManageRepository($project, $user)) {
            return response()->json(['message' => 'Only project owner can manage Forgejo settings.'], 403);
        }

        if (! $user->forgejo_access_token) {
            return response()->json(['message' => 'Forgejo is not connected.'], 409);
        }

        $result = $workflow->connect($project, $user, $request->payload(), $forgejo, $git);

        return response()->json($result->payload, $result->status);
    }

    public function save(
        SaveProjectForgejoRequest $request,
        int $projectId,
        ProjectAccessService $access,
        ProjectGitService $git,
        ProjectForgejoWorkflowService $workflow
    ) {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $project = Project::query()->findOrFail($projectId);

        if (! $access->userHasAccess($project, $user)) {
            return response()->json(['message' => 'Access denied.'], 403);
        }

        if (! $access->canPushDirect($project, $user)) {
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

        $result = $workflow->save($project, $user, $request->message(), $git);

        return response()->json($result->payload, $result->status);
    }

    public function sync(
        Request $request,
        int $projectId,
        ProjectAccessService $access,
        ProjectGitService $git,
        ProjectForgejoWorkflowService $workflow
    ) {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $project = Project::query()->findOrFail($projectId);

        if (! $access->userHasAccess($project, $user)) {
            return response()->json(['message' => 'Access denied.'], 403);
        }

        if (! $access->canSyncRepository($project, $user)) {
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

        $result = $workflow->sync($project, $user, $git);

        return response()->json($result->payload, $result->status);
    }

    public function pullRequest(
        PullRequestProjectForgejoRequest $request,
        int $projectId,
        ProjectAccessService $access,
        ProjectGitService $git,
        ForgejoService $forgejo,
        ProjectForgejoWorkflowService $workflow
    ) {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $project = Project::query()->findOrFail($projectId);

        if (! $access->userHasAccess($project, $user)) {
            return response()->json(['message' => 'Access denied.'], 403);
        }

        if (! $access->canCreatePullRequest($project, $user)) {
            return response()->json(['message' => 'Insufficient permissions to create pull requests.'], 403);
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

        $result = $workflow->pullRequest($project, $user, $request->payload(), $git, $forgejo);

        return response()->json($result->payload, $result->status);
    }
}

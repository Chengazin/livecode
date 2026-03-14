<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\User;
use App\Services\ProjectAccessService;
use App\Services\ProjectGitService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class ProjectGitController extends Controller
{
    public function createBranch(
        Request $request,
        int $projectId,
        ProjectAccessService $access,
        ProjectGitService $git
    ): JsonResponse {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $data = $request->validate([
            'branch_name' => ['required', 'string', 'max:255'],
            'commit_hash' => ['required', 'string', 'max:255'],
        ]);

        $project = Project::query()->findOrFail($projectId);
        $guardResponse = $this->guardWritableRepository($project, $user, $access, $git);
        if ($guardResponse !== null) {
            return $guardResponse;
        }

        $repoPath = Storage::disk('local')->path($project->project_path);
        $branchName = trim((string) $data['branch_name']);
        $commitHash = trim((string) $data['commit_hash']);

        try {
            $git->validateBranchName($repoPath, $branchName);
        } catch (RuntimeException) {
            return response()->json(['message' => 'Invalid branch name.'], 422);
        }

        if ($git->branchExists($repoPath, $branchName)) {
            return response()->json(['message' => 'A branch with this name already exists.'], 422);
        }

        try {
            $git->createBranchFromCommit($repoPath, $branchName, $commitHash);
        } catch (RuntimeException $e) {
            return $this->gitFailureResponse($e);
        }

        return response()->json([
            'status' => 'ok',
            'action' => 'branch_created',
            'branch_name' => $branchName,
            'commit_hash' => $commitHash,
        ]);
    }

    public function checkoutBranch(
        Request $request,
        int $projectId,
        ProjectAccessService $access,
        ProjectGitService $git
    ): JsonResponse {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $data = $request->validate([
            'branch_name' => ['required', 'string', 'max:255'],
        ]);

        $project = Project::query()->findOrFail($projectId);
        $guardResponse = $this->guardWritableRepository($project, $user, $access, $git);
        if ($guardResponse !== null) {
            return $guardResponse;
        }

        $repoPath = Storage::disk('local')->path($project->project_path);
        $branchName = trim((string) $data['branch_name']);

        try {
            $git->checkoutBranch($repoPath, $branchName);
        } catch (RuntimeException $e) {
            return $this->gitFailureResponse($e);
        }

        return response()->json([
            'status' => 'ok',
            'action' => 'branch_checked_out',
            'branch_name' => $branchName,
        ]);
    }

    public function checkoutCommit(
        Request $request,
        int $projectId,
        ProjectAccessService $access,
        ProjectGitService $git
    ): JsonResponse {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $data = $request->validate([
            'commit_hash' => ['required', 'string', 'max:255'],
        ]);

        $project = Project::query()->findOrFail($projectId);
        $guardResponse = $this->guardWritableRepository($project, $user, $access, $git);
        if ($guardResponse !== null) {
            return $guardResponse;
        }

        $repoPath = Storage::disk('local')->path($project->project_path);
        $commitHash = trim((string) $data['commit_hash']);

        try {
            $git->checkoutCommit($repoPath, $commitHash);
        } catch (RuntimeException $e) {
            return $this->gitFailureResponse($e);
        }

        return response()->json([
            'status' => 'ok',
            'action' => 'commit_checked_out',
            'commit_hash' => $commitHash,
            'detached' => true,
        ]);
    }

    private function guardWritableRepository(
        Project $project,
        User $user,
        ProjectAccessService $access,
        ProjectGitService $git
    ): ?JsonResponse {
        if (! $access->userHasAccess($project, $user)) {
            return response()->json(['message' => 'Access denied.'], 403);
        }

        if (! $access->canWriteProject($project, $user)) {
            return response()->json(['message' => 'Project is read-only for your role.'], 403);
        }

        $repoPath = Storage::disk('local')->path($project->project_path);
        if (! $git->isRepository($repoPath)) {
            return response()->json(['message' => 'Git repository is not initialized for this project.'], 409);
        }

        if ($git->hasChanges($repoPath)) {
            return response()->json(['message' => 'Commit or stash local changes before switching project Git state.'], 409);
        }

        return null;
    }

    private function gitFailureResponse(RuntimeException $e): JsonResponse
    {
        return response()->json([
            'message' => $e->getMessage(),
        ], 422);
    }
}

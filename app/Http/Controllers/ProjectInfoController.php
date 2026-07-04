<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProjectInfo\ShowProjectInfoRequest;
use App\Models\Project;

use App\Services\ProjectAccessService;
use App\Services\ProjectGitService;
use App\Services\ProjectInfo\ProjectInfoService;

class ProjectInfoController extends Controller
{
    public function show(
        ShowProjectInfoRequest $request,
        int $projectId,
        ProjectAccessService $access,
        ProjectGitService $git,
        ProjectInfoService $infoService
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

        $payload = $infoService->build(
            $project,
            $user,
            $access,
            $git,
            $request->periodDays(),
            $request->commitLimit()
        );

        return response()->json($payload);
    }
}

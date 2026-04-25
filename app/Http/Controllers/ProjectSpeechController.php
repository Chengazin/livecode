<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProjectSpeech\TranscribeProjectSpeechRequest;
use App\Models\Project;
use App\Services\ProjectAccessService;
use App\Services\ProjectSpeech\ProjectSpeechTranscriptionService;
use Illuminate\Http\JsonResponse;

class ProjectSpeechController extends Controller
{
    public function transcribe(
        TranscribeProjectSpeechRequest $request,
        int $projectId,
        ProjectAccessService $access,
        ProjectSpeechTranscriptionService $transcriptionService
    ): JsonResponse {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $project = Project::query()->findOrFail($projectId);
        if (! $access->userHasAccess($project, $user)) {
            return response()->json(['message' => 'Access denied.'], 403);
        }

        $audio = $request->audio();
        if (! $audio || ! $audio->isValid()) {
            return response()->json([
                'message' => 'Invalid audio payload.',
                'code' => 'speech_invalid_audio',
            ], 422);
        }

        $result = $transcriptionService->transcribeLocal($audio, $request->language());

        return response()->json($result->payload, $result->status);
    }
}

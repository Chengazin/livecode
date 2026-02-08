<?php

use App\Models\Project;
use App\Models\ProjectParticipant;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('project.{projectId}', function ($user, $projectId) {
    $project = Project::query()->find($projectId);

    if (! $project) {
        return false;
    }

    if ((int) $project->owner_id === (int) $user->user_id) {
        return true;
    }

    return ProjectParticipant::query()
        ->where('project_id', $projectId)
        ->where('user_id', $user->user_id)
        ->exists();
});

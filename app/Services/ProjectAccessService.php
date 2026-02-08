<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectParticipant;
use App\Models\User;

class ProjectAccessService
{
    public function userHasAccess(Project $project, User $user): bool
    {
        if ((int) $project->owner_id === (int) $user->user_id) {
            return true;
        }

        return ProjectParticipant::query()
            ->where('project_id', $project->project_id)
            ->where('user_id', $user->user_id)
            ->exists();
    }
}

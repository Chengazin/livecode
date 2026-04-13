<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectParticipant;
use App\Models\User;

class ProjectAccessService
{
    private const ROLE_OWNER = 'owner';

    public function userHasAccess(Project $project, User $user): bool
    {
        if ($this->isOwner($project, $user)) {
            return true;
        }

        return ProjectParticipant::query()
            ->where('project_id', $project->project_id)
            ->where('user_id', $user->user_id)
            ->exists();
    }

    public function isOwner(Project $project, User $user): bool
    {
        return (int) $project->owner_id === (int) $user->user_id;
    }

    public function resolveEffectiveRole(Project $project, User $user): ?string
    {
        if ($this->isOwner($project, $user)) {
            return self::ROLE_OWNER;
        }

        return $this->participantRole($project, $user);
    }

    public function participantRole(Project $project, User $user): ?string
    {
        $participant = ProjectParticipant::query()
            ->where('project_id', $project->project_id)
            ->where('user_id', $user->user_id)
            ->first();

        if (! $participant) {
            return null;
        }

        return ProjectParticipant::normalizeRole((string) ($participant->role ?? ProjectParticipant::ROLE_DEVELOPER));
    }

    public function canCreatePullRequest(Project $project, User $user): bool
    {
        return $this->hasProjectRole($project, $user, [
            self::ROLE_OWNER,
            ProjectParticipant::ROLE_MAINTAINER,
            ProjectParticipant::ROLE_DEVELOPER,
        ]);
    }

    public function canManageSettings(Project $project, User $user): bool
    {
        return $this->hasProjectRole($project, $user, [
            self::ROLE_OWNER,
            ProjectParticipant::ROLE_MAINTAINER,
        ]);
    }

    public function canManageParticipants(Project $project, User $user): bool
    {
        return $this->hasProjectRole($project, $user, [
            self::ROLE_OWNER,
            ProjectParticipant::ROLE_MAINTAINER,
        ]);
    }

    public function canManageRepository(Project $project, User $user): bool
    {
        return $this->hasProjectRole($project, $user, [
            self::ROLE_OWNER,
        ]);
    }

    public function canPushDirect(Project $project, User $user): bool
    {
        return $this->hasProjectRole($project, $user, [
            self::ROLE_OWNER,
        ]);
    }

    public function canSyncRepository(Project $project, User $user): bool
    {
        return $this->hasProjectRole($project, $user, [
            self::ROLE_OWNER,
            ProjectParticipant::ROLE_MAINTAINER,
        ]);
    }

    public function canWriteProject(Project $project, User $user): bool
    {
        return $this->hasProjectRole($project, $user, [
            self::ROLE_OWNER,
            ProjectParticipant::ROLE_MAINTAINER,
            ProjectParticipant::ROLE_DEVELOPER,
        ]);
    }

    public function canManageTasks(Project $project, User $user): bool
    {
        return $this->hasProjectRole($project, $user, [
            self::ROLE_OWNER,
            ProjectParticipant::ROLE_MAINTAINER,
            ProjectParticipant::ROLE_DEVELOPER,
        ]);
    }

    /**
     * @param list<string> $allowedRoles
     */
    public function hasProjectRole(Project $project, User $user, array $allowedRoles): bool
    {
        $effectiveRole = $this->resolveEffectiveRole($project, $user);
        if ($effectiveRole === null) {
            return false;
        }

        return in_array($effectiveRole, $allowedRoles, true);
    }
}

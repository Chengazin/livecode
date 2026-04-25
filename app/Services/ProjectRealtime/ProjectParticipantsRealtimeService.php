<?php

namespace App\Services\ProjectRealtime;

use App\Events\ProjectRealtimeEvent;
use App\Models\Project;

class ProjectParticipantsRealtimeService
{
    /**
     * @param array<string, mixed> $payload
     */
    public function broadcastParticipantsUpdated(
        Project $project,
        int $actorUserId,
        string $action,
        array $payload = []
    ): void {
        try {
            event(new ProjectRealtimeEvent(
                (int) $project->project_id,
                $actorUserId,
                'realtime.project.participants.updated',
                array_merge(
                    [
                        'action' => $action,
                    ],
                    $payload
                )
            ));
        } catch (\Throwable) {
            // Broadcast failures should never break request handling.
        }
    }
}

<?php

namespace App\Support\ProjectTerminal;

use App\Models\ProjectTerminalSession;

class ProjectTerminalSessionSerializer
{
    /**
     * @return array<string, mixed>
     */
    public function serialize(ProjectTerminalSession $session): array
    {
        $user = $session->user;

        return [
            'terminal_session_id' => (int) $session->terminal_session_id,
            'project_id' => (int) $session->project_id,
            'user_id' => (int) $session->user_id,
            'name' => (string) $session->name,
            'shell' => (string) ($session->shell ?? ''),
            'cwd' => (string) ($session->cwd ?? '/'),
            'shared' => (bool) $session->shared,
            'status' => (string) ($session->status ?? ''),
            'last_activity_at' => $session->last_activity_at?->toISOString(),
            'closed_at' => $session->closed_at?->toISOString(),
            'created_at' => $session->created_at?->toISOString(),
            'updated_at' => $session->updated_at?->toISOString(),
            'user' => [
                'user_id' => (int) ($user->user_id ?? 0),
                'name' => (string) ($user->name ?? ''),
                'email' => (string) ($user->email ?? ''),
            ],
        ];
    }
}

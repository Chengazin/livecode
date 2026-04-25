<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\ProjectInvitation;
use App\Models\ProjectParticipant;
use App\Services\ProjectInvitation\ProjectInvitationTokenService;
use App\Services\ProjectRealtime\ProjectParticipantsRealtimeService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProjectInvitationController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $perPage = min((int) $request->query('per_page', 50), 200);
        $query = ProjectInvitation::query()
            ->whereHas('project', function ($projectQuery) use ($user) {
                $projectQuery->where('owner_id', $user->user_id);
            });

        if ($request->filled('project_id')) {
            $query->where('project_id', (int) $request->query('project_id'));
        }

        if ($request->filled('inviter_user_id')) {
            $query->where('inviter_user_id', (int) $request->query('inviter_user_id'));
        }

        return $query->paginate($perPage);
    }

    public function show(
        Request $request,
        int $invitationId
    )
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $invitation = ProjectInvitation::query()->findOrFail($invitationId);
        $project = Project::query()->findOrFail($invitation->project_id);

        if (! $this->isOwner($project, (int) $user->user_id)) {
            return response()->json(['message' => 'Access denied.'], 403);
        }

        return $invitation;
    }

    public function store(Request $request, ProjectInvitationTokenService $tokenService)
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $data = $request->validate([
            'project_id' => ['required', 'integer', 'exists:projects,project_id'],
            'invite_token' => ['sometimes', 'string', 'max:128', 'unique:project_invitations,invite_token'],
            'expires_at' => ['nullable', 'date'],
        ]);

        $project = Project::query()->findOrFail((int) $data['project_id']);
        if (! $this->isOwner($project, (int) $user->user_id)) {
            return response()->json(['message' => 'Access denied.'], 403);
        }

        $payload = [
            'project_id' => (int) $data['project_id'],
            'inviter_user_id' => (int) $user->user_id,
            'invite_token' => (string) ($data['invite_token'] ?? $tokenService->generateInviteToken()),
            'expires_at' => $data['expires_at'] ?? null,
        ];

        try {
            $invitation = ProjectInvitation::query()->create($payload);
        } catch (QueryException $e) {
            return response()->json(['message' => 'Invitation token already exists.'], 409);
        }

        return response()->json($invitation, 201);
    }

    public function accept(Request $request, ProjectParticipantsRealtimeService $participantsRealtime)
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $data = $request->validate([
            'invite_token' => ['required', 'string', 'max:128'],
        ]);

        $invitation = ProjectInvitation::query()
            ->where('invite_token', (string) $data['invite_token'])
            ->first();

        if (! $invitation) {
            return response()->json(['message' => 'Invitation not found.'], 404);
        }

        if ($invitation->expires_at !== null && $invitation->expires_at->isPast()) {
            $invitation->delete();

            return response()->json(['message' => 'Invitation expired.'], 410);
        }

        $project = Project::query()->findOrFail($invitation->project_id);
        $isOwner = (int) $project->owner_id === (int) $user->user_id;
        $alreadyParticipant = ProjectParticipant::query()
            ->where('project_id', $project->project_id)
            ->where('user_id', $user->user_id)
            ->exists();
        $participantJoined = false;

        if (! $isOwner && ! $alreadyParticipant) {
            try {
                ProjectParticipant::query()->create([
                    'project_id' => $project->project_id,
                    'user_id' => $user->user_id,
                    'joined_at' => now(),
                ]);
                $participantJoined = true;
            } catch (QueryException $e) {
                // Ignore duplicate participant race conditions.
            }
        }

        $invitation->delete();

        if ($participantJoined) {
            $participantsRealtime->broadcastParticipantsUpdated(
                $project,
                (int) $user->user_id,
                'participant_joined',
                [
                    'participant_user_id' => (int) $user->user_id,
                ]
            );
        }

        return response()->json([
            'status' => $isOwner || $alreadyParticipant ? 'already_joined' : 'joined',
            'project_id' => $project->project_id,
            'project_name' => $project->name,
        ]);
    }

    public function update(Request $request, int $invitationId)
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $invitation = ProjectInvitation::query()->findOrFail($invitationId);
        $project = Project::query()->findOrFail($invitation->project_id);

        if (! $this->isOwner($project, (int) $user->user_id)) {
            return response()->json(['message' => 'Access denied.'], 403);
        }

        $data = $request->validate([
            'invite_token' => [
                'sometimes',
                'string',
                'max:128',
                Rule::unique('project_invitations', 'invite_token')->ignore($invitation->invitation_id, 'invitation_id'),
            ],
            'expires_at' => ['nullable', 'date'],
        ]);

        $invitation->fill($data);
        $invitation->save();

        return $invitation;
    }

    public function destroy(Request $request, int $invitationId)
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $invitation = ProjectInvitation::query()->findOrFail($invitationId);
        $project = Project::query()->findOrFail($invitation->project_id);

        if (! $this->isOwner($project, (int) $user->user_id)) {
            return response()->json(['message' => 'Access denied.'], 403);
        }

        $invitation->delete();

        return response()->noContent();
    }

    private function isOwner(Project $project, int $userId): bool
    {
        return (int) $project->owner_id === $userId;
    }

}

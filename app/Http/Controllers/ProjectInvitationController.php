<?php

namespace App\Http\Controllers;

use App\Models\ProjectInvitation;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProjectInvitationController extends Controller
{
    public function index(Request $request)
    {
        $perPage = min((int) $request->query('per_page', 50), 200);
        $query = ProjectInvitation::query();

        if ($request->filled('project_id')) {
            $query->where('project_id', (int) $request->query('project_id'));
        }

        if ($request->filled('inviter_user_id')) {
            $query->where('inviter_user_id', (int) $request->query('inviter_user_id'));
        }

        return $query->paginate($perPage);
    }

    public function show(int $invitationId)
    {
        return ProjectInvitation::query()->findOrFail($invitationId);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'project_id' => ['required', 'integer', 'exists:projects,project_id'],
            'inviter_user_id' => ['required', 'integer', 'exists:users,user_id'],
            'invite_token' => ['required', 'string', 'max:128', 'unique:project_invitations,invite_token'],
            'expires_at' => ['nullable', 'date'],
            'created_at' => ['nullable', 'date'],
        ]);

        $invitation = ProjectInvitation::query()->create($data);

        return response()->json($invitation, 201);
    }

    public function update(Request $request, int $invitationId)
    {
        $invitation = ProjectInvitation::query()->findOrFail($invitationId);

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

    public function destroy(int $invitationId)
    {
        $invitation = ProjectInvitation::query()->findOrFail($invitationId);
        $invitation->delete();

        return response()->noContent();
    }
}

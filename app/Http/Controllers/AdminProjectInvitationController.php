<?php

namespace App\Http\Controllers;

use App\Models\ProjectInvitation;
use App\Services\ProjectInvitation\ProjectInvitationTokenService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminProjectInvitationController extends Controller
{
    public function index(Request $request)
    {
        $perPage = min((int) $request->query('per_page', 50), 200);
        $search = trim((string) $request->query('search', ''));
        $projectId = trim((string) $request->query('project_id', ''));
        $inviterUserId = trim((string) $request->query('inviter_user_id', ''));
        $state = trim((string) $request->query('state', ''));
        $likeOperator = config('database.default') === 'pgsql' ? 'ilike' : 'like';

        $query = ProjectInvitation::query()
            ->with([
                'project:project_id,name,owner_id,is_public',
                'project.owner:user_id,name,email',
                'inviter:user_id,name,email',
            ])
            ->orderByDesc('created_at')
            ->orderByDesc('invitation_id');

        if ($projectId !== '' && ctype_digit($projectId)) {
            $query->where('project_id', (int) $projectId);
        }

        if ($inviterUserId !== '' && ctype_digit($inviterUserId)) {
            $query->where('inviter_user_id', (int) $inviterUserId);
        }

        if ($state === 'active') {
            $query->where(function ($builder) {
                $builder
                    ->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            });
        } elseif ($state === 'expired') {
            $query->whereNotNull('expires_at')->where('expires_at', '<=', now());
        } elseif ($state === 'no_expiry') {
            $query->whereNull('expires_at');
        }

        if ($search !== '') {
            $query->where(function ($builder) use ($search, $likeOperator) {
                $builder
                    ->where('invite_token', $likeOperator, '%'.$search.'%')
                    ->orWhereHas('project', function ($projectQuery) use ($search, $likeOperator) {
                        $projectQuery->where('name', $likeOperator, '%'.$search.'%');

                        if (ctype_digit($search)) {
                            $projectQuery->orWhere('project_id', (int) $search);
                        }
                    })
                    ->orWhereHas('inviter', function ($userQuery) use ($search, $likeOperator) {
                        $userQuery
                            ->where('name', $likeOperator, '%'.$search.'%')
                            ->orWhere('email', $likeOperator, '%'.$search.'%');

                        if (ctype_digit($search)) {
                            $userQuery->orWhere('user_id', (int) $search);
                        }
                    });

                if (ctype_digit($search)) {
                    $builder->orWhere('invitation_id', (int) $search);
                }
            });
        }

        return $query->paginate($perPage);
    }

    public function show(int $invitationId)
    {
        return ProjectInvitation::query()
            ->with([
                'project:project_id,name,owner_id,is_public',
                'project.owner:user_id,name,email',
                'inviter:user_id,name,email',
            ])
            ->findOrFail($invitationId);
    }

    public function store(Request $request, ProjectInvitationTokenService $tokenService)
    {
        $data = $request->validate([
            'project_id' => ['required', 'integer', 'exists:projects,project_id'],
            'inviter_user_id' => ['required', 'integer', 'exists:users,user_id'],
            'invite_token' => ['sometimes', 'string', 'max:128', 'unique:project_invitations,invite_token'],
            'expires_at' => ['nullable', 'date'],
            'created_at' => ['nullable', 'date'],
        ]);

        $payload = [
            'project_id' => (int) $data['project_id'],
            'inviter_user_id' => (int) $data['inviter_user_id'],
            'invite_token' => (string) ($data['invite_token'] ?? $tokenService->generateInviteToken()),
            'expires_at' => $data['expires_at'] ?? null,
            'created_at' => $data['created_at'] ?? now(),
        ];

        try {
            $invitation = ProjectInvitation::query()->create($payload);
        } catch (QueryException $exception) {
            return response()->json(['message' => 'Invitation token already exists.'], 409);
        }

        return response()->json(
            $invitation->load([
                'project:project_id,name,owner_id,is_public',
                'project.owner:user_id,name,email',
                'inviter:user_id,name,email',
            ]),
            201
        );
    }

    public function update(Request $request, int $invitationId)
    {
        $invitation = ProjectInvitation::query()->findOrFail($invitationId);

        $data = $request->validate([
            'project_id' => ['sometimes', 'integer', 'exists:projects,project_id'],
            'inviter_user_id' => ['sometimes', 'integer', 'exists:users,user_id'],
            'invite_token' => [
                'sometimes',
                'string',
                'max:128',
                Rule::unique('project_invitations', 'invite_token')->ignore($invitation->invitation_id, 'invitation_id'),
            ],
            'expires_at' => ['nullable', 'date'],
            'created_at' => ['nullable', 'date'],
        ]);

        $invitation->fill($data);

        try {
            $invitation->save();
        } catch (QueryException $exception) {
            return response()->json(['message' => 'Invitation token already exists.'], 409);
        }

        return $invitation->load([
            'project:project_id,name,owner_id,is_public',
            'project.owner:user_id,name,email',
            'inviter:user_id,name,email',
        ]);
    }

    public function destroy(int $invitationId)
    {
        $invitation = ProjectInvitation::query()->findOrFail($invitationId);
        $invitation->delete();

        return response()->noContent();
    }
}

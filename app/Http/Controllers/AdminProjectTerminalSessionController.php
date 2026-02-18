<?php

namespace App\Http\Controllers;

use App\Models\ProjectTerminalSession;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminProjectTerminalSessionController extends Controller
{
    public function index(Request $request)
    {
        $perPage = min((int) $request->query('per_page', 50), 200);
        $search = trim((string) $request->query('search', ''));
        $projectId = trim((string) $request->query('project_id', ''));
        $userId = trim((string) $request->query('user_id', ''));
        $status = trim((string) $request->query('status', ''));
        $shared = $request->query('shared', null);
        $likeOperator = config('database.default') === 'pgsql' ? 'ilike' : 'like';

        $query = ProjectTerminalSession::query()
            ->with([
                'project:project_id,name,owner_id,is_public',
                'project.owner:user_id,name,email',
                'user:user_id,name,email',
            ])
            ->orderByDesc('updated_at')
            ->orderByDesc('terminal_session_id');

        if ($projectId !== '' && ctype_digit($projectId)) {
            $query->where('project_id', (int) $projectId);
        }

        if ($userId !== '' && ctype_digit($userId)) {
            $query->where('user_id', (int) $userId);
        }

        if ($status !== '') {
            $query->where('status', $status);
        }

        if ($shared !== null && $shared !== '') {
            $query->where('shared', filter_var($shared, FILTER_VALIDATE_BOOL));
        }

        if ($search !== '') {
            $query->where(function ($builder) use ($search, $likeOperator) {
                $builder
                    ->where('name', $likeOperator, '%'.$search.'%')
                    ->orWhere('shell', $likeOperator, '%'.$search.'%')
                    ->orWhere('cwd', $likeOperator, '%'.$search.'%')
                    ->orWhereHas('project', function ($projectQuery) use ($search, $likeOperator) {
                        $projectQuery->where('name', $likeOperator, '%'.$search.'%');

                        if (ctype_digit($search)) {
                            $projectQuery->orWhere('project_id', (int) $search);
                        }
                    })
                    ->orWhereHas('user', function ($userQuery) use ($search, $likeOperator) {
                        $userQuery
                            ->where('name', $likeOperator, '%'.$search.'%')
                            ->orWhere('email', $likeOperator, '%'.$search.'%');

                        if (ctype_digit($search)) {
                            $userQuery->orWhere('user_id', (int) $search);
                        }
                    });

                if (ctype_digit($search)) {
                    $builder->orWhere('terminal_session_id', (int) $search);
                }
            });
        }

        return $query->paginate($perPage);
    }

    public function show(int $terminalSessionId)
    {
        return ProjectTerminalSession::query()
            ->with([
                'project:project_id,name,owner_id,is_public',
                'project.owner:user_id,name,email',
                'user:user_id,name,email',
            ])
            ->findOrFail($terminalSessionId);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'project_id' => ['required', 'integer', 'exists:projects,project_id'],
            'user_id' => ['required', 'integer', 'exists:users,user_id'],
            'name' => ['nullable', 'string', 'max:120'],
            'shell' => ['nullable', 'string', 'max:120'],
            'cwd' => ['nullable', 'string', 'max:2048'],
            'shared' => ['nullable', 'boolean'],
            'status' => ['nullable', 'string', Rule::in(['open', 'closed'])],
            'meta' => ['nullable', 'array'],
            'last_activity_at' => ['nullable', 'date'],
            'closed_at' => ['nullable', 'date'],
        ]);

        $status = (string) ($data['status'] ?? 'open');
        $closedAt = $data['closed_at'] ?? null;

        if ($status === 'open') {
            $closedAt = null;
        } elseif ($status === 'closed' && $closedAt === null) {
            $closedAt = now();
        }

        $session = ProjectTerminalSession::query()->create([
            'project_id' => (int) $data['project_id'],
            'user_id' => (int) $data['user_id'],
            'name' => (string) ($data['name'] ?? 'Terminal'),
            'shell' => $data['shell'] ?? null,
            'cwd' => (string) ($data['cwd'] ?? '/'),
            'shared' => (bool) ($data['shared'] ?? false),
            'status' => $status,
            'meta' => $data['meta'] ?? null,
            'last_activity_at' => $data['last_activity_at'] ?? null,
            'closed_at' => $closedAt,
        ]);

        return response()->json(
            $session->load([
                'project:project_id,name,owner_id,is_public',
                'project.owner:user_id,name,email',
                'user:user_id,name,email',
            ]),
            201
        );
    }

    public function update(Request $request, int $terminalSessionId)
    {
        $session = ProjectTerminalSession::query()->findOrFail($terminalSessionId);

        $data = $request->validate([
            'project_id' => ['sometimes', 'integer', 'exists:projects,project_id'],
            'user_id' => ['sometimes', 'integer', 'exists:users,user_id'],
            'name' => ['sometimes', 'string', 'max:120'],
            'shell' => ['nullable', 'string', 'max:120'],
            'cwd' => ['sometimes', 'string', 'max:2048'],
            'shared' => ['sometimes', 'boolean'],
            'status' => ['sometimes', 'string', Rule::in(['open', 'closed'])],
            'meta' => ['nullable', 'array'],
            'last_activity_at' => ['nullable', 'date'],
            'closed_at' => ['nullable', 'date'],
        ]);

        $session->fill($data);

        if (array_key_exists('status', $data)) {
            if ($data['status'] === 'open') {
                $session->closed_at = null;
            } elseif ($data['status'] === 'closed' && ! array_key_exists('closed_at', $data) && $session->closed_at === null) {
                $session->closed_at = now();
            }
        }

        $session->save();

        return $session->load([
            'project:project_id,name,owner_id,is_public',
            'project.owner:user_id,name,email',
            'user:user_id,name,email',
        ]);
    }

    public function destroy(int $terminalSessionId)
    {
        $session = ProjectTerminalSession::query()->findOrFail($terminalSessionId);
        $session->delete();

        return response()->noContent();
    }
}

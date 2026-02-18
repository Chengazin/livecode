<?php

namespace App\Http\Controllers;

use App\Models\ProjectSnapshot;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminProjectSnapshotController extends Controller
{
    public function index(Request $request)
    {
        $perPage = min((int) $request->query('per_page', 50), 200);
        $search = trim((string) $request->query('search', ''));
        $projectId = trim((string) $request->query('project_id', ''));
        $authorUserId = trim((string) $request->query('author_user_id', ''));
        $likeOperator = config('database.default') === 'pgsql' ? 'ilike' : 'like';

        $query = ProjectSnapshot::query()
            ->with([
                'project:project_id,name,owner_id,is_public',
                'project.owner:user_id,name,email',
                'author:user_id,name,email',
            ])
            ->orderByDesc('created_at')
            ->orderByDesc('snapshot_id');

        if ($projectId !== '' && ctype_digit($projectId)) {
            $query->where('project_id', (int) $projectId);
        }

        if ($authorUserId !== '' && ctype_digit($authorUserId)) {
            $query->where('author_user_id', (int) $authorUserId);
        }

        if ($search !== '') {
            $query->where(function ($builder) use ($search, $likeOperator) {
                $builder
                    ->where('snapshot_hash', $likeOperator, '%'.$search.'%')
                    ->orWhere('message', $likeOperator, '%'.$search.'%')
                    ->orWhere('snapshot_path', $likeOperator, '%'.$search.'%')
                    ->orWhereHas('project', function ($projectQuery) use ($search, $likeOperator) {
                        $projectQuery->where('name', $likeOperator, '%'.$search.'%');

                        if (ctype_digit($search)) {
                            $projectQuery->orWhere('project_id', (int) $search);
                        }
                    })
                    ->orWhereHas('author', function ($userQuery) use ($search, $likeOperator) {
                        $userQuery
                            ->where('name', $likeOperator, '%'.$search.'%')
                            ->orWhere('email', $likeOperator, '%'.$search.'%');

                        if (ctype_digit($search)) {
                            $userQuery->orWhere('user_id', (int) $search);
                        }
                    });

                if (ctype_digit($search)) {
                    $builder->orWhere('snapshot_id', (int) $search);
                }
            });
        }

        return $query->paginate($perPage);
    }

    public function show(int $snapshotId)
    {
        return ProjectSnapshot::query()
            ->with([
                'project:project_id,name,owner_id,is_public',
                'project.owner:user_id,name,email',
                'author:user_id,name,email',
            ])
            ->findOrFail($snapshotId);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'project_id' => ['required', 'integer', 'exists:projects,project_id'],
            'snapshot_hash' => ['required', 'string', 'size:64', 'unique:project_snapshots,snapshot_hash'],
            'author_user_id' => ['nullable', 'integer', 'exists:users,user_id'],
            'message' => ['nullable', 'string'],
            'snapshot_path' => ['required', 'string', 'max:2048'],
            'size_bytes' => ['nullable', 'integer', 'min:0'],
            'created_at' => ['nullable', 'date'],
        ]);

        try {
            $snapshot = ProjectSnapshot::query()->create([
                'project_id' => (int) $data['project_id'],
                'snapshot_hash' => (string) $data['snapshot_hash'],
                'author_user_id' => $data['author_user_id'] ?? null,
                'message' => $data['message'] ?? null,
                'snapshot_path' => (string) $data['snapshot_path'],
                'size_bytes' => $data['size_bytes'] ?? null,
                'created_at' => $data['created_at'] ?? now(),
            ]);
        } catch (QueryException $exception) {
            return response()->json(['message' => 'Snapshot already exists.'], 409);
        }

        return response()->json(
            $snapshot->load([
                'project:project_id,name,owner_id,is_public',
                'project.owner:user_id,name,email',
                'author:user_id,name,email',
            ]),
            201
        );
    }

    public function update(Request $request, int $snapshotId)
    {
        $snapshot = ProjectSnapshot::query()->findOrFail($snapshotId);

        $data = $request->validate([
            'project_id' => ['sometimes', 'integer', 'exists:projects,project_id'],
            'snapshot_hash' => [
                'sometimes',
                'string',
                'size:64',
                Rule::unique('project_snapshots', 'snapshot_hash')->ignore($snapshot->snapshot_id, 'snapshot_id'),
            ],
            'author_user_id' => ['nullable', 'integer', 'exists:users,user_id'],
            'message' => ['nullable', 'string'],
            'snapshot_path' => ['sometimes', 'string', 'max:2048'],
            'size_bytes' => ['nullable', 'integer', 'min:0'],
            'created_at' => ['nullable', 'date'],
        ]);

        $snapshot->fill($data);

        try {
            $snapshot->save();
        } catch (QueryException $exception) {
            return response()->json(['message' => 'Snapshot already exists.'], 409);
        }

        return $snapshot->load([
            'project:project_id,name,owner_id,is_public',
            'project.owner:user_id,name,email',
            'author:user_id,name,email',
        ]);
    }

    public function destroy(int $snapshotId)
    {
        $snapshot = ProjectSnapshot::query()->findOrFail($snapshotId);
        $snapshot->delete();

        return response()->noContent();
    }
}

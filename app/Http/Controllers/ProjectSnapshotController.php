<?php

namespace App\Http\Controllers;

use App\Models\ProjectSnapshot;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProjectSnapshotController extends Controller
{
    public function index(Request $request)
    {
        $perPage = min((int) $request->query('per_page', 50), 200);
        $query = ProjectSnapshot::query();

        if ($request->filled('project_id')) {
            $query->where('project_id', (int) $request->query('project_id'));
        }

        if ($request->filled('author_user_id')) {
            $query->where('author_user_id', (int) $request->query('author_user_id'));
        }

        return $query->paginate($perPage);
    }

    public function show(int $snapshotId)
    {
        return ProjectSnapshot::query()->findOrFail($snapshotId);
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

        $snapshot = ProjectSnapshot::query()->create($data);

        return response()->json($snapshot, 201);
    }

    public function update(Request $request, int $snapshotId)
    {
        $snapshot = ProjectSnapshot::query()->findOrFail($snapshotId);

        $data = $request->validate([
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
        ]);

        $snapshot->fill($data);
        $snapshot->save();

        return $snapshot;
    }

    public function destroy(int $snapshotId)
    {
        $snapshot = ProjectSnapshot::query()->findOrFail($snapshotId);
        $snapshot->delete();

        return response()->noContent();
    }
}

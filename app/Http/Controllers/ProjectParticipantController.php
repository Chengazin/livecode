<?php

namespace App\Http\Controllers;

use App\Models\ProjectParticipant;
use Illuminate\Http\Request;

class ProjectParticipantController extends Controller
{
    public function index(Request $request)
    {
        $perPage = min((int) $request->query('per_page', 50), 200);
        $query = ProjectParticipant::query();

        if ($request->filled('project_id')) {
            $query->where('project_id', (int) $request->query('project_id'));
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', (int) $request->query('user_id'));
        }

        return $query->paginate($perPage);
    }

    public function show(int $participantId)
    {
        return ProjectParticipant::query()->findOrFail($participantId);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'project_id' => ['required', 'integer', 'exists:projects,project_id'],
            'user_id' => ['required', 'integer', 'exists:users,user_id'],
            'joined_at' => ['nullable', 'date'],
        ]);

        $participant = ProjectParticipant::query()->create($data);

        return response()->json($participant, 201);
    }

    public function update(Request $request, int $participantId)
    {
        $participant = ProjectParticipant::query()->findOrFail($participantId);

        $data = $request->validate([
            'joined_at' => ['nullable', 'date'],
        ]);

        $participant->fill($data);
        $participant->save();

        return $participant;
    }

    public function destroy(int $participantId)
    {
        $participant = ProjectParticipant::query()->findOrFail($participantId);
        $participant->delete();

        return response()->noContent();
    }
}

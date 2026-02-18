<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $perPage = min((int) $request->query('per_page', 50), 200);
        $search = trim((string) $request->query('search', ''));
        $status = trim((string) $request->query('status', ''));
        $isAdmin = $request->query('is_admin', null);
        $likeOperator = config('database.default') === 'pgsql' ? 'ilike' : 'like';

        $query = User::query()->with(['admin:admin_id,user_id'])->orderByDesc('created_at');

        if ($search !== '') {
            $query->where(function ($builder) use ($search, $likeOperator) {
                $builder
                    ->where('name', $likeOperator, '%'.$search.'%')
                    ->orWhere('email', $likeOperator, '%'.$search.'%');

                if (ctype_digit($search)) {
                    $builder->orWhere('user_id', (int) $search);
                }
            });
        }

        if ($status !== '') {
            $query->where('status', $status);
        }

        if ($isAdmin !== null && $isAdmin !== '') {
            $flag = filter_var($isAdmin, FILTER_VALIDATE_BOOL);
            $query->{$flag ? 'whereHas' : 'whereDoesntHave'}('admin');
        }

        return $query->paginate($perPage);
    }

    public function show(int $userId)
    {
        return User::query()
            ->with(['admin:admin_id,user_id'])
            ->findOrFail($userId);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'max:255'],
            'status' => ['sometimes', 'string', Rule::in(['active', 'blocked'])],
            'language' => ['sometimes', 'string', Rule::in(['rus', 'eng'])],
            'last_seen' => ['nullable', 'date'],
        ]);

        $user = User::query()->create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password_hash' => Hash::make($data['password']),
            'status' => $data['status'] ?? 'active',
            'language' => $data['language'] ?? 'rus',
            'last_seen' => $data['last_seen'] ?? null,
        ]);

        return response()->json($user->load('admin:admin_id,user_id'), 201);
    }

    public function update(Request $request, int $userId)
    {
        $user = User::query()->findOrFail($userId);
        $isAdminUser = $user->admin()->exists();

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => [
                'sometimes',
                'string',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($user->user_id, 'user_id'),
            ],
            'password' => ['sometimes', 'string', 'min:8', 'max:255'],
            'status' => ['sometimes', 'string', Rule::in(['active', 'blocked'])],
            'language' => ['sometimes', 'string', Rule::in(['rus', 'eng'])],
            'last_seen' => ['nullable', 'date'],
        ]);

        if ($isAdminUser && array_key_exists('status', $data) && $data['status'] !== $user->status) {
            return response()->json([
                'message' => 'Cannot change status for admin users.',
            ], 403);
        }

        if (array_key_exists('password', $data)) {
            $data['password_hash'] = Hash::make($data['password']);
            unset($data['password']);
        }

        $user->fill($data);
        $user->save();

        return $user->load('admin:admin_id,user_id');
    }

    public function destroy(int $userId)
    {
        $user = User::query()->findOrFail($userId);
        if ($user->admin()->exists()) {
            return response()->json([
                'message' => 'Cannot delete admin users.',
            ], 403);
        }

        $projectPaths = Project::query()
            ->where('owner_id', $user->user_id)
            ->pluck('project_path')
            ->filter(fn ($path) => is_string($path) && trim($path) !== '')
            ->all();

        foreach ($projectPaths as $projectPath) {
            Storage::disk('local')->deleteDirectory((string) $projectPath);
        }

        if ($user->avatar_type === 'upload' && $user->avatar_path) {
            Storage::disk('public')->delete($user->avatar_path);
        }

        $user->tokens()->delete();
        $user->delete();

        return response()->noContent();
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminController extends Controller
{
    public function index(Request $request)
    {
        $perPage = min((int) $request->query('per_page', 50), 200);

        return Admin::query()->paginate($perPage);
    }

    public function show(int $adminId)
    {
        return Admin::query()->findOrFail($adminId);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,user_id', 'unique:admins,user_id'],
            'created_at' => ['nullable', 'date'],
        ]);

        $admin = Admin::query()->create($data);

        return response()->json($admin, 201);
    }

    public function update(Request $request, int $adminId)
    {
        $admin = Admin::query()->findOrFail($adminId);

        $data = $request->validate([
            'user_id' => [
                'sometimes',
                'integer',
                'exists:users,user_id',
                Rule::unique('admins', 'user_id')->ignore($admin->admin_id, 'admin_id'),
            ],
        ]);

        $admin->fill($data);
        $admin->save();

        return $admin;
    }

    public function destroy(int $adminId)
    {
        $admin = Admin::query()->findOrFail($adminId);
        $admin->delete();

        return response()->noContent();
    }
}

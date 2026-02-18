<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use Illuminate\Http\Request;

class AdminController extends Controller
{
    public function index(Request $request)
    {
        $perPage = min((int) $request->query('per_page', 50), 200);

        return Admin::query()
            ->with(['user:user_id,name,email,status'])
            ->paginate($perPage);
    }

    public function show(int $adminId)
    {
        return Admin::query()
            ->with(['user:user_id,name,email,status'])
            ->findOrFail($adminId);
    }

    public function store(Request $request)
    {
        return response()->json([
            'message' => 'Admin role changes are disabled by business rules.',
        ], 403);
    }

    public function update(Request $request, int $adminId)
    {
        return response()->json([
            'message' => 'Admin role changes are disabled by business rules.',
        ], 403);
    }

    public function destroy(int $adminId)
    {
        return response()->json([
            'message' => 'Admin role changes are disabled by business rules.',
        ], 403);
    }
}

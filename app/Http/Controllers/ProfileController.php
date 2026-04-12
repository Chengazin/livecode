<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ProfileController extends Controller
{
    private const AVATAR_PRESETS = [
        'robot',
        'fox',
        'owl',
        'wave',
        'leaf',
        'sun',
        'code',
        'rocket',
    ];

    private const THEMES = [
        'light',
        'dark',
        'system',
    ];

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        return response()->json($this->serializeUser($user));
    }

    public function update(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => [
                'sometimes',
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($user->user_id, 'user_id'),
            ],
            'language' => ['sometimes', 'required', 'string', Rule::in(['rus', 'eng'])],
            'theme' => ['sometimes', 'required', 'string', Rule::in(self::THEMES)],
            'avatar_preset' => ['nullable', 'string', Rule::in(self::AVATAR_PRESETS)],
            'new_password' => ['sometimes', 'required', 'string', 'min:8', 'max:255', 'confirmed'],
        ]);

        $emailChanged = array_key_exists('email', $data)
            && (string) $data['email'] !== (string) $user->email;
        $passwordChanged = array_key_exists('new_password', $data);

        if (array_key_exists('name', $data)) {
            $user->name = $data['name'];
        }

        if (array_key_exists('email', $data)) {
            $user->email = $data['email'];
        }

        if (array_key_exists('language', $data)) {
            $user->language = $data['language'];
        }

        if (array_key_exists('theme', $data)) {
            $user->theme = $data['theme'];
        }

        if (array_key_exists('new_password', $data)) {
            $user->password_hash = Hash::make($data['new_password']);
        }

        if (array_key_exists('avatar_preset', $data) && $data['avatar_preset'] !== null) {
            if ($user->avatar_type === 'upload') {
                $this->deleteAvatarFile($user);
                $user->avatar_path = null;
            }

            $user->avatar_type = 'preset';
            $user->avatar_preset = $data['avatar_preset'];
        }

        $user->save();

        if ($emailChanged || $passwordChanged) {
            $user->tokens()->delete();
        }

        $payload = $this->serializeUser($user->fresh());
        $payload['logged_out_all'] = $emailChanged || $passwordChanged;

        return response()->json($payload);
    }

    public function uploadAvatar(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $data = $request->validate([
            'avatar' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:3072'],
        ]);

        $file = $data['avatar'];
        $extension = strtolower((string) ($file->getClientOriginalExtension() ?: $file->extension() ?: 'png'));
        $filename = 'avatar-'.now()->timestamp.'-'.Str::random(12).'.'.$extension;
        $path = $file->storeAs('avatars/'.$user->user_id, $filename, 'public');

        if ($path === false) {
            return response()->json(['message' => 'Failed to store avatar.'], 500);
        }

        if ($user->avatar_type === 'upload') {
            $this->deleteAvatarFile($user);
        }

        $user->avatar_type = 'upload';
        $user->avatar_path = $path;
        $user->save();

        return response()->json($this->serializeUser($user->fresh()));
    }

    public function deleteAvatar(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        if ($user->avatar_type === 'upload') {
            $this->deleteAvatarFile($user);
            $user->avatar_path = null;
            $user->avatar_type = 'preset';

            if (! $user->avatar_preset) {
                $user->avatar_preset = self::AVATAR_PRESETS[0];
            }

            $user->save();
        }

        return response()->json($this->serializeUser($user->fresh()));
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeUser(User $user): array
    {
        $payload = $user->toArray();
        $payload['avatar_url'] = $this->resolveAvatarUrl($user);
        $payload['avatar_presets'] = self::AVATAR_PRESETS;
        $payload['theme_options'] = self::THEMES;
        $payload['is_admin'] = $user->admin()->exists();

        return $payload;
    }

    private function resolveAvatarUrl(User $user): ?string
    {
        if ($user->avatar_type !== 'upload' || ! $user->avatar_path) {
            return null;
        }

        $disk = Storage::disk('public');

        if (! $disk->exists($user->avatar_path)) {
            return null;
        }

        return '/storage/'.ltrim($user->avatar_path, '/');
    }

    private function deleteAvatarFile(User $user): void
    {
        if (! $user->avatar_path) {
            return;
        }

        Storage::disk('public')->delete($user->avatar_path);
    }
}

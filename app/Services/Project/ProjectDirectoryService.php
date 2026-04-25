<?php

namespace App\Services\Project;

use App\Models\Project;
use Illuminate\Support\Facades\Storage;

class ProjectDirectoryService
{
    public function createProjectDirectory(int $userId, string $email, string $projectName): string
    {
        $disk = Storage::disk('local');
        $root = 'projects';

        if (! $disk->exists($root)) {
            $disk->makeDirectory($root);
        }

        $userSegment = $this->sanitizePathSegment($userId.'_'.$email);
        $userPath = $root.'/'.$userSegment;

        if (! $disk->exists($userPath)) {
            $disk->makeDirectory($userPath);
        }

        $projectSegment = $this->sanitizePathSegment($projectName);
        $basePath = $userPath.'/'.$projectSegment;
        $path = $basePath;
        $suffix = 2;

        while ($disk->exists($path) || Project::query()->where('project_path', $path)->exists()) {
            $path = $basePath.'-'.$suffix;
            $suffix++;
        }

        if (! $disk->makeDirectory($path)) {
            abort(500, 'Failed to create project directory.');
        }

        return $path;
    }

    public function deleteProjectDirectory(?string $projectPath): void
    {
        $normalizedPath = trim((string) $projectPath, '/');
        if ($normalizedPath === '') {
            return;
        }

        Storage::disk('local')->deleteDirectory($normalizedPath);
    }

    private function sanitizePathSegment(string $value): string
    {
        $value = trim($value);
        $value = preg_replace('/[\/\\\\]+/', '-', $value);
        $value = preg_replace('/[:*?"<>|]/', '', $value);
        $value = preg_replace('/[\x00-\x1F\x7F]/u', '', $value);
        $value = preg_replace('/\s+/', ' ', $value);
        $value = trim($value, " .\t\n\r\0\x0B");

        if ($value === '') {
            return 'untitled';
        }

        return $value;
    }
}

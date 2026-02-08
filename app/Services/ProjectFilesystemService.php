<?php

namespace App\Services;

use App\Models\Project;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

class ProjectFilesystemService
{
    private const MAX_DEPTH = 10;

    public function createFolder(Project $project, string $relativePath): string
    {
        $safePath = $this->sanitizeRelativePath($relativePath);
        $fullPath = $this->joinProjectPath($project, $safePath);

        $disk = Storage::disk('local');

        if (! $disk->exists($fullPath)) {
            $disk->makeDirectory($fullPath);
        }

        return $safePath;
    }

    public function createFile(Project $project, string $relativePath, string $content): string
    {
        $safePath = $this->sanitizeRelativePath($relativePath);
        $fullPath = $this->joinProjectPath($project, $safePath);

        $disk = Storage::disk('local');
        $directory = pathinfo($fullPath, PATHINFO_DIRNAME);

        if ($directory !== '.' && ! $disk->exists($directory)) {
            $disk->makeDirectory($directory);
        }

        $disk->put($fullPath, $content);

        return $safePath;
    }

    private function joinProjectPath(Project $project, string $relativePath): string
    {
        $projectRoot = trim((string) $project->project_path, '/');

        if ($projectRoot === '') {
            throw new InvalidArgumentException('invalid_project_root');
        }

        return $projectRoot.'/'.$relativePath;
    }

    private function sanitizeRelativePath(string $path): string
    {
        $path = trim($path);
        $path = str_replace('\\', '/', $path);

        if ($path === '') {
            throw new InvalidArgumentException('invalid_path');
        }

        if (str_starts_with($path, '/') || preg_match('/^[A-Za-z]:/', $path) === 1) {
            throw new InvalidArgumentException('invalid_path');
        }

        if (str_contains($path, "\0") || str_contains($path, '//')) {
            throw new InvalidArgumentException('invalid_path');
        }

        $segments = explode('/', $path);

        if (count($segments) > self::MAX_DEPTH) {
            throw new InvalidArgumentException('invalid_path');
        }

        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new InvalidArgumentException('invalid_path');
            }

            if (strlen($segment) > 255) {
                throw new InvalidArgumentException('invalid_path');
            }

            if ($segment !== trim($segment)) {
                throw new InvalidArgumentException('invalid_path');
            }

            if (str_ends_with($segment, '.')) {
                throw new InvalidArgumentException('invalid_path');
            }

            if (preg_match('/^[A-Za-z0-9._ -]+$/', $segment) !== 1) {
                throw new InvalidArgumentException('invalid_path');
            }
        }

        return implode('/', $segments);
    }
}

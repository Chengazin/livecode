<?php

namespace App\Services;

use App\Models\Project;
use FilesystemIterator;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ZipArchive;

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

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listTree(Project $project): array
    {
        $projectRoot = $this->projectRoot($project);
        $disk = Storage::disk('local');

        if (! $disk->exists($projectRoot)) {
            $disk->makeDirectory($projectRoot);
        }

        return $this->buildDirectoryTree($projectRoot, $projectRoot);
    }

    /**
     * @return array{path: string, content: string, size: int, updated_at: string|null}
     */
    public function readFile(Project $project, string $relativePath): array
    {
        $safePath = $this->sanitizeRelativePath($relativePath);
        $fullPath = $this->joinProjectPath($project, $safePath);
        $disk = Storage::disk('local');

        if (! $disk->exists($fullPath)) {
            throw new InvalidArgumentException('not_found');
        }

        $absolutePath = $disk->path($fullPath);

        if (is_dir($absolutePath)) {
            throw new InvalidArgumentException('not_file');
        }

        $content = (string) $disk->get($fullPath);

        if (! preg_match('//u', $content)) {
            throw new InvalidArgumentException('binary_file');
        }

        return [
            'path' => $safePath,
            'content' => $content,
            'size' => strlen($content),
            'updated_at' => $this->timestampToIso(filemtime($absolutePath) ?: null),
        ];
    }

    /**
     * @return array{path: string, size: int, updated_at: string|null}
     */
    public function writeFile(Project $project, string $relativePath, string $content): array
    {
        $safePath = $this->createFile($project, $relativePath, $content);
        $disk = Storage::disk('local');
        $fullPath = $this->joinProjectPath($project, $safePath);
        $absolutePath = $disk->path($fullPath);

        return [
            'path' => $safePath,
            'size' => strlen($content),
            'updated_at' => $this->timestampToIso(filemtime($absolutePath) ?: null),
        ];
    }

    /**
     * @return array{path: string, type: string}
     */
    public function deletePath(Project $project, string $relativePath): array
    {
        $safePath = $this->sanitizeRelativePath($relativePath);
        $fullPath = $this->joinProjectPath($project, $safePath);
        $disk = Storage::disk('local');

        if (! $disk->exists($fullPath)) {
            throw new InvalidArgumentException('not_found');
        }

        $absolutePath = $disk->path($fullPath);

        if (is_dir($absolutePath)) {
            $disk->deleteDirectory($fullPath);

            return [
                'path' => $safePath,
                'type' => 'folder',
            ];
        }

        $disk->delete($fullPath);

        return [
            'path' => $safePath,
            'type' => 'file',
        ];
    }

    /**
     * @return array{from_path: string, to_path: string, type: string}
     */
    public function movePath(Project $project, string $fromRelativePath, string $toRelativePath): array
    {
        $fromSafePath = $this->sanitizeRelativePath($fromRelativePath);
        $toSafePath = $this->sanitizeRelativePath($toRelativePath);

        if ($fromSafePath === $toSafePath) {
            throw new InvalidArgumentException('same_path');
        }

        if (str_starts_with($toSafePath, $fromSafePath.'/')) {
            throw new InvalidArgumentException('invalid_target');
        }

        $disk = Storage::disk('local');
        $fromFullPath = $this->joinProjectPath($project, $fromSafePath);
        $toFullPath = $this->joinProjectPath($project, $toSafePath);

        if (! $disk->exists($fromFullPath)) {
            throw new InvalidArgumentException('not_found');
        }

        if ($disk->exists($toFullPath)) {
            throw new InvalidArgumentException('target_exists');
        }

        $targetDirectory = pathinfo($toFullPath, PATHINFO_DIRNAME);

        if ($targetDirectory !== '.') {
            if (! $disk->exists($targetDirectory)) {
                throw new InvalidArgumentException('not_found');
            }

            $targetDirectoryAbsolute = $disk->path($targetDirectory);
            if (! is_dir($targetDirectoryAbsolute)) {
                throw new InvalidArgumentException('invalid_target');
            }
        }

        $fromAbsolutePath = $disk->path($fromFullPath);
        $toAbsolutePath = $disk->path($toFullPath);
        $fromType = is_dir($fromAbsolutePath) ? 'folder' : 'file';

        if (! @rename($fromAbsolutePath, $toAbsolutePath)) {
            throw new InvalidArgumentException('move_failed');
        }

        return [
            'from_path' => $fromSafePath,
            'to_path' => $toSafePath,
            'type' => $fromType,
        ];
    }

    /**
     * @return array{archive_path: string, download_name: string}
     */
    public function createZipArchive(Project $project, ?string $relativePath = null): array
    {
        $disk = Storage::disk('local');
        $projectRoot = $this->projectRoot($project);

        if (! $disk->exists($projectRoot)) {
            $disk->makeDirectory($projectRoot);
        }

        $safePath = null;
        if ($relativePath !== null && trim($relativePath) !== '') {
            $safePath = $this->sanitizeRelativePath($relativePath);
        }

        $sourceFullPath = $safePath ? $this->joinProjectPath($project, $safePath) : $projectRoot;
        if (! $disk->exists($sourceFullPath)) {
            throw new InvalidArgumentException('not_found');
        }

        $sourceAbsolutePath = $disk->path($sourceFullPath);
        if (! file_exists($sourceAbsolutePath)) {
            throw new InvalidArgumentException('not_found');
        }

        $archiveRootName = $safePath
            ? basename($safePath)
            : $this->archiveNameFromProject($project);

        $archiveDir = storage_path('app/tmp');
        if (! is_dir($archiveDir) && ! @mkdir($archiveDir, 0775, true) && ! is_dir($archiveDir)) {
            throw new InvalidArgumentException('archive_failed');
        }

        $archiveSuffix = str_replace('.', '', uniqid('', true));
        $archivePath = $archiveDir.'/project-'.$project->project_id.'-'.$archiveSuffix.'.zip';
        @unlink($archivePath);

        if (class_exists(ZipArchive::class)) {
            $this->createZipWithPhpExtension($sourceAbsolutePath, $archivePath, $archiveRootName);
        } else {
            $created = $this->createZipWithSystemTool($sourceAbsolutePath, $archivePath);

            if (! $created) {
                @unlink($archivePath);
                throw new InvalidArgumentException('zip_unavailable');
            }
        }

        if (! is_file($archivePath) || (int) filesize($archivePath) <= 0) {
            @unlink($archivePath);
            throw new InvalidArgumentException('archive_failed');
        }

        return [
            'archive_path' => $archivePath,
            'download_name' => $archiveRootName.'.zip',
        ];
    }

    private function createZipWithPhpExtension(string $sourceAbsolutePath, string $archivePath, string $archiveRootName): void
    {
        $zip = new ZipArchive();
        $openResult = $zip->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        if ($openResult !== true) {
            throw new InvalidArgumentException('archive_failed');
        }

        $added = false;

        if (is_file($sourceAbsolutePath)) {
            $added = $zip->addFile($sourceAbsolutePath, basename($sourceAbsolutePath));
        } elseif (is_dir($sourceAbsolutePath)) {
            $added = $this->addDirectoryToZip($zip, $sourceAbsolutePath, $archiveRootName);
        }

        if (! $added || ! $zip->close()) {
            @unlink($archivePath);
            throw new InvalidArgumentException('archive_failed');
        }
    }

    private function createZipWithSystemTool(string $sourceAbsolutePath, string $archivePath): bool
    {
        return PHP_OS_FAMILY === 'Windows'
            ? $this->createZipWithPowerShell($sourceAbsolutePath, $archivePath)
            : $this->createZipWithZipBinary($sourceAbsolutePath, $archivePath);
    }

    private function createZipWithPowerShell(string $sourceAbsolutePath, string $archivePath): bool
    {
        $sourceLiteral = $this->powerShellLiteral($sourceAbsolutePath);
        $archiveLiteral = $this->powerShellLiteral($archivePath);
        $script = "Compress-Archive -Path {$sourceLiteral} -DestinationPath {$archiveLiteral} -Force";

        return $this->runSystemCommand([
            'powershell',
            '-NoProfile',
            '-NonInteractive',
            '-ExecutionPolicy',
            'Bypass',
            '-Command',
            $script,
        ]) === 0;
    }

    private function createZipWithZipBinary(string $sourceAbsolutePath, string $archivePath): bool
    {
        $parent = dirname($sourceAbsolutePath);
        $name = basename($sourceAbsolutePath);

        if (! is_dir($parent) || $name === '') {
            return false;
        }

        if (is_dir($sourceAbsolutePath)) {
            return $this->runSystemCommand(['zip', '-r', '-q', $archivePath, $name], $parent) === 0;
        }

        return $this->runSystemCommand(['zip', '-q', $archivePath, $name], $parent) === 0;
    }

    /**
     * @param list<string> $command
     */
    private function runSystemCommand(array $command, ?string $cwd = null): int
    {
        $commandLine = implode(' ', array_map('escapeshellarg', $command));
        $descriptors = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $pipes = [];
        $process = @proc_open($commandLine, $descriptors, $pipes, $cwd);

        if (! is_resource($process)) {
            return 1;
        }

        if (isset($pipes[1]) && is_resource($pipes[1])) {
            stream_get_contents($pipes[1]);
            fclose($pipes[1]);
        }

        if (isset($pipes[2]) && is_resource($pipes[2])) {
            stream_get_contents($pipes[2]);
            fclose($pipes[2]);
        }

        return (int) proc_close($process);
    }

    private function powerShellLiteral(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildDirectoryTree(string $projectRoot, string $directory): array
    {
        $disk = Storage::disk('local');
        $items = [];

        $directories = $disk->directories($directory);
        sort($directories, SORT_NATURAL | SORT_FLAG_CASE);

        foreach ($directories as $folderPath) {
            $absolutePath = $disk->path($folderPath);
            $items[] = [
                'type' => 'folder',
                'name' => basename($folderPath),
                'path' => $this->toRelativeProjectPath($projectRoot, $folderPath),
                'updated_at' => $this->timestampToIso(filemtime($absolutePath) ?: null),
                'children' => $this->buildDirectoryTree($projectRoot, $folderPath),
            ];
        }

        $files = $disk->files($directory);
        sort($files, SORT_NATURAL | SORT_FLAG_CASE);

        foreach ($files as $filePath) {
            $absolutePath = $disk->path($filePath);
            $items[] = [
                'type' => 'file',
                'name' => basename($filePath),
                'path' => $this->toRelativeProjectPath($projectRoot, $filePath),
                'size' => is_file($absolutePath) ? (filesize($absolutePath) ?: 0) : 0,
                'updated_at' => $this->timestampToIso(filemtime($absolutePath) ?: null),
            ];
        }

        return $items;
    }

    private function toRelativeProjectPath(string $projectRoot, string $fullPath): string
    {
        $prefix = rtrim($projectRoot, '/').'/';

        return str_starts_with($fullPath, $prefix)
            ? substr($fullPath, strlen($prefix))
            : $fullPath;
    }

    private function projectRoot(Project $project): string
    {
        $projectRoot = trim((string) $project->project_path, '/');

        if ($projectRoot === '') {
            throw new InvalidArgumentException('invalid_project_root');
        }

        return $projectRoot;
    }

    private function joinProjectPath(Project $project, string $relativePath): string
    {
        $projectRoot = $this->projectRoot($project);
        return $projectRoot.'/'.$relativePath;
    }

    private function archiveNameFromProject(Project $project): string
    {
        $name = trim((string) ($project->name ?: 'project-'.$project->project_id));
        $name = preg_replace('/[^A-Za-z0-9._ -]+/', '-', $name) ?? '';
        $name = trim($name, " .-_");

        if ($name === '') {
            return 'project-'.$project->project_id;
        }

        return $name;
    }

    private function addDirectoryToZip(ZipArchive $zip, string $sourceAbsolutePath, string $archiveRootName): bool
    {
        $archiveRoot = trim(str_replace('\\', '/', $archiveRootName), '/');
        if ($archiveRoot === '') {
            $archiveRoot = 'project';
        }

        if ($zip->addEmptyDir($archiveRoot) === false) {
            return false;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sourceAbsolutePath, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $absoluteItemPath = $item->getPathname();
            $relativePart = ltrim(str_replace('\\', '/', substr($absoluteItemPath, strlen($sourceAbsolutePath))), '/');

            if ($relativePart === '') {
                continue;
            }

            $archiveEntry = $archiveRoot.'/'.$relativePart;

            if ($item->isDir()) {
                if ($zip->addEmptyDir($archiveEntry) === false) {
                    return false;
                }

                continue;
            }

            if ($item->isFile() && $zip->addFile($absoluteItemPath, $archiveEntry) === false) {
                return false;
            }
        }

        return true;
    }

    private function timestampToIso(?int $timestamp): ?string
    {
        if (! $timestamp) {
            return null;
        }

        return gmdate(DATE_ATOM, $timestamp);
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

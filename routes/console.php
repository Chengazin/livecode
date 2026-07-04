<?php

use App\Models\ProjectTerminalSession;
use Illuminate\Console\Command;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('app:export-data {--output= : Path to output zip file (default: storage/app/backup/livecode-backup-{date}.zip)}', function () {
    $output = $this->option('output');
    if (! $output) {
        $backupDir = storage_path('app/backup');
        if (! is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }
        $output = $backupDir . '/livecode-backup-' . date('Y-m-d-His') . '.zip';
    }

    $zip = new ZipArchive();
    if ($zip->open($output, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        $this->error("Cannot create zip file: {$output}");
        return Command::FAILURE;
    }

    $sourceDirs = [
        'projects' => storage_path('app/private/projects'),
        'public' => storage_path('app/public'),
    ];

    $added = 0;
    foreach ($sourceDirs as $name => $dir) {
        if (! is_dir($dir)) {
            $this->warn("Directory not found: {$dir}");
            continue;
        }
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($files as $file) {
            if (! $file->isFile()) continue;
            $relativePath = $name . '/' . $file->getFilename();
            $realPath = $file->getRealPath();
            $localPath = $file->getPathname();
            $prefix = dirname($localPath);
            $zipRelativeDir = $name . '/' . substr($prefix, strlen(dirname($dir)) + 1);
            $zipRelativePath = $zipRelativeDir . '/' . $file->getFilename();
            $zipRelativePath = ltrim($zipRelativePath, '/');
            $zip->addFile($realPath, $zipRelativePath);
            $added++;
        }
    }

    $privateGitignore = storage_path('app/private/.gitignore');
    if (file_exists($privateGitignore)) {
        $zip->addFile($privateGitignore, 'private/.gitignore');
        $added++;
    }

    $sttFile = storage_path('app/private/stt_test.wav');
    if (file_exists($sttFile)) {
        $zip->addFile($sttFile, 'private/stt_test.wav');
        $added++;
    }

    $zip->close();

    $this->info("Exported {$added} files to: {$output}");
    $this->line("Size: " . round(filesize($output) / 1024 / 1024, 2) . " MB");

    return Command::SUCCESS;
})->purpose('Export storage contents (projects + avatars) to a portable zip archive');

Artisan::command('app:import-data {zip : Path to the zip file created by app:export-data}', function () {
    $zipPath = $this->argument('zip');
    if (! file_exists($zipPath)) {
        $this->error("File not found: {$zipPath}");
        return Command::FAILURE;
    }

    $zip = new ZipArchive();
    $status = $zip->open($zipPath);
    if ($status !== true) {
        $this->error("Cannot open zip file (error code: {$status})");
        return Command::FAILURE;
    }

    $extractPaths = [
        'projects' => storage_path('app/private/projects'),
        'public' => storage_path('app/public'),
        'private' => storage_path('app/private'),
    ];

    $extracted = 0;
    for ($i = 0; $i < $zip->numEntries; $i++) {
        $entry = $zip->getNameIndex($i);
        if (substr($entry, -1) === '/') continue;

        $matched = false;
        foreach ($extractPaths as $prefix => $destDir) {
            if (str_starts_with($entry, $prefix . '/')) {
                $relativePath = substr($entry, strlen($prefix) + 1);
                $targetPath = $destDir . '/' . $relativePath;
                $targetDir = dirname($targetPath);
                if (! is_dir($targetDir)) {
                    mkdir($targetDir, 0755, true);
                }
                copy('zip://' . $zipPath . '#' . $entry, $targetPath);
                $extracted++;
                $matched = true;
                break;
            }
        }
        if (! $matched) {
            $this->warn("Skipped unknown entry: {$entry}");
        }
    }

    $zip->close();
    $this->info("Extracted {$extracted} files from: {$zipPath}");

    return Command::SUCCESS;
})->purpose('Import storage contents from a zip archive created by app:export-data');

Artisan::command('terminal:sessions:prune {--hours=} {--dry-run}', function () {
    if (! (bool) config('terminal.enabled', true)) {
        $this->info('Terminal feature disabled. Skipping prune.');

        return Command::SUCCESS;
    }

    $configuredHours = max(1, (int) config('terminal.closed_session_retention_hours', 168));
    $hoursOption = $this->option('hours');
    $retentionHours = $configuredHours;

    if ($hoursOption !== null && $hoursOption !== '') {
        if (! is_numeric($hoursOption)) {
            $this->error('Option --hours must be numeric.');

            return Command::INVALID;
        }

        $retentionHours = max(1, (int) $hoursOption);
    }

    $cutoff = now()->subHours($retentionHours);

    $query = ProjectTerminalSession::query()
        ->where('status', 'closed')
        ->where(function ($nested) use ($cutoff): void {
            $nested->where(function ($dated) use ($cutoff): void {
                $dated->whereNotNull('closed_at')
                    ->where('closed_at', '<=', $cutoff);
            })->orWhere(function ($fallback) use ($cutoff): void {
                $fallback->whereNull('closed_at')
                    ->where('updated_at', '<=', $cutoff);
            });
        });

    $deleteCount = (clone $query)->count();
    $summary = 'Closed terminal sessions older than '.$retentionHours.'h (cutoff '.$cutoff->toDateTimeString().')';

    if ((bool) $this->option('dry-run')) {
        $this->line('[dry-run] '.$summary.': '.$deleteCount.' candidate(s).');

        return Command::SUCCESS;
    }

    if ($deleteCount > 0) {
        $query->delete();
    }

    $this->info($summary.': pruned '.$deleteCount.' session(s).');

    return Command::SUCCESS;
})->purpose('Prune old closed terminal sessions');

Schedule::command('terminal:sessions:prune')
    ->hourly()
    ->withoutOverlapping()
    ->when(fn (): bool => (bool) config('terminal.enabled', true));

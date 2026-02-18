<?php

use App\Models\ProjectTerminalSession;
use Illuminate\Console\Command;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

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

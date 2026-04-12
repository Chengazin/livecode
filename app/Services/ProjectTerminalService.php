<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectTerminalSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

class ProjectTerminalService
{
    private const STATUS_OPEN = 'open';
    private const STATUS_CLOSED = 'closed';

    /**
     * @return array<int, ProjectTerminalSession>
     */
    public function listVisibleSessions(Project $project, User $user): array
    {
        return $this->visibleSessionsQuery($project, $user)
            ->with('user:user_id,name,email')
            ->orderByDesc('updated_at')
            ->limit(100)
            ->get()
            ->all();
    }

    /**
     * @param array<string, mixed> $data
     */
    public function createSession(Project $project, User $user, array $data): ProjectTerminalSession
    {
        $maxOpenPerUser = max(1, (int) config('terminal.max_open_sessions_per_user', 4));
        $openCount = ProjectTerminalSession::query()
            ->where('project_id', $project->project_id)
            ->where('user_id', $user->user_id)
            ->where('status', self::STATUS_OPEN)
            ->count();

        if ($openCount >= $maxOpenPerUser) {
            throw new InvalidArgumentException('limit_reached');
        }

        $shared = (bool) ($data['shared'] ?? false);
        if ($shared && ! $this->mayCreateSharedSession($project, $user)) {
            throw new InvalidArgumentException('shared_forbidden');
        }

        $session = ProjectTerminalSession::query()->create([
            'project_id' => $project->project_id,
            'user_id' => $user->user_id,
            'name' => $this->normalizeName($data['name'] ?? null, $openCount + 1),
            'shell' => $this->normalizeShell($data['shell'] ?? null),
            'cwd' => $this->normalizeCwd($project, $data['cwd'] ?? '/'),
            'shared' => $shared,
            'status' => self::STATUS_OPEN,
            'last_activity_at' => now(),
            'meta' => [
                'created_by' => (int) $user->user_id,
            ],
        ]);

        return $session->loadMissing('user:user_id,name,email');
    }

    public function closeSession(Project $project, ProjectTerminalSession $session, User $user): ProjectTerminalSession
    {
        if (! $this->mayManageSession($project, $session, $user)) {
            throw new InvalidArgumentException('access_denied');
        }

        if (! $session->isOpen()) {
            return $session;
        }

        $session->status = self::STATUS_CLOSED;
        $session->closed_at = now();
        $session->last_activity_at = now();
        $session->save();

        return $session->refresh()->loadMissing('user:user_id,name,email');
    }

    /**
     * @return array<string, mixed>
     */
    public function issueConnectTicket(Project $project, ProjectTerminalSession $session, User $user): array
    {
        if (! $session->isOpen()) {
            throw new InvalidArgumentException('session_closed');
        }

        if (! $this->mayUseSession($project, $session, $user)) {
            throw new InvalidArgumentException('access_denied');
        }

        $disk = Storage::disk('local');
        $projectPath = trim((string) $project->project_path, '/');

        if ($projectPath === '') {
            throw new InvalidArgumentException('invalid_project_root');
        }

        if (! $disk->exists($projectPath)) {
            $disk->makeDirectory($projectPath);
        }

        $ownerPath = $this->ownerPathFromProjectPath($projectPath);
        if ($ownerPath === '') {
            throw new InvalidArgumentException('invalid_project_root');
        }

        if (! $disk->exists($ownerPath)) {
            $disk->makeDirectory($ownerPath);
        }

        $ownerRootAbsolute = $disk->path($ownerPath);
        $projectRootAbsolute = $disk->path($projectPath);
        if (! $this->isAbsolutePathWithin($ownerRootAbsolute, $projectRootAbsolute)) {
            throw new InvalidArgumentException('invalid_project_root');
        }

        $cwdRelative = $this->normalizeCwd($project, (string) $session->cwd);

        $cwdAbsolute = $projectRootAbsolute;
        if ($cwdRelative !== '/') {
            $cwdAbsolute = $disk->path($projectPath.'/'.ltrim($cwdRelative, '/'));
            if (! is_dir($cwdAbsolute) || ! $this->isAbsolutePathWithin($projectRootAbsolute, $cwdAbsolute)) {
                $cwdAbsolute = $projectRootAbsolute;
            }
        }

        $issuedAt = now()->timestamp;
        $ttlSeconds = max(5, (int) config('terminal.ticket_ttl_seconds', 30));
        $expiresAt = $issuedAt + $ttlSeconds;

        $payload = [
            'iss' => 'livecode-terminal',
            'terminal_session_id' => (int) $session->terminal_session_id,
            'project_id' => (int) $project->project_id,
            'owner_root' => $ownerRootAbsolute,
            'project_root' => $projectRootAbsolute,
            'cwd' => $cwdAbsolute,
            'cwd_relative' => $cwdRelative,
            'request_user_id' => (int) $user->user_id,
            'session_user_id' => (int) $session->user_id,
            'shared' => (bool) $session->shared,
            'shell' => $this->normalizeShell((string) ($session->shell ?? '')),
            'nonce' => Str::random(20),
            'iat' => $issuedAt,
            'exp' => $expiresAt,
        ];

        $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if (! is_string($payloadJson) || $payloadJson === '') {
            throw new InvalidArgumentException('ticket_encode_failed');
        }

        $payloadEncoded = $this->base64UrlEncode($payloadJson);
        $signatureRaw = hash_hmac('sha256', $payloadEncoded, $this->sharedSecret(), true);

        $session->last_activity_at = now();
        $session->save();

        return [
            'ticket' => $payloadEncoded.'.'.$this->base64UrlEncode($signatureRaw),
            'expires_at' => now()->addSeconds($ttlSeconds)->toISOString(),
            'ws_url' => $this->gatewayWsUrl(),
            'session' => $session->fresh()->loadMissing('user:user_id,name,email'),
        ];
    }

    public function findProjectSession(Project $project, int $sessionId): ?ProjectTerminalSession
    {
        return ProjectTerminalSession::query()
            ->where('project_id', $project->project_id)
            ->where('terminal_session_id', $sessionId)
            ->first();
    }

    public function findSessionById(int $sessionId): ?ProjectTerminalSession
    {
        return ProjectTerminalSession::query()
            ->where('terminal_session_id', $sessionId)
            ->first();
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function closeSessionFromGateway(ProjectTerminalSession $session, array $payload = []): ProjectTerminalSession
    {
        $closedAt = $this->parseGatewayClosedAt($payload['closed_at'] ?? null);
        $reason = trim((string) ($payload['reason'] ?? 'gateway-close'));
        $runtime = trim((string) ($payload['runtime'] ?? ''));
        $exitCode = array_key_exists('exit_code', $payload) && $payload['exit_code'] !== null && $payload['exit_code'] !== ''
            ? (int) $payload['exit_code']
            : null;
        $signal = array_key_exists('signal', $payload) && $payload['signal'] !== null && $payload['signal'] !== ''
            ? (int) $payload['signal']
            : null;

        $entry = [
            'reason' => $reason !== '' ? $reason : 'gateway-close',
            'runtime' => $runtime !== '' ? $runtime : 'host',
            'exit_code' => $exitCode,
            'signal' => $signal,
            'closed_at' => $closedAt->toISOString(),
        ];

        $meta = is_array($session->meta) ? $session->meta : [];
        $history = is_array($meta['gateway_close_history'] ?? null)
            ? $meta['gateway_close_history']
            : [];
        $history[] = $entry;

        if (count($history) > 20) {
            $history = array_slice($history, -20);
        }

        $meta['gateway_last_close'] = $entry;
        $meta['gateway_close_history'] = $history;
        $session->meta = $meta;
        $session->last_activity_at = $closedAt;

        if ($session->isOpen()) {
            $session->status = self::STATUS_CLOSED;
            $session->closed_at = $closedAt;
        } elseif ($session->closed_at === null) {
            $session->closed_at = $closedAt;
        }

        $session->save();

        return $session->refresh()->loadMissing('user:user_id,name,email');
    }

    private function mayUseSession(Project $project, ProjectTerminalSession $session, User $user): bool
    {
        if ($this->isProjectOwner($project, $user)) {
            return true;
        }

        if ((int) $session->user_id === (int) $user->user_id) {
            return true;
        }

        return (bool) $session->shared;
    }

    private function mayManageSession(Project $project, ProjectTerminalSession $session, User $user): bool
    {
        if ($this->isProjectOwner($project, $user)) {
            return true;
        }

        return (int) $session->user_id === (int) $user->user_id;
    }

    private function mayCreateSharedSession(Project $project, User $user): bool
    {
        if ((bool) config('terminal.allow_collaborator_shared_sessions', false)) {
            return true;
        }

        return $this->isProjectOwner($project, $user);
    }

    private function isProjectOwner(Project $project, User $user): bool
    {
        return (int) $project->owner_id === (int) $user->user_id;
    }

    /**
     * @return Builder<ProjectTerminalSession>
     */
    private function visibleSessionsQuery(Project $project, User $user): Builder
    {
        $query = ProjectTerminalSession::query()
            ->where('project_id', $project->project_id);

        if ($this->isProjectOwner($project, $user)) {
            return $query;
        }

        return $query->where(function (Builder $nested) use ($user): void {
            $nested
                ->where('shared', true)
                ->orWhere('user_id', $user->user_id);
        });
    }

    /**
     * @param mixed $name
     */
    private function normalizeName($name, int $fallbackIndex): string
    {
        $value = trim((string) ($name ?? ''));
        if ($value === '') {
            $value = 'Terminal '.$fallbackIndex;
        }

        $maxLength = max(16, (int) config('terminal.max_name_length', 120));
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($value, 'UTF-8') > $maxLength) {
                $value = (string) mb_substr($value, 0, $maxLength, 'UTF-8');
            }
        } elseif (strlen($value) > $maxLength) {
            $value = substr($value, 0, $maxLength);
        }

        return $value;
    }

    /**
     * @param mixed $shell
     */
    private function normalizeShell($shell): string
    {
        $value = trim((string) ($shell ?? ''));
        $allowed = array_values(array_filter(array_map('trim', (array) config('terminal.allowed_shells', []))));

        if ($value === '') {
            if (count($allowed) > 0) {
                return (string) $allowed[0];
            }

            return PHP_OS_FAMILY === 'Windows' ? 'powershell.exe' : '/bin/bash';
        }

        if (count($allowed) > 0 && ! in_array($value, $allowed, true)) {
            throw new InvalidArgumentException('invalid_shell');
        }

        return $value;
    }

    /**
     * @param mixed $cwd
     */
    private function normalizeCwd(Project $project, $cwd): string
    {
        $value = trim(str_replace('\\', '/', (string) ($cwd ?? '/')));
        if ($value === '') {
            return '/';
        }

        if ($value === '/') {
            return '/';
        }

        if (str_starts_with($value, '/')) {
            $value = ltrim($value, '/');
        }

        $maxLength = max(64, (int) config('terminal.max_cwd_length', 2048));
        if (strlen($value) > $maxLength) {
            throw new InvalidArgumentException('invalid_cwd');
        }

        if (str_contains($value, "\0") || str_contains($value, '//')) {
            throw new InvalidArgumentException('invalid_cwd');
        }

        $segments = explode('/', $value);
        if (count($segments) > max(1, (int) config('terminal.max_path_depth', 12))) {
            throw new InvalidArgumentException('invalid_cwd');
        }

        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new InvalidArgumentException('invalid_cwd');
            }

            if (strlen($segment) > 255 || $segment !== trim($segment)) {
                throw new InvalidArgumentException('invalid_cwd');
            }

            if (preg_match('/^[A-Za-z0-9._ -]+$/', $segment) !== 1) {
                throw new InvalidArgumentException('invalid_cwd');
            }
        }

        $projectPath = trim((string) $project->project_path, '/');
        if ($projectPath === '') {
            throw new InvalidArgumentException('invalid_project_root');
        }

        $disk = Storage::disk('local');
        $relativePath = $projectPath.'/'.$value;

        if (! $disk->exists($relativePath)) {
            throw new InvalidArgumentException('cwd_not_found');
        }

        $absolutePath = $disk->path($relativePath);
        if (! is_dir($absolutePath)) {
            throw new InvalidArgumentException('cwd_not_directory');
        }

        return '/'.$value;
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function sharedSecret(): string
    {
        $secret = trim((string) config('terminal.shared_secret', ''));
        if ($secret === '') {
            throw new InvalidArgumentException('terminal_secret_missing');
        }

        return $secret;
    }

    private function gatewayWsUrl(): string
    {
        $value = trim((string) config('terminal.gateway_ws_url', ''));
        if ($value === '') {
            throw new InvalidArgumentException('terminal_gateway_missing');
        }

        return $value;
    }

    private function ownerPathFromProjectPath(string $projectPath): string
    {
        $normalized = trim(str_replace('\\', '/', $projectPath), '/');
        if ($normalized === '') {
            return '';
        }

        $ownerPath = trim(str_replace('\\', '/', dirname($normalized)), '/');
        if ($ownerPath === '' || $ownerPath === '.') {
            return $normalized;
        }

        return $ownerPath;
    }

    private function isAbsolutePathWithin(string $basePath, string $targetPath): bool
    {
        $base = $this->normalizeAbsolutePath($basePath);
        $target = $this->normalizeAbsolutePath($targetPath);

        if ($base === '' || $target === '') {
            return false;
        }

        if (DIRECTORY_SEPARATOR === '\\') {
            $base = strtolower($base);
            $target = strtolower($target);
        }

        if ($target === $base) {
            return true;
        }

        return str_starts_with($target, $base.'/');
    }

    private function normalizeAbsolutePath(string $value): string
    {
        $resolved = @realpath($value);
        $path = is_string($resolved) && $resolved !== ''
            ? $resolved
            : $value;

        return rtrim(str_replace('\\', '/', (string) $path), '/');
    }

    /**
     * @param mixed $raw
     */
    private function parseGatewayClosedAt($raw): Carbon
    {
        if ($raw === null || $raw === '') {
            return now();
        }

        try {
            return Carbon::parse((string) $raw);
        } catch (\Throwable) {
            return now();
        }
    }
}

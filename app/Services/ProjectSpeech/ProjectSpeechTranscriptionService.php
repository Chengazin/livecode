<?php

namespace App\Services\ProjectSpeech;

use Illuminate\Http\UploadedFile;
use RuntimeException;

class ProjectSpeechTranscriptionService
{
    public function transcribeLocal(UploadedFile $audio, ?string $language): ProjectSpeechResult
    {
        $audioPath = $audio->getRealPath();
        if (! is_string($audioPath) || $audioPath === '') {
            return new ProjectSpeechResult(422, [
                'message' => 'Audio file is not readable.',
                'code' => 'speech_invalid_audio',
            ]);
        }

        $pythonBinary = trim((string) config('services.speech.local.python_binary', 'python'));
        if ($pythonBinary === '') {
            $pythonBinary = 'python';
        }

        $scriptPath = $this->resolveScriptPath(
            trim((string) config('services.speech.local.script_path', 'tools/local_transcribe.py'))
        );

        if ($scriptPath === null || ! is_file($scriptPath)) {
            return new ProjectSpeechResult(503, [
                'message' => 'Local speech script was not found.',
                'code' => 'speech_local_unavailable',
            ]);
        }

        $model = trim((string) config('services.speech.local.model', 'small'));
        if ($model === '') {
            $model = 'small';
        }

        $device = trim((string) config('services.speech.local.device', 'auto'));
        if ($device === '') {
            $device = 'auto';
        }

        $computeType = trim((string) config('services.speech.local.compute_type', 'int8'));
        if ($computeType === '') {
            $computeType = 'int8';
        }

        $beamSize = max(1, min(10, (int) config('services.speech.local.beam_size', 1)));
        $useVadFilter = (bool) config('services.speech.local.vad_filter', true);
        $timeout = max(5, (int) config('services.speech.local.timeout', 120));

        $command = [
            $pythonBinary,
            $scriptPath,
            '--input',
            $audioPath,
            '--model',
            $model,
            '--device',
            $device,
            '--compute-type',
            $computeType,
            '--beam-size',
            (string) $beamSize,
        ];

        $normalizedLanguage = $this->normalizeLanguageCode($language);
        if ($normalizedLanguage !== null) {
            $command[] = '--language';
            $command[] = $normalizedLanguage;
        }

        if ($useVadFilter) {
            $command[] = '--vad-filter';
        }

        try {
            [$exitCode, $stdout, $stderr, $timedOut] = $this->runCommand(
                $command,
                dirname($scriptPath),
                $timeout
            );
        } catch (RuntimeException $error) {
            return new ProjectSpeechResult(500, [
                'message' => trim($error->getMessage()) !== '' ? trim($error->getMessage()) : 'Local speech process failed.',
                'code' => 'speech_local_runtime',
            ]);
        }

        if ($timedOut) {
            return new ProjectSpeechResult(504, [
                'message' => 'Local speech transcription timed out.',
                'code' => 'speech_local_timeout',
            ]);
        }

        $payload = $this->decodeJson($stdout);

        if ($exitCode !== 0) {
            $localCode = is_array($payload) ? trim((string) ($payload['code'] ?? '')) : '';
            $localMessage = is_array($payload) ? trim((string) ($payload['message'] ?? '')) : '';
            $message = $localMessage !== ''
                ? $localMessage
                : (trim($stderr) !== '' ? trim($stderr) : 'Local speech transcription failed.');

            $code = $localCode !== '' ? 'speech_local_'.$localCode : 'speech_local_runtime';

            return new ProjectSpeechResult($this->mapLocalErrorStatus($code), [
                'message' => $message,
                'code' => $code,
            ]);
        }

        $text = is_array($payload) ? trim((string) ($payload['text'] ?? '')) : trim($stdout);
        if ($text === '') {
            return new ProjectSpeechResult(422, [
                'message' => 'No speech recognized.',
                'code' => 'speech_no_text',
            ]);
        }

        return new ProjectSpeechResult(200, [
            'status' => 'ok',
            'text' => $text,
            'engine' => 'local',
        ]);
    }

    private function mapLocalErrorStatus(string $code): int
    {
        if ($code === 'speech_local_dependency_missing' || $code === 'speech_local_unavailable') {
            return 503;
        }

        if ($code === 'speech_local_timeout') {
            return 504;
        }

        if ($code === 'speech_local_no_speech') {
            return 422;
        }

        return 500;
    }

    /**
     * @param array<int, string> $command
     * @return array{0:int,1:string,2:string,3:bool}
     */
    private function runCommand(array $command, string $cwd, int $timeoutSeconds): array
    {
        $commandLine = $this->buildCommandLine($command);
        $descriptors = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $pipes = [];
        $process = @proc_open($commandLine, $descriptors, $pipes, $cwd, $this->buildEnv());

        if (! is_resource($process)) {
            throw new RuntimeException('Failed to start local speech process.');
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $startedAt = microtime(true);
        $timedOut = false;

        try {
            while (true) {
                $status = proc_get_status($process);
                $stdout .= stream_get_contents($pipes[1]);
                $stderr .= stream_get_contents($pipes[2]);

                if (! $status['running']) {
                    break;
                }

                if ((microtime(true) - $startedAt) > $timeoutSeconds) {
                    $timedOut = true;
                    proc_terminate($process);
                    break;
                }

                usleep(100000);
            }

            $stdout .= stream_get_contents($pipes[1]);
            $stderr .= stream_get_contents($pipes[2]);
        } finally {
            fclose($pipes[1]);
            fclose($pipes[2]);
        }

        $exitCode = (int) proc_close($process);

        return [$exitCode, trim($stdout), trim($stderr), $timedOut];
    }

    /**
     * @return array<string, string>
     */
    private function buildEnv(): array
    {
        $baseEnv = getenv();
        if (! is_array($baseEnv)) {
            $baseEnv = [];
        }

        return array_merge($baseEnv, [
            'PYTHONIOENCODING' => 'utf-8',
        ]);
    }

    /**
     * @param array<int, string> $command
     */
    private function buildCommandLine(array $command): string
    {
        return implode(' ', array_map('escapeshellarg', $command));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeJson(string $raw): ?array
    {
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return null;
        }

        $decoded = json_decode($trimmed, true);
        if (! is_array($decoded)) {
            return null;
        }

        return $decoded;
    }

    private function resolveScriptPath(string $path): ?string
    {
        $normalized = trim($path);
        if ($normalized === '') {
            return null;
        }

        if ($this->isAbsolutePath($normalized)) {
            return $normalized;
        }

        return base_path(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $normalized));
    }

    private function isAbsolutePath(string $path): bool
    {
        if ($path === '') {
            return false;
        }

        if ($path[0] === '/' || $path[0] === '\\') {
            return true;
        }

        return preg_match('/^[a-zA-Z]:[\\\\\\/]/', $path) === 1;
    }

    private function normalizeLanguageCode(?string $language): ?string
    {
        $value = strtolower(trim((string) $language));
        if ($value === '') {
            return null;
        }

        $value = preg_replace('/[^a-z-]/', '', $value) ?? '';
        if ($value === '') {
            return null;
        }

        $parts = explode('-', $value);
        $primary = trim((string) ($parts[0] ?? ''));
        if ($primary === '') {
            return null;
        }

        if (strlen($primary) > 8) {
            $primary = substr($primary, 0, 8);
        }

        return $primary;
    }
}

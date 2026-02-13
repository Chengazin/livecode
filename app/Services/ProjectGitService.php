<?php

namespace App\Services;

use RuntimeException;
use Symfony\Component\Process\Process;

class ProjectGitService
{
    public function initRepository(string $path, string $defaultBranch = 'main'): void
    {
        if (is_dir($path.DIRECTORY_SEPARATOR.'.git')) {
            return;
        }

        try {
            $this->run(['git', 'init', '-b', $defaultBranch], $path);
        } catch (RuntimeException $e) {
            $this->run(['git', 'init'], $path);
            $this->run(['git', 'checkout', '-b', $defaultBranch], $path);
        }
    }

    public function setRemote(string $path, string $name, string $url): void
    {
        $remotes = array_filter(explode("\n", $this->run(['git', 'remote'], $path)));

        if (in_array($name, $remotes, true)) {
            $this->run(['git', 'remote', 'set-url', $name, $url], $path);
            return;
        }

        $this->run(['git', 'remote', 'add', $name, $url], $path);
    }

    public function hasChanges(string $path): bool
    {
        $status = trim($this->run(['git', 'status', '--porcelain'], $path));

        return $status !== '';
    }

    /**
     * @param array{name:string,email:string} $author
     */
    public function commitAll(string $path, string $message, array $author, bool $allowEmpty = false): bool
    {
        $hasChanges = $this->hasChanges($path);

        if (! $hasChanges && ! $allowEmpty) {
            return false;
        }

        $this->run(['git', 'add', '-A'], $path);

        $args = ['git', 'commit', '-m', $message];
        if ($allowEmpty) {
            $args[] = '--allow-empty';
        }

        $this->run($args, $path, $author);

        return true;
    }

    /**
     * @param array{name:string,email:string} $author
     */
    public function push(string $path, string $remoteUrl, string $branch, string $token, array $author): void
    {
        $authUrl = $this->buildAuthenticatedUrl($remoteUrl, $token);

        $this->run(['git', 'push', '-u', $authUrl, $branch], $path, $author);
    }

    /**
     * @param array{name:string,email:string}|null $author
     */
    private function run(array $command, string $path, ?array $author = null): string
    {
        $process = new Process($command, $path, $this->buildEnv($author));
        $process->setTimeout(120);
        $process->run();

        if (! $process->isSuccessful()) {
            $message = trim($process->getErrorOutput());
            if ($message === '') {
                $message = trim($process->getOutput());
            }

            $message = $this->sanitizeError($message);

            throw new RuntimeException($message === '' ? 'git_command_failed' : $message);
        }

        return trim($process->getOutput());
    }

    /**
     * @param array{name:string,email:string}|null $author
     * @return array<string, string>
     */
    private function buildEnv(?array $author): array
    {
        $env = [
            'GIT_TERMINAL_PROMPT' => '0',
        ];

        if ($author) {
            $env['GIT_AUTHOR_NAME'] = $author['name'];
            $env['GIT_AUTHOR_EMAIL'] = $author['email'];
            $env['GIT_COMMITTER_NAME'] = $author['name'];
            $env['GIT_COMMITTER_EMAIL'] = $author['email'];
        }

        return array_merge($_ENV, $env);
    }

    private function buildAuthenticatedUrl(string $url, string $token): string
    {
        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return $url;
        }

        $user = 'oauth2';
        $pass = rawurlencode($token);

        $auth = $user.':'.$pass.'@';
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $path = $parts['path'] ?? '';
        $query = isset($parts['query']) ? '?'.$parts['query'] : '';
        $fragment = isset($parts['fragment']) ? '#'.$parts['fragment'] : '';

        return $parts['scheme'].'://'.$auth.$parts['host'].$port.$path.$query.$fragment;
    }

    private function sanitizeError(string $message): string
    {
        if ($message === '') {
            return $message;
        }

        return (string) preg_replace('/https?:\\/\\/[^@\\s]+@/i', 'https://***@', $message);
    }
}

<?php

namespace App\Services;

use RuntimeException;

class ProjectGitService
{
    public function initRepository(string $path, string $defaultBranch = 'main'): void
    {
        $git = $this->gitBinary();

        if (is_dir($path.DIRECTORY_SEPARATOR.'.git')) {
            return;
        }

        try {
            $this->run([$git, 'init', '-b', $defaultBranch], $path);
        } catch (RuntimeException $e) {
            $this->run([$git, 'init'], $path);
            $this->run([$git, 'checkout', '-b', $defaultBranch], $path);
        }
    }

    public function setRemote(string $path, string $name, string $url): void
    {
        $git = $this->gitBinary();
        $remotes = array_filter(explode("\n", $this->run([$git, 'remote'], $path)));

        if (in_array($name, $remotes, true)) {
            $this->run([$git, 'remote', 'set-url', $name, $url], $path);
            return;
        }

        $this->run([$git, 'remote', 'add', $name, $url], $path);
    }

    public function hasChanges(string $path): bool
    {
        $status = trim($this->run([$this->gitBinary(), 'status', '--porcelain'], $path));

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

        $git = $this->gitBinary();

        $this->run([$git, 'add', '-A'], $path);

        $args = [$git, 'commit', '-m', $message];
        if ($allowEmpty) {
            $args[] = '--allow-empty';
        }

        $this->run($args, $path, $author);

        return true;
    }

    /**
     * @param array{name:string,email:string} $author
     */
    public function push(string $path, string $remoteUrl, string $branch, string $token, array $author): string
    {
        $authUrl = $this->buildAuthenticatedUrl($remoteUrl, $token);
        $extraEnv = $this->buildNetworkEnvForRemoteUrl($remoteUrl);
        $args = [$this->gitBinary()];

        if ($this->isLocalLikeRemoteHost($remoteUrl)) {
            $args[] = '-c';
            $args[] = 'http.proxy=';
            $args[] = '-c';
            $args[] = 'https.proxy=';
        }

        $args[] = 'push';
        $args[] = '-u';
        $args[] = $authUrl;
        $args[] = $branch;

        return $this->run($args, $path, $author, $extraEnv);
    }

    /**
     * @param array{name:string,email:string} $author
     */
    public function pull(string $path, string $remoteUrl, string $branch, string $token, array $author): string
    {
        $authUrl = $this->buildAuthenticatedUrl($remoteUrl, $token);
        $extraEnv = $this->buildNetworkEnvForRemoteUrl($remoteUrl);
        $args = [$this->gitBinary()];

        if ($this->isLocalLikeRemoteHost($remoteUrl)) {
            $args[] = '-c';
            $args[] = 'http.proxy=';
            $args[] = '-c';
            $args[] = 'https.proxy=';
        }

        $args[] = 'pull';
        $args[] = '--no-rebase';
        $args[] = $authUrl;
        $args[] = $branch;

        return $this->run($args, $path, $author, $extraEnv);
    }

    /**
     * @param array{name:string,email:string}|null $author
     */
    private function run(array $command, string $path, ?array $author = null, array $extraEnv = []): string
    {
        $commandLine = $this->buildCommandLine($command);
        $descriptors = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $pipes = [];
        $process = proc_open($commandLine, $descriptors, $pipes, $path, $this->buildEnv($author, $extraEnv));

        if (! is_resource($process)) {
            throw new RuntimeException('git_command_failed');
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $output = '';
        $errorOutput = '';
        $startedAt = microtime(true);

        while (true) {
            $status = proc_get_status($process);
            $output .= stream_get_contents($pipes[1]);
            $errorOutput .= stream_get_contents($pipes[2]);

            if (! $status['running']) {
                break;
            }

            if ((microtime(true) - $startedAt) > 120) {
                proc_terminate($process);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);
                throw new RuntimeException('git_command_timeout');
            }

            usleep(100000);
        }

        $output .= stream_get_contents($pipes[1]);
        $errorOutput .= stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        $output = trim($output);
        $errorOutput = trim($errorOutput);

        if ($exitCode !== 0) {
            $message = $errorOutput !== '' ? $errorOutput : $output;
            $message = $this->sanitizeError($message);

            throw new RuntimeException($message === '' ? 'git_command_failed' : $message);
        }

        if ($output !== '' && $errorOutput !== '') {
            return $output."\n".$errorOutput;
        }

        return $output !== '' ? $output : $errorOutput;
    }

    /**
     * @param array{name:string,email:string}|null $author
     * @return array<string, string>
     */
    private function buildEnv(?array $author, array $extraEnv = []): array
    {
        $baseEnv = getenv();
        if (! is_array($baseEnv)) {
            $baseEnv = [];
        }

        $env = [
            'GIT_TERMINAL_PROMPT' => '0',
        ];

        if ($author) {
            $env['GIT_AUTHOR_NAME'] = $author['name'];
            $env['GIT_AUTHOR_EMAIL'] = $author['email'];
            $env['GIT_COMMITTER_NAME'] = $author['name'];
            $env['GIT_COMMITTER_EMAIL'] = $author['email'];
        }

        return array_merge($baseEnv, $env, $extraEnv);
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

    /**
     * @return array<string, string>
     */
    private function buildNetworkEnvForRemoteUrl(string $remoteUrl): array
    {
        $parts = parse_url($remoteUrl);
        if (! is_array($parts) || ! isset($parts['host'])) {
            return [];
        }

        $host = strtolower(trim((string) $parts['host']));
        if (! $this->isLocalLikeHost($host)) {
            return [];
        }

        $noProxy = $this->buildNoProxyValue($host);

        return [
            'HTTP_PROXY' => '',
            'HTTPS_PROXY' => '',
            'ALL_PROXY' => '',
            'http_proxy' => '',
            'https_proxy' => '',
            'all_proxy' => '',
            'NO_PROXY' => $noProxy,
            'no_proxy' => $noProxy,
        ];
    }

    private function buildNoProxyValue(string $host): string
    {
        $raw = (string) ($_ENV['NO_PROXY'] ?? $_ENV['no_proxy'] ?? '');
        $items = preg_split('/[\s,;]+/', trim($raw), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $items[] = $host;
        $items[] = 'localhost';
        $items[] = '127.0.0.1';
        $items[] = '::1';
        $items[] = 'forgejo';

        $unique = [];
        foreach ($items as $item) {
            $key = strtolower(trim((string) $item));
            if ($key !== '') {
                $unique[$key] = true;
            }
        }

        return implode(',', array_keys($unique));
    }

    private function buildCommandLine(array $command): string
    {
        $parts = [];

        foreach ($command as $index => $arg) {
            $value = (string) $arg;

            if ($index === 0) {
                $parts[] = $this->quoteArg($value);
                continue;
            }

            if (
                preg_match('/^-{1,2}[A-Za-z0-9].*/', $value) === 1
                || preg_match('/^[A-Za-z0-9._\\/-]+$/', $value) === 1
            ) {
                $parts[] = $value;
                continue;
            }

            $parts[] = $this->quoteArg($value);
        }

        return implode(' ', $parts);
    }

    private function quoteArg(string $value): string
    {
        return '"'.str_replace('"', '\\"', $value).'"';
    }

    private function isLocalLikeRemoteHost(string $remoteUrl): bool
    {
        $parts = parse_url($remoteUrl);
        if (! is_array($parts) || ! isset($parts['host'])) {
            return false;
        }

        return $this->isLocalLikeHost((string) $parts['host']);
    }

    private function isLocalLikeHost(string $host): bool
    {
        return in_array(strtolower(trim($host)), ['localhost', '127.0.0.1', '::1', 'forgejo'], true);
    }

    private function gitBinary(): string
    {
        $custom = trim((string) env('GIT_BINARY', ''));

        return $custom !== '' ? $custom : 'git';
    }
}

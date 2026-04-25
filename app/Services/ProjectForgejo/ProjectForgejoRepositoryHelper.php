<?php

namespace App\Services\ProjectForgejo;

use App\Models\Project;
use Illuminate\Support\Str;

class ProjectForgejoRepositoryHelper
{
    public function sanitizeRepoName(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9._-]+/', '-', $value);
        $value = preg_replace('/-+/', '-', $value);
        $value = trim($value, '-');

        return $value === '' ? 'project' : $value;
    }

    public function isTrustedForgejoCloneUrl(string $cloneUrl): bool
    {
        $repoParts = parse_url($cloneUrl);

        if (! is_array($repoParts) || ! isset($repoParts['scheme'], $repoParts['host'])) {
            return false;
        }

        $scheme = strtolower((string) $repoParts['scheme']);
        if (! in_array($scheme, ['http', 'https'], true)) {
            return false;
        }

        return $this->isTrustedForgejoHost((string) $repoParts['host']);
    }

    public function isTrustedForgejoHost(string $host): bool
    {
        $host = strtolower(trim($host));
        if ($host === '') {
            return false;
        }

        return in_array($host, $this->trustedForgejoHosts(), true);
    }

    /**
     * @return list<string>
     */
    public function trustedForgejoHosts(): array
    {
        $hosts = [];
        $urls = [
            (string) config('services.forgejo.base_url', ''),
            (string) config('services.forgejo.public_url', ''),
            (string) config('services.forgejo.git_base_url', ''),
        ];

        foreach ($urls as $url) {
            $url = trim($url);
            if ($url === '') {
                continue;
            }

            $parts = parse_url($url);
            if (! is_array($parts) || ! isset($parts['host'])) {
                continue;
            }

            $candidate = strtolower(trim((string) $parts['host']));
            if ($candidate !== '') {
                $hosts[$candidate] = true;
            }
        }

        return array_keys($hosts);
    }

    public function forgejoGitBaseUrl(): string
    {
        return rtrim((string) config('services.forgejo.git_base_url', ''), '/');
    }

    /**
     * @return list<string>
     */
    public function buildRepositoryCloneUrlCandidates(string $repoFullName, string $fallbackCloneUrl): array
    {
        $repoFullName = trim($repoFullName, " \t\n\r\0\x0B/");
        $fallbackCloneUrl = trim($fallbackCloneUrl);
        $candidates = [];

        if ($repoFullName !== '') {
            $repoPath = str_ends_with(strtolower($repoFullName), '.git')
                ? $repoFullName
                : $repoFullName.'.git';

            $bases = [
                $this->forgejoGitBaseUrl(),
                rtrim((string) config('services.forgejo.base_url', ''), '/'),
                rtrim((string) config('services.forgejo.public_url', ''), '/'),
            ];

            foreach ($bases as $base) {
                $base = trim($base);
                if ($base === '') {
                    continue;
                }

                $candidates[] = $base.'/'.ltrim($repoPath, '/');
            }
        }

        if ($fallbackCloneUrl !== '') {
            $candidates[] = $fallbackCloneUrl;
        }

        $unique = [];
        foreach ($candidates as $candidate) {
            $value = trim($candidate);
            if ($value === '' || isset($unique[$value])) {
                continue;
            }
            $unique[$value] = true;
        }

        return array_keys($unique);
    }

    public function preferredRepositoryCloneUrl(string $repoFullName, string $fallbackCloneUrl): string
    {
        $candidates = $this->buildRepositoryCloneUrlCandidates($repoFullName, $fallbackCloneUrl);

        return $candidates[0] ?? '';
    }

    public function generatePullRequestBranchName(int $userId): string
    {
        return 'livecode/u'.$userId.'/pr-'.now()->format('Ymd-His').'-'.strtolower(Str::random(6));
    }

    /**
     * @return array{owner: string, repo: string}|null
     */
    public function resolveRepositoryOwnerAndName(Project $project, string $fallbackCloneUrl = ''): ?array
    {
        $fullName = trim((string) ($project->forgejo_repo_full_name ?? ''), " \t\n\r\0\x0B/");
        if ($fullName !== '') {
            if (str_ends_with(strtolower($fullName), '.git')) {
                $fullName = substr($fullName, 0, -4);
            }

            $segments = array_values(array_filter(explode('/', $fullName)));
            if (count($segments) === 2) {
                $owner = trim((string) $segments[0]);
                $repo = trim((string) $segments[1]);

                if ($owner !== '' && $repo !== '') {
                    return [
                        'owner' => $owner,
                        'repo' => $repo,
                    ];
                }
            }
        }

        $candidates = [
            (string) ($project->forgejo_repo_clone_url ?? ''),
            $fallbackCloneUrl,
        ];

        foreach ($candidates as $candidate) {
            $reference = $this->parseRepositoryReference((string) $candidate);
            if ($reference === null) {
                continue;
            }

            return [
                'owner' => $reference['owner'],
                'repo' => $reference['repo'],
            ];
        }

        return null;
    }

    /**
     * @return array{host: string, owner: string, repo: string}|null
     */
    public function parseRepositoryReference(string $value): ?array
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $host = '';
        $path = '';

        if (preg_match('/^[^@\s]+@([^:\/\s]+):(.+)$/', $value, $matches) === 1) {
            $host = (string) $matches[1];
            $path = (string) $matches[2];
        } else {
            $parts = parse_url($value);
            if (! is_array($parts) || ! isset($parts['host'], $parts['path'])) {
                return null;
            }

            $scheme = strtolower((string) ($parts['scheme'] ?? ''));
            if ($scheme !== '' && ! in_array($scheme, ['http', 'https', 'ssh'], true)) {
                return null;
            }

            $host = (string) $parts['host'];
            $path = (string) $parts['path'];
        }

        $host = strtolower(trim($host));
        $path = trim($path);
        $path = ltrim($path, '/');
        $path = rtrim($path, '/');

        if ($host === '' || $path === '') {
            return null;
        }

        if (str_ends_with(strtolower($path), '.git')) {
            $path = substr($path, 0, -4);
        }

        $parts = array_values(array_filter(explode('/', $path)));
        if (count($parts) !== 2) {
            return null;
        }

        $owner = trim((string) $parts[0]);
        $repo = trim((string) $parts[1]);

        if ($owner === '' || $repo === '') {
            return null;
        }

        return [
            'host' => $host,
            'owner' => $owner,
            'repo' => $repo,
        ];
    }
}

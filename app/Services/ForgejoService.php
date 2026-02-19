<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ForgejoService
{
    public function buildAuthorizationUrl(string $state): string
    {
        $baseUrl = $this->publicUrl();

        $params = [
            'client_id' => $this->clientId(),
            'redirect_uri' => $this->redirectUrl(),
            'response_type' => 'code',
            'state' => $state,
        ];

        $scope = $this->scopes();
        if ($scope !== '') {
            $params['scope'] = $scope;
        }

        return $baseUrl.'/login/oauth/authorize?'.http_build_query($params);
    }

    /**
     * @return array<string, mixed>
     */
    public function exchangeCode(string $code): array
    {
        $response = Http::asForm()
            ->acceptJson()
            ->post($this->baseUrl().'/login/oauth/access_token', [
                'client_id' => $this->clientId(),
                'client_secret' => $this->clientSecret(),
                'redirect_uri' => $this->redirectUrl(),
                'grant_type' => 'authorization_code',
                'code' => $code,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException($this->buildErrorMessage('forgejo_token_exchange_failed', $response));
        }

        return (array) $response->json();
    }

    /**
     * @return array<string, mixed>
     */
    public function fetchUser(string $token): array
    {
        $response = $this->fetchUserResponse($token);

        return (array) $response->json();
    }

    /**
     * @return array{user: array<string, mixed>, scopes: string, accepted_scopes: string}
     */
    public function fetchUserWithMeta(string $token): array
    {
        $response = $this->fetchUserResponse($token);

        return [
            'user' => (array) $response->json(),
            'scopes' => (string) ($response->header('X-OAuth-Scopes') ?? ''),
            'accepted_scopes' => (string) ($response->header('X-Accepted-OAuth-Scopes') ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function createRepository(string $token, array $payload): array
    {
        $response = Http::withToken($token, 'token')
            ->acceptJson()
            ->post($this->baseUrl().'/api/v1/user/repos', $payload);

        if (! $response->successful()) {
            throw new RuntimeException($this->buildErrorMessage('forgejo_repo_create_failed', $response));
        }

        return (array) $response->json();
    }

    /**
     * @return array<string, mixed>
     */
    public function getRepository(string $token, string $owner, string $repo): array
    {
        $response = Http::withToken($token, 'token')
            ->acceptJson()
            ->get($this->baseUrl().'/api/v1/repos/'.rawurlencode($owner).'/'.rawurlencode($repo));

        if (! $response->successful()) {
            throw new RuntimeException($this->buildErrorMessage('forgejo_repo_fetch_failed', $response));
        }

        return (array) $response->json();
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function createPullRequest(string $token, string $owner, string $repo, array $payload): array
    {
        $response = Http::withToken($token, 'token')
            ->acceptJson()
            ->post(
                $this->baseUrl().'/api/v1/repos/'.rawurlencode($owner).'/'.rawurlencode($repo).'/pulls',
                $payload
            );

        if (! $response->successful()) {
            throw new RuntimeException($this->buildErrorMessage('forgejo_pull_request_create_failed', $response));
        }

        return (array) $response->json();
    }

    private function baseUrl(): string
    {
        $baseUrl = (string) config('services.forgejo.base_url', '');

        if ($baseUrl === '') {
            throw new RuntimeException('forgejo_base_url_missing');
        }

        return rtrim($baseUrl, '/');
    }

    private function publicUrl(): string
    {
        $publicUrl = (string) config('services.forgejo.public_url', '');

        if ($publicUrl !== '') {
            return rtrim($publicUrl, '/');
        }

        return $this->baseUrl();
    }

    private function clientId(): string
    {
        $clientId = (string) config('services.forgejo.client_id', '');

        if ($clientId === '') {
            throw new RuntimeException('forgejo_client_id_missing');
        }

        return $clientId;
    }

    private function clientSecret(): string
    {
        $clientSecret = (string) config('services.forgejo.client_secret', '');

        if ($clientSecret === '') {
            throw new RuntimeException('forgejo_client_secret_missing');
        }

        return $clientSecret;
    }

    private function redirectUrl(): string
    {
        $redirectUrl = (string) config('services.forgejo.redirect_url', '');

        if ($redirectUrl === '') {
            throw new RuntimeException('forgejo_redirect_url_missing');
        }

        return $redirectUrl;
    }

    private function scopes(): string
    {
        $scopes = trim((string) config('services.forgejo.scopes', ''));
        if ($scopes === '') {
            return '';
        }

        $tokens = preg_split('/[\s,]+/', $scopes, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $normalized = [];

        foreach ($tokens as $token) {
            $scope = strtolower(trim($token));

            if ($scope === 'read:repo') {
                $scope = 'read:repository';
            } elseif ($scope === 'write:repo') {
                $scope = 'write:repository';
            } elseif ($scope === 'repo') {
                $normalized[] = 'read:repository';
                $normalized[] = 'write:repository';
                continue;
            }

            if ($scope !== '') {
                $normalized[] = $scope;
            }
        }

        $normalized = array_values(array_unique($normalized));

        return implode(' ', $normalized);
    }

    private function buildErrorMessage(string $prefix, Response $response): string
    {
        $status = $response->status();
        $details = $this->extractResponseError($response);
        $scopeInfo = $this->extractScopeInfo($response);

        if ($scopeInfo !== '') {
            $details = $details !== '' ? ($details.' | '.$scopeInfo) : $scopeInfo;
        }

        if ($details !== '') {
            return $prefix.' (status '.$status.'): '.$details;
        }

        return $prefix.' (status '.$status.')';
    }

    private function extractResponseError(Response $response): string
    {
        $data = null;

        try {
            $data = $response->json();
        } catch (\Throwable $e) {
            $data = null;
        }

        if (is_array($data)) {
            $message = $data['message'] ?? null;
            if (is_string($message) && $message !== '') {
                return $this->limitText($message);
            }

            $error = $data['error'] ?? null;
            if (is_string($error) && $error !== '') {
                return $this->limitText($error);
            }

            $errors = $data['errors'] ?? null;
            if (is_string($errors) && $errors !== '') {
                return $this->limitText($errors);
            }

            if (is_array($errors)) {
                $flat = [];
                foreach ($errors as $value) {
                    if (is_string($value) && $value !== '') {
                        $flat[] = $value;
                        continue;
                    }

                    if (is_array($value)) {
                        foreach ($value as $item) {
                            if (is_string($item) && $item !== '') {
                                $flat[] = $item;
                            }
                        }
                    }
                }

                if (! empty($flat)) {
                    return $this->limitText(implode('; ', $flat));
                }
            }
        }

        $body = trim($response->body());
        if ($body !== '') {
            return $this->limitText($body);
        }

        return '';
    }

    private function extractScopeInfo(Response $response): string
    {
        $scopes = $response->header('X-OAuth-Scopes');
        $accepted = $response->header('X-Accepted-OAuth-Scopes');

        $parts = [];

        if (is_string($scopes) && trim($scopes) !== '') {
            $parts[] = 'token scopes: '.trim($scopes);
        }

        if (is_string($accepted) && trim($accepted) !== '') {
            $parts[] = 'required scopes: '.trim($accepted);
        }

        return implode('; ', $parts);
    }

    private function limitText(string $text, int $limit = 500): string
    {
        $text = trim($text);

        if (strlen($text) <= $limit) {
            return $text;
        }

        return substr($text, 0, $limit).'...';
    }

    private function fetchUserResponse(string $token): Response
    {
        $response = Http::withToken($token, 'token')
            ->acceptJson()
            ->get($this->baseUrl().'/api/v1/user');

        if (! $response->successful()) {
            throw new RuntimeException($this->buildErrorMessage('forgejo_user_fetch_failed', $response));
        }

        return $response;
    }
}

<?php

namespace App\Services\ProjectForgejo;

class ProjectForgejoGitErrorClassifier
{
    public function isNoCommitRefspecError(string $message): bool
    {
        $normalized = strtolower($message);

        return str_contains($normalized, 'src refspec')
            && str_contains($normalized, 'does not match any');
    }

    public function isPushNoopOutput(string $output): bool
    {
        $normalized = strtolower(trim($output));

        if ($normalized === '') {
            return false;
        }

        return str_contains($normalized, 'everything up-to-date')
            || str_contains($normalized, 'everything up to date');
    }

    public function isPullNoopOutput(string $output): bool
    {
        $normalized = strtolower(trim($output));

        if ($normalized === '') {
            return false;
        }

        return str_contains($normalized, 'already up to date')
            || str_contains($normalized, 'already up-to-date');
    }

    public function isNetworkUnreachableGitError(string $message): bool
    {
        $normalized = strtolower($message);

        return str_contains($normalized, 'couldn\'t connect to server')
            || str_contains($normalized, 'failed to connect to')
            || str_contains($normalized, 'could not resolve host')
            || str_contains($normalized, 'name or service not known')
            || str_contains($normalized, 'getaddrinfo() thread failed to start')
            || str_contains($normalized, 'timed out')
            || str_contains($normalized, 'connection refused');
    }

    public function isLocalChangesGitError(string $message): bool
    {
        $normalized = strtolower($message);

        return str_contains($normalized, 'your local changes to the following files would be overwritten by merge')
            || str_contains($normalized, 'the following untracked working tree files would be overwritten by merge')
            || str_contains($normalized, 'please commit your changes or stash them before you merge');
    }

    public function isMissingRemoteBranchGitError(string $message): bool
    {
        $normalized = strtolower($message);

        return str_contains($normalized, 'couldn\'t find remote ref')
            || str_contains($normalized, 'could not find remote branch')
            || str_contains($normalized, 'no such ref was fetched');
    }
}

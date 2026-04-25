<?php

namespace App\Services\ProjectInfo;

use App\Models\Project;
use App\Models\ProjectParticipant;
use Illuminate\Support\Collection;

class ProjectInfoContributorService
{
    /**
     * @param Collection<int, ProjectParticipant> $participants
     * @return array{
     *   byUserId: array<int, array{user_id: int, name: string, email: string, role: string}>,
     *   byEmail: array<string, array{user_id: int, name: string, email: string, role: string}>
     * }
     */
    public function buildKnownContributorMaps(Project $project, Collection $participants): array
    {
        $byUserId = [];
        $byEmail = [];

        $ownerId = (int) $project->owner_id;
        $ownerName = (string) ($project->owner?->name ?? 'Owner');
        $ownerEmail = (string) ($project->owner?->email ?? '');

        $byUserId[$ownerId] = [
            'user_id' => $ownerId,
            'name' => $ownerName,
            'email' => $ownerEmail,
            'role' => 'owner',
        ];

        if ($ownerEmail !== '') {
            $byEmail[strtolower($ownerEmail)] = $byUserId[$ownerId];
        }

        foreach ($participants as $participant) {
            $participantUserId = (int) $participant->user_id;
            $participantName = (string) ($participant->user?->name ?? ('User #'.$participantUserId));
            $participantEmail = (string) ($participant->user?->email ?? '');
            $participantRole = ProjectParticipant::normalizeRole((string) ($participant->role ?? ProjectParticipant::ROLE_DEVELOPER));

            $byUserId[$participantUserId] = [
                'user_id' => $participantUserId,
                'name' => $participantName,
                'email' => $participantEmail,
                'role' => $participantRole,
            ];

            if ($participantEmail !== '') {
                $byEmail[strtolower($participantEmail)] = $byUserId[$participantUserId];
            }
        }

        return [
            'byUserId' => $byUserId,
            'byEmail' => $byEmail,
        ];
    }

    /**
     * @param list<array{
     *   hash: string,
     *   short_hash: string,
     *   parents: list<string>,
     *   author_name: string,
     *   author_email: string,
     *   authored_at: string,
     *   subject: string,
     *   decorations: string
     * }> $commits
     * @param array<string, array{user_id: int, name: string, email: string, role: string}> $knownByEmail
     * @return list<array{
     *   hash: string,
     *   short_hash: string,
     *   parents: list<string>,
     *   author_name: string,
     *   author_email: string,
     *   authored_at: string,
     *   subject: string,
     *   decorations: string,
     *   contributor_key: string,
     *   user_id: int|null,
     *   role: string
     * }>
     */
    public function enrichCommitsWithKnownContributors(array $commits, array $knownByEmail): array
    {
        $result = [];
        foreach ($commits as $commit) {
            $email = strtolower(trim((string) ($commit['author_email'] ?? '')));
            $known = $email !== '' ? ($knownByEmail[$email] ?? null) : null;

            $result[] = array_merge($commit, [
                'contributor_key' => $known !== null
                    ? 'user:'.$known['user_id']
                    : 'external:'.($email !== '' ? $email : strtolower((string) ($commit['author_name'] ?? 'unknown'))),
                'user_id' => $known['user_id'] ?? null,
                'role' => $known['role'] ?? 'external',
            ]);
        }

        return $result;
    }
}

<?php

namespace App\Services\ProjectInfo;

use Carbon\CarbonImmutable;

class ProjectInfoStatsService
{
    public function __construct(
        private readonly ProjectInfoDateHelper $dateHelper
    ) {
    }

    /**
     * @param array<string, array{user_id: int, name: string, email: string, role: string}> $knownByEmail
     * @param list<array{
     *   hash: string,
     *   short_hash: string,
     *   parents: list<string>,
     *   author_name: string,
     *   author_email: string,
     *   authored_at: string,
     *   subject: string,
     *   decorations: string,
     *   contributor_key?: string,
     *   user_id?: int|null,
     *   role?: string
     * }> $commits
     * @return array{
     *   period_days: int,
     *   from: string,
     *   to: string,
     *   total_commits: int,
     *   timeline: list<array{
     *     date: string,
     *     commits: int,
     *     total: int
     *   }>,
     *   contributors: list<array{
     *     key: string,
     *     user_id: int|null,
     *     name: string,
     *     email: string,
     *     role: string,
     *     commit_count: int,
     *     last_activity_at: string|null
     *   }>
     * }
     */
    public function buildStats(array $knownByEmail, array $commits, int $periodDays): array
    {
        $now = CarbonImmutable::now();
        $from = $now->subDays($periodDays);
        $timelineByDate = [];
        $cursor = $from->startOfDay();
        $lastDay = $now->startOfDay();
        while ($cursor->lessThanOrEqualTo($lastDay)) {
            $dayKey = $cursor->toDateString();
            $timelineByDate[$dayKey] = [
                'date' => $dayKey,
                'commits' => 0,
                'total' => 0,
            ];
            $cursor = $cursor->addDay();
        }

        $contributors = [];
        $totalCommits = 0;
        foreach ($commits as $commit) {
            $authoredAt = $this->dateHelper->safeParseIsoDate((string) ($commit['authored_at'] ?? ''));
            if ($authoredAt === null || $authoredAt->lt($from)) {
                continue;
            }

            $totalCommits++;
            $email = strtolower(trim((string) ($commit['author_email'] ?? '')));
            $known = $email !== '' ? ($knownByEmail[$email] ?? null) : null;

            $key = $known !== null
                ? 'user:'.$known['user_id']
                : 'external:'.($email !== '' ? $email : strtolower((string) ($commit['author_name'] ?? 'unknown')));

            if (! isset($contributors[$key])) {
                $contributors[$key] = [
                    'key' => $key,
                    'user_id' => $known['user_id'] ?? null,
                    'name' => $known['name'] ?? (string) ($commit['author_name'] ?? 'Unknown'),
                    'email' => $known['email'] ?? (string) ($commit['author_email'] ?? ''),
                    'role' => $known['role'] ?? 'external',
                    'commit_count' => 0,
                    'last_activity_at' => null,
                ];
            }

            $contributors[$key]['commit_count']++;
            $contributors[$key]['last_activity_at'] = $this->dateHelper->maxIsoDate(
                $contributors[$key]['last_activity_at'],
                $authoredAt->toIso8601String()
            );

            $dayKey = $authoredAt->toDateString();
            if (! isset($timelineByDate[$dayKey])) {
                $timelineByDate[$dayKey] = [
                    'date' => $dayKey,
                    'commits' => 0,
                    'total' => 0,
                ];
            }
            $timelineByDate[$dayKey]['commits']++;
        }

        $contributors = array_values(array_filter(
            $contributors,
            fn (array $item): bool => (int) $item['commit_count'] > 0
        ));

        usort($contributors, function (array $left, array $right): int {
            $leftScore = (int) $left['commit_count'];
            $rightScore = (int) $right['commit_count'];

            if ($leftScore !== $rightScore) {
                return $rightScore <=> $leftScore;
            }

            $leftDate = (string) ($left['last_activity_at'] ?? '');
            $rightDate = (string) ($right['last_activity_at'] ?? '');

            return strcmp($rightDate, $leftDate);
        });

        ksort($timelineByDate);
        $timeline = array_values(array_map(function (array $point): array {
            $point['total'] = (int) $point['commits'];

            return $point;
        }, $timelineByDate));

        return [
            'period_days' => $periodDays,
            'from' => $from->toIso8601String(),
            'to' => $now->toIso8601String(),
            'total_commits' => $totalCommits,
            'timeline' => $timeline,
            'contributors' => $contributors,
        ];
    }
}

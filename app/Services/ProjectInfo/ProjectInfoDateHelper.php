<?php

namespace App\Services\ProjectInfo;

use Carbon\CarbonImmutable;

class ProjectInfoDateHelper
{
    public function safeParseIsoDate(string $value): ?CarbonImmutable
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    public function maxIsoDate(?string $left, ?string $right): ?string
    {
        $leftDate = $this->safeParseIsoDate((string) $left);
        $rightDate = $this->safeParseIsoDate((string) $right);

        if ($leftDate === null) {
            return $rightDate?->toIso8601String();
        }

        if ($rightDate === null) {
            return $leftDate->toIso8601String();
        }

        return $leftDate->greaterThan($rightDate)
            ? $leftDate->toIso8601String()
            : $rightDate->toIso8601String();
    }
}

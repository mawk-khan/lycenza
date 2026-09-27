<?php

namespace App\Support\Domains;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ADR 0054 section 12: the scrape-time custom-domain gauges -- counts by
 * state, the fewest days any ACTIVE domain's certificate has left, and how
 * long the oldest live domain has had only indeterminate checks. Three
 * bounded aggregate reads of platform data; no identifier leaves here.
 */
final class DomainSignals
{
    /** @return array<string, int> state => rows */
    public function countsByState(): array
    {
        $counts = DB::table('school_domains')->selectRaw('state, count(*) as n')->groupBy('state')->pluck('n', 'state');

        $result = [];
        foreach (DomainState::cases() as $state) {
            $result[$state->value] = (int) ($counts[$state->value] ?? 0);
        }

        return $result;
    }

    /** Whole days until the earliest ACTIVE certificate expiry (negative once expired), or null with none recorded. */
    public function certificateMinDaysRemaining(): ?int
    {
        $earliest = DB::table('school_domains')
            ->where('state', DomainState::Active->value)
            ->whereNotNull('certificate_not_after')
            ->min('certificate_not_after');

        return $earliest !== null ? (int) floor(now()->diffInSeconds(Carbon::parse((string) $earliest), false) / 86400) : null;
    }

    /** Seconds the longest-running indeterminate streak on a live domain has lasted (0 with none). */
    public function indeterminateMaxAgeSeconds(): int
    {
        $oldest = DB::table('school_domains')
            ->whereIn('state', [DomainState::Active->value, DomainState::Suspended->value])
            ->whereNotNull('indeterminate_since')
            ->min('indeterminate_since');

        return $oldest !== null ? max(0, (int) Carbon::parse((string) $oldest)->diffInSeconds(now(), true)) : 0;
    }
}

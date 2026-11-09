<?php

namespace Tests\Support;

use App\Domain\Leave\Application\Calendar\StaffCalendarHistory;
use App\Models\School;
use App\Support\Testing\LocalCatalogueFixtures;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/**
 * HR.R1-C22 (WO-5, D6-3): a fixture School's staff-calendar history start, written as the WO-5 migration writes
 * one -- a `cutover` week version only the owner may record -- through the local/testing-only owner seam. At
 * runtime a School's history starts today or later and is never backfilled; a test needs history before the
 * dates it records evidence on. Later weeks go through `StaffCalendarService`.
 */
final class StaffCalendarFixture
{
    public const HISTORY_START = '2012-01-01';

    /** @param array<int, string> $pattern ISO weekday => full | first_half | off */
    public static function startHistory(School $school, array $pattern, string $from = self::HISTORY_START): void
    {
        if (app(StaffCalendarHistory::class)->historyStart($school) !== null) {
            throw new LogicException('This School already has calendar history; record later weeks through StaffCalendarService.');
        }
        ksort($pattern);
        LocalCatalogueFixtures::asOwner(fn () => DB::table('staff_working_week_versions')->insert([
            'id' => (string) Str::uuid7(), 'school_id' => $school->id, 'effective_from' => $from,
            ...collect($pattern)->mapWithKeys(fn (string $portion, int $day) => ["weekday_{$day}" => $portion])->all(),
            'status' => 'active', 'reason_code' => 'cutover', 'recorded_at' => now(),
        ]));
    }
}

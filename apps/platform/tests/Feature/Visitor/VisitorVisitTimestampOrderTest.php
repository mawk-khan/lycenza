<?php

namespace Tests\Feature\Visitor;

use App\Domain\Visitor\Application\VisitorVisitService;
use App\Domain\Visitor\Infrastructure\VisitorVisit;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantRls;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\UuidV7;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * S3 (ADR 0068 §27.11): a Visit's check-out never precedes its check-in.
 *
 * The flake: a fixture passed `checked_out_at => now()`, evaluated BEFORE the
 * factory's own `checked_in_at => now()`. Both are written at whole-second
 * precision, so the row broke `visitor_visits_checkout_after_checkin_check`
 * whenever a second boundary fell between the two reads -- no backward clock
 * needed. Production check-out had the cousin: `now()` against a stored
 * check-in, refused if this node's clock reads earlier.
 *
 * The clock is driven per call (Carbon test-now sequences, reset after each
 * test by the framework); nothing sleeps or depends on real elapsed time.
 */
class VisitorVisitTimestampOrderTest extends TestCase
{
    use CreatesTenancyFixtures;

    /** Each clock read returns the next instant (the last one repeats). */
    private function clockReads(string ...$instants): void
    {
        $read = 0;
        Carbon::setTestNow(function () use (&$read, $instants): Carbon {
            return Carbon::parse($instants[min($read++, count($instants) - 1)]);
        });
    }

    /** @return array{0: string, 1: string} the stored check-in / check-out, as PostgreSQL holds them */
    private function stored(VisitorVisit $visit): array
    {
        $row = app(TenantContext::class)->withSchool($visit->school, fn () => DB::table('visitor_visits')->where('id', $visit->id)->first(['checked_in_at', 'checked_out_at']));

        return [(string) $row->checked_in_at, (string) $row->checked_out_at];
    }

    #[Test]
    public function the_checked_out_fixture_is_ordered_whatever_the_clock_does(): void
    {
        $school = $this->createSchool();
        $campus = $this->createCampus($school);

        foreach ([
            'forward across a second boundary' => ['2026-10-08 10:00:00.999990', '2026-10-08 10:00:01.000010'],
            'backward step' => ['2026-10-08 10:00:05', '2026-10-08 10:00:00'],
            'frozen' => ['2026-10-08 10:00:00.5'],
        ] as $case => $reads) {
            $visitor = $this->createVisitor($school);
            $this->clockReads(...$reads);
            $visit = $this->createCheckedOutVisitorVisit($visitor, $campus);
            Carbon::setTestNow();

            [$in, $out] = $this->stored($visit);
            $this->assertSame('checked_out', $visit->status, $case);
            $this->assertGreaterThan($in, $out, $case);
        }
    }

    #[Test]
    public function the_checked_out_fixture_derives_from_an_overridden_check_in(): void
    {
        $school = $this->createSchool();
        $visit = $this->createCheckedOutVisitorVisit($this->createVisitor($school), $this->createCampus($school), ['checked_in_at' => '2025-02-01 09:00:00']);

        $this->assertSame(['2025-02-01 09:00:00', '2025-02-01 09:30:00'], $this->stored($visit));
    }

    #[Test]
    public function a_check_out_on_a_clock_behind_the_check_in_is_recorded_at_the_check_in(): void
    {
        [$admin, $school] = $this->createSchoolAdmin();
        $campus = $this->createCampus($school);
        $service = app(VisitorVisitService::class);
        $within = fn (callable $fn) => app(TenantContext::class)->withSchool($school, $fn);

        // A backward step (or a node whose clock is 5 s behind the one that checked in).
        $this->travelTo(Carbon::parse('2026-10-08 11:00:00'));
        $visit = $within(fn () => $service->checkIn($this->createVisitor($school), $campus, null, 'Meeting', null, $admin));
        $this->travelTo(Carbon::parse('2026-10-08 10:59:55'));
        $out = $within(fn () => $service->checkOut($visit, $admin));
        $this->assertSame(['checked_out', '2026-10-08 11:00:00', '2026-10-08 11:00:00'], [$out->status, ...$this->stored($out)]);
        $this->assertEquals($visit->checked_in_at, $out->checked_in_at, 'the check-in is never rewritten');

        // An ordinary clock: the check-out is the current instant.
        $this->travelTo(Carbon::parse('2026-10-08 12:00:00'));
        $visit = $within(fn () => $service->checkIn($this->createVisitor($school), $campus, null, 'Meeting', null, $admin));
        $this->travelTo(Carbon::parse('2026-10-08 12:45:10'));
        $out = $within(fn () => $service->checkOut($visit, $admin));
        $this->assertSame(['2026-10-08 12:00:00', '2026-10-08 12:45:10'], $this->stored($out));
    }

    #[Test]
    public function the_database_still_refuses_a_check_out_before_the_check_in(): void
    {
        $school = $this->createSchool();
        $campus = $this->createCampus($school);
        $visitor = $this->createVisitor($school);
        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $school->id]);

        $rejected = null;
        try {
            DB::connection('pgsql')->transaction(fn () => DB::connection('pgsql')->table('visitor_visits')->insert([
                'id' => (string) new UuidV7, 'school_id' => $school->id, 'visitor_id' => $visitor->id, 'campus_id' => $campus->id,
                'purpose' => 'Invalid interval', 'status' => 'checked_out',
                'checked_in_at' => '2026-10-08 10:00:01', 'checked_out_at' => '2026-10-08 10:00:00',
                'created_at' => now(), 'updated_at' => now(),
            ]));
        } catch (QueryException $e) {
            $rejected = $e->getMessage();
        }

        $this->assertNotNull($rejected);
        $this->assertStringContainsString('visitor_visits_checkout_after_checkin_check', (string) $rejected);
    }
}

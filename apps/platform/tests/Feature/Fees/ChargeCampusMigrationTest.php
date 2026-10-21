<?php

namespace Tests\Feature\Fees;

use App\Domain\Fees\Infrastructure\Charge;
use App\Domain\Students\Application\StudentEnrollmentService;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Fees\Concerns\CreatesLateFeeFixtures;
use Tests\TestCase;

/**
 * ORG.7G-1A (ADR 0076 §49): the migration's backfill derivation and its
 * rollback guard, run against real fixtures (under the School's RLS):
 * - every provenance resolves to the Campus its creator now stores;
 * - a Charge whose creation-date placement has since changed is ambiguous
 *   (the backfill would abort, never guess);
 * - rollback is refused once a stored Campus differs from what the old
 *   runtime derivation would return (dropping it would re-home debt).
 */
class ChargeCampusMigrationTest extends TestCase
{
    use CreatesLateFeeFixtures;

    private function migration(): Migration
    {
        return require database_path('migrations/2026_12_26_090000_add_charge_campus_attribution.php');
    }

    /** @return array<string, object{id: string, source: string, campus_id: ?string, ambiguity: ?string}> */
    private function derived(array $w): array
    {
        $m = $this->migration();

        return $this->inSchool($w['school'], fn () => collect(DB::select('SELECT * FROM ('.$m->derivationSql().') r'))->keyBy('id')->all());
    }

    private function blockers(array $w): int
    {
        $m = $this->migration();

        return $this->inSchool($w['school'], fn () => $m->rollbackBlockers(DB::connection()));
    }

    #[Test]
    public function the_backfill_derivation_reproduces_what_every_creator_stores(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-06-05T06:00:00Z'));
        $w = $this->lateFeeWorld();
        $this->travelTo(CarbonImmutable::parse('2026-06-11T06:00:00Z'));
        $this->executedLateRun($w, $this->activeRule($w));

        $derived = $this->derived($w);
        $stored = $this->inSchool($w['school'], fn () => Charge::query()->pluck('campus_id', 'id')->all());

        $this->assertEqualsCanonicalizing(['fee_run', 'late_fee', 'manual'], array_values(array_unique(array_map(fn ($r) => $r->source, $derived))));
        foreach ($stored as $id => $campus) {
            $this->assertNull($derived[$id]->ambiguity, "{$id} is unambiguous");
            $this->assertSame($campus, $derived[$id]->campus_id, "{$derived[$id]->source} {$id}: the backfill rule equals the creator's stored Campus");
        }
        $this->assertSame(0, $this->blockers($w), 'nothing would be lost by a rollback while the old derivation still agrees');
    }

    #[Test]
    public function a_changed_creation_date_placement_is_ambiguous_and_blocks_rollback(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-06-01T06:00:00Z'));
        $w = $this->concessionWorld();
        $b = $this->createCampus($w['school'], ['name' => 'South']);
        $student = $this->createStudent($w['school']);
        $onA = $this->createStudentEnrollment($student, $this->createSection($w['year'], $w['campus'], $w['grade'], ['code' => 'SA', 'status' => 'active']), ['starts_on' => '2026-06-01', 'status' => 'active']);

        $this->travelTo(CarbonImmutable::parse('2026-08-01T06:00:00Z'));
        $onB = $this->inSchool($w['school'], fn () => app(StudentEnrollmentService::class)->transferPlacement(StudentEnrollment::query()->findOrFail($onA->id), $this->createSection($w['year'], $b, $w['grade'], ['code' => 'SB', 'status' => 'active']), '9', '2026-08-01'));
        $this->travelTo(CarbonImmutable::parse('2026-09-01T06:00:00Z'));
        $charge = $this->assessCharge($w['school'], $student, $w['year'], $w['receivable'], $w['revenue'], '10.00');
        $this->assertSame($b->id, $charge->campus_id);

        $this->assertNull($this->derived($w)[$charge->id]->ambiguity);
        $this->assertSame(0, $this->blockers($w));

        // The B placement is cancelled after the Charge: today's stored Campus
        // is still B, the old runtime derivation would now say A.
        $this->travelTo(CarbonImmutable::parse('2026-09-20T06:00:00Z'));
        $this->inSchool($w['school'], fn () => app(StudentEnrollmentService::class)->cancel($onB->fresh(), '2026-09-20'));

        $this->assertSame('a placement in effect when it was created has since changed', $this->derived($w)[$charge->id]->ambiguity, 'a backfill would abort here, never guess');
        $this->assertSame(1, $this->blockers($w), 'rolling back would re-home this debt to A, so down() refuses');
        $this->assertSame($b->id, $this->inSchool($w['school'], fn () => Charge::query()->findOrFail($charge->id)->campus_id));
    }

    #[Test]
    public function a_placement_recorded_later_but_backdated_over_the_creation_date_is_ambiguous(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-07-01T06:00:00Z'));
        $w = $this->concessionWorld();
        $student = $this->createStudent($w['school']);
        $charge = $this->assessCharge($w['school'], $student, $w['year'], $w['receivable'], $w['revenue'], '10.00');
        $this->assertNull($charge->campus_id, 'no placement that day: whole-School');

        $this->travelTo(CarbonImmutable::parse('2026-08-01T06:00:00Z'));
        $this->createStudentEnrollment($student, $this->createSection($w['year'], $w['campus'], $w['grade'], ['code' => 'SA', 'status' => 'active']), ['starts_on' => '2026-06-01', 'status' => 'active']);

        $this->assertSame('a placement covering the creation date was recorded after it', $this->derived($w)[$charge->id]->ambiguity, 'a backfill never silently adopts a backdated placement');
        $this->assertSame(1, $this->blockers($w), 'the old derivation would now say A for a whole-School Charge');
    }
}

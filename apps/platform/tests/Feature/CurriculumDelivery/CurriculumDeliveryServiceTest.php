<?php

namespace Tests\Feature\CurriculumDelivery;

use App\Domain\CurriculumDelivery\Application\CurriculumDeliveryService;
use App\Domain\CurriculumDelivery\Application\Exceptions\AcademicYearNotActiveException;
use App\Domain\CurriculumDelivery\Application\Exceptions\CompletionDateNotAllowedException;
use App\Domain\CurriculumDelivery\Application\Exceptions\CompletionDateRequiredException;
use App\Domain\CurriculumDelivery\Application\Exceptions\DeliveryContextMismatchException;
use App\Domain\CurriculumDelivery\Application\Exceptions\DeliveryDateInFutureException;
use App\Domain\CurriculumDelivery\Application\Exceptions\DeliveryDateOrderException;
use App\Domain\CurriculumDelivery\Application\Exceptions\DeliveryDateOutsideAcademicYearException;
use App\Domain\CurriculumDelivery\Application\Exceptions\DeliveryStatusChangedException;
use App\Domain\CurriculumDelivery\Application\Exceptions\DuplicateDeliveryException;
use App\Domain\CurriculumDelivery\Application\Exceptions\NoOpTransitionException;
use App\Domain\CurriculumDelivery\Application\Exceptions\RequiredSubjectOfferingOnlyException;
use App\Domain\CurriculumDelivery\Infrastructure\CurriculumDelivery;
use App\Support\Tenancy\SchoolTimezone;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\CurriculumDelivery\Concerns\CreatesCurriculumDeliveryFixtures;
use Tests\TestCase;

/**
 * Phase 0H.3B -- the Application service's own invariants: server-
 * derived context, the required-Offering restriction, every date rule
 * (School-local, not-future, inside the AcademicYear, correctly
 * ordered), the closed two-state machine with its compare-and-swap,
 * and the historical-correction discipline that keeps a CLOSED
 * AcademicYear from making a genuine clerical fix impossible.
 */
class CurriculumDeliveryServiceTest extends TestCase
{
    use CreatesCurriculumDeliveryFixtures;

    private function service(): CurriculumDeliveryService
    {
        return app(CurriculumDeliveryService::class);
    }

    private function start(array $w, ?string $startedOn = null): CurriculumDelivery
    {
        return $this->service()->start(
            $w['school'], $w['offering']->id, $w['section']->id, $w['unit']->id,
            $startedOn ?? $this->today()->subDays(3)->toDateString(), $w['actor'],
        );
    }

    // --- server-derived context ---------------------------------------

    #[Test]
    public function every_structural_pin_is_derived_from_the_resolved_parents(): void
    {
        $w = $this->deliveryWorld();
        $delivery = $this->start($w);

        $this->assertSame($w['school']->id, $delivery->school_id);
        $this->assertSame($w['offering']->id, $delivery->subject_offering_id);
        $this->assertSame($w['offering']->academic_year_id, $delivery->academic_year_id);
        $this->assertSame($w['offering']->campus_id, $delivery->campus_id);
        $this->assertSame($w['offering']->grade_level_id, $delivery->grade_level_id);
        $this->assertSame(CurriculumDelivery::STATUS_IN_PROGRESS, $delivery->status);
        $this->assertNull($delivery->completed_on);
    }

    #[Test]
    public function the_structural_pins_are_not_mass_assignable(): void
    {
        // Even if a future caller passed them straight through, fill()
        // must drop them -- they are absent from $fillable by design.
        $model = new CurriculumDelivery([
            'subject_offering_id' => 'attacker',
            'academic_year_id' => 'attacker',
            'campus_id' => 'attacker',
            'grade_level_id' => 'attacker',
        ]);

        $this->assertNull($model->subject_offering_id);
        $this->assertNull($model->academic_year_id);
        $this->assertNull($model->campus_id);
        $this->assertNull($model->grade_level_id);
    }

    // --- required-Offering restriction ---------------------------------

    #[Test]
    public function a_required_offering_is_accepted_and_an_elective_is_rejected(): void
    {
        $required = $this->deliveryWorld();
        $this->assertNotNull($this->start($required)->id);

        $elective = $this->deliveryWorld(offeringAttributes: ['is_required' => false]);
        $this->expectException(RequiredSubjectOfferingOnlyException::class);
        $this->start($elective);
    }

    #[Test]
    public function a_unit_from_another_offering_is_rejected(): void
    {
        $w = $this->deliveryWorld();
        $other = $this->createSubjectOffering(
            $w['year'], $w['campus'], $w['grade'], $this->createSubject($w['school'], ['code' => 'SCI']),
            ['is_required' => true, 'status' => 'active'],
        );
        $otherUnit = $this->createSyllabusUnitFor($other, ['code' => 'S1']);

        $this->expectException(DeliveryContextMismatchException::class);
        $this->service()->start(
            $w['school'], $w['offering']->id, $w['section']->id, $otherUnit->id,
            $this->today()->toDateString(), $w['actor'],
        );
    }

    #[Test]
    public function a_section_in_a_different_context_is_rejected(): void
    {
        $w = $this->deliveryWorld();
        $otherGrade = $this->createGradeLevel($w['school'], ['code' => 'G9', 'sequence' => 9]);
        $foreignSection = $this->createSection($w['year'], $w['campus'], $otherGrade, ['code' => 'B']);

        $this->expectException(DeliveryContextMismatchException::class);
        $this->service()->start(
            $w['school'], $w['offering']->id, $foreignSection->id, $w['unit']->id,
            $this->today()->toDateString(), $w['actor'],
        );
    }

    // --- AcademicYear rules --------------------------------------------

    #[Test]
    public function a_new_delivery_requires_an_active_academic_year(): void
    {
        $w = $this->deliveryWorld(yearAttributes: ['status' => 'closed']);

        $this->expectException(AcademicYearNotActiveException::class);
        $this->start($w);
    }

    #[Test]
    public function a_closed_year_still_allows_correction_and_transition(): void
    {
        // The historical-correction discipline: the year closes AFTER
        // the delivery exists, and a genuine clerical fix must remain
        // possible forever.
        $w = $this->deliveryWorld();
        $delivery = $this->start($w, $this->today()->subDays(10)->toDateString());

        $this->inDeliverySchool($w['school'], fn () => $w['year']->update(['status' => 'closed']));

        $corrected = $this->service()->correctDates(
            $w['school'], $delivery->id, $this->today()->subDays(12)->toDateString(), null, $w['actor'],
        );
        $this->assertSame($this->today()->subDays(12)->toDateString(), $corrected->started_on->toDateString());

        $completed = $this->service()->transition(
            $w['school'], $delivery->id, 'in_progress', 'completed',
            $this->today()->subDay()->toDateString(), $w['actor'],
        );
        $this->assertSame('completed', $completed->status);
    }

    // --- date rules ------------------------------------------------------

    #[Test]
    public function todays_school_local_date_is_accepted_and_tomorrow_is_rejected(): void
    {
        $w = $this->deliveryWorld();

        // Evaluated in the SCHOOL's timezone, not UTC.
        $schoolToday = CarbonImmutable::now(SchoolTimezone::resolve($w['school']))->toDateString();
        $this->assertSame($schoolToday, $this->start($w, $schoolToday)->started_on->toDateString());

        $w2 = $this->deliveryWorld();
        $tomorrow = CarbonImmutable::now(SchoolTimezone::resolve($w2['school']))->addDay()->toDateString();

        $this->expectException(DeliveryDateInFutureException::class);
        $this->start($w2, $tomorrow);
    }

    #[Test]
    public function a_date_before_the_academic_year_is_rejected(): void
    {
        $w = $this->deliveryWorld();
        $beforeYear = $w['year']->starts_on->subDay()->toDateString();

        $this->expectException(DeliveryDateOutsideAcademicYearException::class);
        $this->start($w, $beforeYear);
    }

    #[Test]
    public function a_date_after_the_academic_year_is_rejected(): void
    {
        // A year that already ENDED but is still active, so the
        // after-the-year rule can be exercised without the not-future
        // rule firing first.
        $w = $this->deliveryWorld(yearAttributes: [
            'status' => 'active',
            'starts_on' => $this->today()->subYears(2)->toDateString(),
            'ends_on' => $this->today()->subYear()->toDateString(),
        ]);

        $this->expectException(DeliveryDateOutsideAcademicYearException::class);
        $this->start($w, $this->today()->subDays(30)->toDateString());
    }

    #[Test]
    public function a_completion_date_before_the_start_date_is_rejected(): void
    {
        $w = $this->deliveryWorld();
        $delivery = $this->start($w, $this->today()->subDays(3)->toDateString());

        $this->expectException(DeliveryDateOrderException::class);
        $this->service()->transition(
            $w['school'], $delivery->id, 'in_progress', 'completed',
            $this->today()->subDays(10)->toDateString(), $w['actor'],
        );
    }

    #[Test]
    public function a_correction_revalidates_every_applicable_date_rule(): void
    {
        // PATCH must NOT be a back door around the invariants merely
        // because the row already exists.
        $w = $this->deliveryWorld();
        $delivery = $this->start($w, $this->today()->subDays(3)->toDateString());

        try {
            $this->service()->correctDates(
                $w['school'], $delivery->id, $this->today()->addDay()->toDateString(), null, $w['actor'],
            );
            $this->fail('A correction to a future date must be rejected.');
        } catch (DeliveryDateInFutureException) {
            // expected
        }

        try {
            $this->service()->correctDates(
                $w['school'], $delivery->id, $w['year']->starts_on->subDay()->toDateString(), null, $w['actor'],
            );
            $this->fail('A correction to a date outside the AcademicYear must be rejected.');
        } catch (DeliveryDateOutsideAcademicYearException) {
            // expected
        }

        // ...and the ordering rule still holds on a completed row.
        $this->service()->transition(
            $w['school'], $delivery->id, 'in_progress', 'completed',
            $this->today()->subDay()->toDateString(), $w['actor'],
        );

        $this->expectException(DeliveryDateOrderException::class);
        $this->service()->correctDates(
            $w['school'], $delivery->id, $this->today()->toDateString(), null, $w['actor'],
        );
    }

    #[Test]
    public function a_completion_date_cannot_be_introduced_by_a_correction(): void
    {
        $w = $this->deliveryWorld();
        $delivery = $this->start($w);

        $this->expectException(CompletionDateNotAllowedException::class);
        $this->service()->correctDates(
            $w['school'], $delivery->id, null, $this->today()->toDateString(), $w['actor'],
        );
    }

    // --- state machine ----------------------------------------------------

    #[Test]
    public function both_legal_transitions_are_supported_and_reopening_clears_the_completion_date(): void
    {
        $w = $this->deliveryWorld();
        $delivery = $this->start($w, $this->today()->subDays(5)->toDateString());
        $completedOn = $this->today()->subDay()->toDateString();

        $completed = $this->service()->transition(
            $w['school'], $delivery->id, 'in_progress', 'completed', $completedOn, $w['actor'],
        );
        $this->assertSame('completed', $completed->status);
        $this->assertSame($completedOn, $completed->completed_on->toDateString());

        $reopened = $this->service()->transition(
            $w['school'], $delivery->id, 'completed', 'in_progress', null, $w['actor'],
        );
        $this->assertSame('in_progress', $reopened->status);
        $this->assertNull($reopened->completed_on);
    }

    #[Test]
    public function a_stale_expected_status_is_refused(): void
    {
        $w = $this->deliveryWorld();
        $delivery = $this->start($w, $this->today()->subDays(5)->toDateString());

        // A colleague completes it first.
        $this->service()->transition(
            $w['school'], $delivery->id, 'in_progress', 'completed',
            $this->today()->subDay()->toDateString(), $w['actor'],
        );

        $this->expectException(DeliveryStatusChangedException::class);
        $this->service()->transition(
            $w['school'], $delivery->id, 'in_progress', 'completed',
            $this->today()->toDateString(), $w['actor'],
        );
    }

    #[Test]
    public function a_no_op_transition_is_rejected(): void
    {
        $w = $this->deliveryWorld();
        $delivery = $this->start($w);

        $this->expectException(NoOpTransitionException::class);
        $this->service()->transition(
            $w['school'], $delivery->id, 'in_progress', 'in_progress', null, $w['actor'],
        );
    }

    #[Test]
    public function completing_without_a_completion_date_is_rejected(): void
    {
        $w = $this->deliveryWorld();
        $delivery = $this->start($w);

        $this->expectException(CompletionDateRequiredException::class);
        $this->service()->transition(
            $w['school'], $delivery->id, 'in_progress', 'completed', null, $w['actor'],
        );
    }

    // --- duplicate handling ------------------------------------------------

    #[Test]
    public function a_duplicate_section_and_unit_is_translated_to_a_domain_exception(): void
    {
        $w = $this->deliveryWorld();
        $this->start($w);

        $this->expectException(DuplicateDeliveryException::class);
        $this->start($w);
    }

    #[Test]
    public function a_different_section_may_cover_the_same_unit(): void
    {
        // Per-Section variation is the entire point of this table.
        $w = $this->deliveryWorld();
        $sectionB = $this->createSection($w['year'], $w['campus'], $w['grade'], ['code' => 'B']);

        $this->start($w);
        $second = $this->service()->start(
            $w['school'], $w['offering']->id, $sectionB->id, $w['unit']->id,
            $this->today()->toDateString(), $w['actor'],
        );

        $this->assertNotNull($second->id);
        $this->assertSame(2, $this->inDeliverySchool(
            $w['school'], fn () => CurriculumDelivery::query()->count(),
        ));
    }

    #[Test]
    public function an_unrelated_unique_violation_is_not_mislabelled_as_a_duplicate(): void
    {
        // The service matches the SPECIFIC named constraint, never any
        // UniqueConstraintViolationException. Proven here by asserting
        // the exact constraint name the translation keys on still
        // exists -- if it were ever renamed, the catch would silently
        // stop translating and every duplicate would become a 500.
        $constraint = DB::connection('pgsql_admin')->selectOne(
            'select conname from pg_constraint where conrelid = ?::regclass and conname = ?',
            ['curriculum_deliveries', 'curriculum_deliveries_section_unit_unique'],
        );

        $this->assertNotNull($constraint, 'The unique constraint the duplicate translation keys on must exist.');

        $source = file_get_contents(app_path('Domain/CurriculumDelivery/Application/CurriculumDeliveryService.php'));
        $this->assertStringContainsString("'curriculum_deliveries_section_unit_unique'", $source);
        $this->assertStringContainsString('throw $e;', $source,
            'Any other unique violation must be rethrown, not translated into DuplicateDeliveryException.');
    }
}

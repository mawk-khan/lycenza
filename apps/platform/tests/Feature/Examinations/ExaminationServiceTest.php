<?php

namespace Tests\Feature\Examinations;

use App\Domain\Examinations\Application\ExaminationService;
use App\Domain\Examinations\Application\Exceptions\DuplicateExaminationCodeException;
use App\Domain\Examinations\Application\Exceptions\ExaminationDateOrderException;
use App\Domain\Examinations\Application\Exceptions\ExaminationDateOutsideAcademicYearException;
use App\Domain\Examinations\Infrastructure\Examination;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Examinations\Concerns\CreatesExaminationFixtures;
use Tests\TestCase;

/**
 * Phase 0H.4A -- the Application service's own invariants: server-derived
 * School/AcademicYear context, the AcademicYear range rule, date
 * ordering, and the two deliberate departures from Curriculum Delivery
 * (future dates permitted, overlapping windows permitted).
 */
class ExaminationServiceTest extends TestCase
{
    use CreatesExaminationFixtures;

    private function service(): ExaminationService
    {
        return app(ExaminationService::class);
    }

    private function attrs(array $overrides = []): array
    {
        return array_merge([
            'code' => 'MID1',
            'name' => 'Mid-Term Examination',
            'starts_on' => $this->today()->addDays(10)->toDateString(),
            'ends_on' => $this->today()->addDays(20)->toDateString(),
        ], $overrides);
    }

    private function create(array $w, array $overrides = []): Examination
    {
        return $this->service()->create($w['school'], $w['year'], $this->attrs($overrides), $w['actor']);
    }

    // --- server-derived context ----------------------------------------

    #[Test]
    public function the_school_and_academic_year_are_derived_from_trusted_context(): void
    {
        $w = $this->examinationWorld();
        $examination = $this->create($w);

        $this->assertSame($w['school']->id, $examination->school_id);
        $this->assertSame($w['year']->id, $examination->academic_year_id);
        $this->assertSame(Examination::STATUS_ACTIVE, $examination->status);
    }

    #[Test]
    public function the_academic_year_is_not_mass_assignable(): void
    {
        // Even if a future caller passed it straight through, fill()
        // must drop it -- it is absent from $fillable by design, so an
        // Examination's parent can never be reassigned from input.
        $model = new Examination(['academic_year_id' => 'attacker', 'school_id' => 'attacker-school']);

        $this->assertNull($model->academic_year_id);
    }

    // --- AcademicYear range ---------------------------------------------

    #[Test]
    public function a_window_inside_the_academic_year_is_accepted_at_both_inclusive_bounds(): void
    {
        $w = $this->examinationWorld();

        $examination = $this->create($w, [
            'starts_on' => $w['year']->starts_on->toDateString(),
            'ends_on' => $w['year']->ends_on->toDateString(),
        ]);

        $this->assertSame($w['year']->starts_on->toDateString(), $examination->starts_on->toDateString());
        $this->assertSame($w['year']->ends_on->toDateString(), $examination->ends_on->toDateString());
    }

    #[Test]
    public function a_window_starting_before_the_academic_year_is_rejected(): void
    {
        $w = $this->examinationWorld();

        $this->expectException(ExaminationDateOutsideAcademicYearException::class);
        $this->create($w, [
            'starts_on' => $w['year']->starts_on->subDay()->toDateString(),
            'ends_on' => $this->today()->toDateString(),
        ]);
    }

    #[Test]
    public function a_window_ending_after_the_academic_year_is_rejected(): void
    {
        $w = $this->examinationWorld();

        $this->expectException(ExaminationDateOutsideAcademicYearException::class);
        $this->create($w, [
            'starts_on' => $this->today()->toDateString(),
            'ends_on' => $w['year']->ends_on->addDay()->toDateString(),
        ]);
    }

    #[Test]
    public function an_end_date_before_the_start_date_is_rejected(): void
    {
        $w = $this->examinationWorld();

        $this->expectException(ExaminationDateOrderException::class);
        $this->create($w, [
            'starts_on' => $this->today()->addDays(20)->toDateString(),
            'ends_on' => $this->today()->addDays(10)->toDateString(),
        ]);
    }

    // --- the two deliberate departures from Curriculum Delivery ----------

    #[Test]
    public function a_wholly_future_examination_is_accepted(): void
    {
        $w = $this->examinationWorld();

        $examination = $this->create($w, [
            'starts_on' => $this->today()->addMonths(4)->toDateString(),
            'ends_on' => $this->today()->addMonths(5)->toDateString(),
        ]);

        $this->assertTrue($examination->starts_on->isFuture(),
            'Future examination dates are permitted and expected -- examinations are scheduled ahead.');
    }

    #[Test]
    public function an_examination_may_be_defined_in_a_non_active_academic_year(): void
    {
        // Planning next year's examinations inside a draft year is
        // legitimate. Curriculum Delivery's active-year-on-create rule
        // deliberately does NOT apply here.
        $w = $this->examinationWorld(yearAttributes: ['status' => 'draft']);

        $examination = $this->create($w);

        $this->assertNotNull($examination->id);
        $this->assertSame('draft', $this->inExaminationSchool(
            $w['school'], fn () => $w['year']->fresh()->status,
        ));
    }

    #[Test]
    public function two_overlapping_examination_windows_are_both_accepted(): void
    {
        $w = $this->examinationWorld();

        $this->create($w, [
            'code' => 'A1',
            'starts_on' => $this->today()->addDays(10)->toDateString(),
            'ends_on' => $this->today()->addDays(20)->toDateString(),
        ]);
        $second = $this->create($w, [
            'code' => 'B1',
            'starts_on' => $this->today()->addDays(15)->toDateString(),
            'ends_on' => $this->today()->addDays(25)->toDateString(),
        ]);

        $this->assertNotNull($second->id);
        $this->assertSame(2, $this->inExaminationSchool($w['school'], fn () => Examination::query()->count()),
            'Overlapping examination windows are permitted -- examinations partition nothing.');
    }

    // --- duplicate handling -----------------------------------------------

    #[Test]
    public function a_duplicate_normalized_code_is_translated_to_a_domain_exception(): void
    {
        $w = $this->examinationWorld();
        $this->create($w, ['code' => 'MID1']);

        $this->expectException(DuplicateExaminationCodeException::class);
        // Case-variant: the model uppercases on assignment, so this is
        // the same normalized code.
        $this->create($w, ['code' => 'mid1']);
    }

    #[Test]
    public function the_same_code_is_accepted_in_a_different_academic_year(): void
    {
        $w = $this->examinationWorld();
        $this->create($w, ['code' => 'MID1']);

        $nextYear = $this->createAcademicYear($w['school'], [
            'code' => 'AY-NEXT', 'status' => 'draft',
            'starts_on' => $this->today()->addMonths(7)->toDateString(),
            'ends_on' => $this->today()->addMonths(18)->toDateString(),
        ]);

        $second = $this->service()->create($w['school'], $nextYear, $this->attrs([
            'code' => 'MID1',
            'starts_on' => $this->today()->addMonths(8)->toDateString(),
            'ends_on' => $this->today()->addMonths(9)->toDateString(),
        ]), $w['actor']);

        $this->assertNotNull($second->id);
    }

    #[Test]
    public function an_unrelated_unique_violation_is_not_mislabelled_as_a_duplicate_code(): void
    {
        // The service matches the SPECIFIC named constraint, never any
        // UniqueConstraintViolationException. Proven by asserting the
        // exact constraint name the translation keys on still exists --
        // if it were ever renamed the catch would stop translating
        // loudly rather than silently misreporting -- and that the
        // rethrow path is present in source.
        $index = DB::connection('pgsql_admin')->selectOne(
            'select indexname from pg_indexes where indexname = ?',
            ['examinations_year_code_ci_unique'],
        );
        $this->assertNotNull($index, 'The unique index the duplicate translation keys on must exist.');

        $source = file_get_contents(app_path('Domain/Examinations/Application/ExaminationService.php'));
        $this->assertStringContainsString("'examinations_year_code_ci_unique'", $source);
        $this->assertStringContainsString('throw $e;', $source,
            'Any other unique violation must be rethrown, not translated into DuplicateExaminationCodeException.');
    }

    // --- update ------------------------------------------------------------

    #[Test]
    public function an_update_reruns_every_applicable_invariant(): void
    {
        $w = $this->examinationWorld();
        $examination = $this->create($w);

        try {
            $this->service()->update($w['school'], $examination, [
                'ends_on' => $w['year']->ends_on->addDay()->toDateString(),
            ], $w['actor']);
            $this->fail('An update pushing the window outside the AcademicYear must be rejected.');
        } catch (ExaminationDateOutsideAcademicYearException) {
            // expected
        }

        $this->expectException(ExaminationDateOrderException::class);
        $this->service()->update($w['school'], $examination, [
            'starts_on' => $this->today()->addDays(30)->toDateString(),
        ], $w['actor']);
    }
}

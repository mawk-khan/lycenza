<?php

namespace Tests\Feature\Examinations;

use App\Domain\Examinations\Application\ExaminationPaperService;
use App\Domain\Examinations\Application\Exceptions\DuplicateExaminationPaperException;
use App\Domain\Examinations\Application\Exceptions\ExaminationNotActiveException;
use App\Domain\Examinations\Application\Exceptions\ExaminationPaperAcademicYearMismatchException;
use App\Domain\Examinations\Application\Exceptions\ExaminationPaperDateOutsideWindowException;
use App\Domain\Examinations\Application\Exceptions\ExaminationPaperInvalidMaxMarksException;
use App\Domain\Examinations\Application\Exceptions\ExaminationPaperTimeOrderException;
use App\Domain\Examinations\Application\Exceptions\SubjectOfferingNotAvailableException;
use App\Domain\Examinations\Infrastructure\ExaminationPaper;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Examinations\Concerns\CreatesExaminationPaperFixtures;
use Tests\TestCase;

/**
 * Phase 0H.4B -- the Application service's own invariants: server-derived
 * context, required-active-parent creation, the Examination-window and
 * time-order/max-marks rules, the aggregate duplicate translation,
 * immutable parents, and the one deliberate asymmetry between an
 * ordinary correction (no parent-activity re-check) and a reactivation
 * (both parents must be active).
 */
class ExaminationPaperServiceTest extends TestCase
{
    use CreatesExaminationPaperFixtures;

    private function service(): ExaminationPaperService
    {
        return app(ExaminationPaperService::class);
    }

    private function attrs(array $w, array $overrides = []): array
    {
        return array_merge([
            'subject_offering_id' => $w['subjectOffering']->id,
            'scheduled_on' => $this->today()->addDays(10)->toDateString(),
            'starts_at' => '09:00:00',
            'ends_at' => '11:00:00',
            'max_marks' => '100.00',
        ], $overrides);
    }

    private function create(array $w, array $overrides = []): ExaminationPaper
    {
        return $this->service()->create($w['school'], $w['examination'], $this->attrs($w, $overrides), $w['actor']);
    }

    // --- required and elective Offerings --------------------------------

    #[Test]
    public function an_active_required_offering_is_accepted(): void
    {
        $w = $this->examinationPaperWorld();
        $this->inExaminationSchool($w['school'], fn () => $w['subjectOffering']->forceFill(['is_required' => true])->save());

        $paper = $this->create($w);

        $this->assertSame($w['subjectOffering']->id, $paper->subject_offering_id);
    }

    #[Test]
    public function an_active_elective_offering_is_accepted(): void
    {
        $w = $this->examinationPaperWorld();
        $this->inExaminationSchool($w['school'], fn () => $w['subjectOffering']->forceFill(['is_required' => false])->save());

        $paper = $this->create($w);

        $this->assertSame($w['subjectOffering']->id, $paper->subject_offering_id);
    }

    // --- server-derived context -------------------------------------------

    #[Test]
    public function context_pins_are_derived_from_trusted_parents(): void
    {
        $w = $this->examinationPaperWorld();
        $paper = $this->create($w);

        $this->assertSame($w['school']->id, $paper->school_id);
        $this->assertSame($w['examination']->id, $paper->examination_id);
        $this->assertSame($w['examination']->academic_year_id, $paper->academic_year_id);
        $this->assertSame($w['subjectOffering']->campus_id, $paper->campus_id);
        $this->assertSame($w['subjectOffering']->grade_level_id, $paper->grade_level_id);
    }

    #[Test]
    public function malicious_context_injection_in_attributes_has_no_effect(): void
    {
        $w = $this->examinationPaperWorld();
        $other = $this->examinationPaperWorld();

        $paper = $this->create($w, [
            'id' => 'attacker-id',
            'school_id' => $other['school']->id,
            'examination_id' => $other['examination']->id,
            'academic_year_id' => $other['examination']->academic_year_id,
            'campus_id' => $other['campus']->id,
            'grade_level_id' => $other['gradeLevel']->id,
        ]);

        $this->assertSame($w['school']->id, $paper->school_id);
        $this->assertSame($w['examination']->id, $paper->examination_id);
        $this->assertNotSame('attacker-id', $paper->id);
    }

    // --- active-parent rules ------------------------------------------------

    #[Test]
    public function an_inactive_examination_is_rejected_on_create(): void
    {
        $w = $this->examinationPaperWorld();
        $this->inExaminationSchool($w['school'], fn () => $w['examination']->forceFill(['status' => 'inactive'])->save());

        $this->expectException(ExaminationNotActiveException::class);
        $this->create($w);
    }

    #[Test]
    public function an_inactive_required_offering_is_rejected_on_create(): void
    {
        $w = $this->examinationPaperWorld();
        $this->inExaminationSchool($w['school'], fn () => $w['subjectOffering']->forceFill(['is_required' => true, 'status' => 'inactive'])->save());

        $this->expectException(SubjectOfferingNotAvailableException::class);
        $this->create($w);
    }

    #[Test]
    public function an_inactive_elective_offering_is_rejected_on_create(): void
    {
        $w = $this->examinationPaperWorld();
        $this->inExaminationSchool($w['school'], fn () => $w['subjectOffering']->forceFill(['is_required' => false, 'status' => 'inactive'])->save());

        $this->expectException(SubjectOfferingNotAvailableException::class);
        $this->create($w);
    }

    // --- AcademicYear mismatch -------------------------------------------

    #[Test]
    public function an_academic_year_mismatch_between_examination_and_offering_is_rejected(): void
    {
        $w = $this->examinationPaperWorld();
        $nextYear = $this->createAcademicYear($w['school'], [
            'code' => 'AY-NEXT', 'status' => 'draft',
            'starts_on' => $this->today()->addMonths(7)->toDateString(),
            'ends_on' => $this->today()->addMonths(18)->toDateString(),
        ]);
        $otherYearOffering = $this->createSubjectOffering($nextYear, $w['campus'], $w['gradeLevel'], $this->createSubject($w['school']));

        $this->expectException(ExaminationPaperAcademicYearMismatchException::class);
        $this->create($w, ['subject_offering_id' => $otherYearOffering->id]);
    }

    // --- scheduling invariants ---------------------------------------------

    #[Test]
    public function a_scheduled_date_outside_the_examination_window_is_rejected(): void
    {
        $w = $this->examinationPaperWorld();

        $this->expectException(ExaminationPaperDateOutsideWindowException::class);
        $this->create($w, ['scheduled_on' => $w['examination']->starts_on->subDay()->toDateString()]);
    }

    #[Test]
    public function a_wholly_future_paper_date_is_accepted(): void
    {
        $w = $this->examinationPaperWorld();

        $paper = $this->create($w, ['scheduled_on' => $w['examination']->ends_on->toDateString()]);

        $this->assertNotNull($paper->id);
    }

    #[Test]
    public function an_end_time_not_after_the_start_time_is_rejected(): void
    {
        $w = $this->examinationPaperWorld();

        $this->expectException(ExaminationPaperTimeOrderException::class);
        $this->create($w, ['starts_at' => '11:00:00', 'ends_at' => '09:00:00']);
    }

    #[Test]
    public function a_non_positive_max_marks_is_rejected(): void
    {
        $w = $this->examinationPaperWorld();

        $this->expectException(ExaminationPaperInvalidMaxMarksException::class);
        $this->create($w, ['max_marks' => '0']);
    }

    #[Test]
    public function overlapping_sittings_across_different_offerings_are_both_accepted(): void
    {
        $w = $this->examinationPaperWorld();
        $secondOffering = $this->createSubjectOffering($w['year'], $w['campus'], $w['gradeLevel'], $this->createSubject($w['school']));

        $this->create($w, ['starts_at' => '09:00:00', 'ends_at' => '11:00:00']);
        $second = $this->create($w, [
            'subject_offering_id' => $secondOffering->id,
            'starts_at' => '09:00:00', 'ends_at' => '11:00:00',
        ]);

        $this->assertNotNull($second->id);
        $this->assertSame(2, $this->inExaminationSchool($w['school'], fn () => ExaminationPaper::query()->count()));
    }

    // --- duplicate handling -----------------------------------------------

    #[Test]
    public function a_duplicate_examination_offering_pair_is_translated_to_a_domain_exception(): void
    {
        $w = $this->examinationPaperWorld();
        $this->create($w);

        $this->expectException(DuplicateExaminationPaperException::class);
        $this->create($w, ['scheduled_on' => $this->today()->addDays(12)->toDateString()]);
    }

    #[Test]
    public function an_unrelated_unique_violation_is_not_mislabelled_as_a_duplicate(): void
    {
        $index = DB::connection('pgsql_admin')->selectOne(
            'select indexname from pg_indexes where indexname = ?',
            ['examination_papers_examination_offering_unique'],
        );
        $this->assertNotNull($index, 'The unique index the duplicate translation keys on must exist.');

        $source = file_get_contents(app_path('Domain/Examinations/Application/ExaminationPaperService.php'));
        $this->assertStringContainsString("'examination_papers_examination_offering_unique'", $source);
        $this->assertStringContainsString('throw $e;', $source,
            'Any other unique violation must be rethrown, not translated into DuplicateExaminationPaperException.');
    }

    // --- tenant isolation ----------------------------------------------------

    #[Test]
    public function a_cross_school_subject_offering_id_is_not_reachable(): void
    {
        $w = $this->examinationPaperWorld();
        $other = $this->examinationPaperWorld();

        $this->expectException(ModelNotFoundException::class);
        $this->create($w, ['subject_offering_id' => $other['subjectOffering']->id]);
    }

    // --- update: immutable parents ------------------------------------------

    #[Test]
    public function update_cannot_reassign_the_examination_or_subject_offering(): void
    {
        $w = $this->examinationPaperWorld();
        $other = $this->examinationPaperWorld();
        $paper = $this->create($w);

        $updated = $this->service()->update($w['school'], $paper, [
            'examination_id' => $other['examination']->id,
            'subject_offering_id' => $other['subjectOffering']->id,
            'max_marks' => '80.00',
        ], $w['actor']);

        $this->assertSame($w['examination']->id, $updated->examination_id);
        $this->assertSame($w['subjectOffering']->id, $updated->subject_offering_id);
        $this->assertSame('80.00', (string) $updated->max_marks);
    }

    #[Test]
    public function update_reruns_scheduling_invariants(): void
    {
        $w = $this->examinationPaperWorld();
        $paper = $this->create($w);

        $this->expectException(ExaminationPaperDateOutsideWindowException::class);
        $this->service()->update($w['school'], $paper, [
            'scheduled_on' => $w['examination']->ends_on->addDay()->toDateString(),
        ], $w['actor']);
    }

    #[Test]
    public function status_moves_through_the_ordinary_update(): void
    {
        $w = $this->examinationPaperWorld();
        $paper = $this->create($w);

        $updated = $this->service()->update($w['school'], $paper, ['status' => 'inactive'], $w['actor']);

        $this->assertSame('inactive', $updated->status);
    }

    // --- reactivation guard --------------------------------------------------

    #[Test]
    public function reactivation_succeeds_when_both_parents_are_active(): void
    {
        $w = $this->examinationPaperWorld();
        $paper = $this->create($w, ['status' => 'inactive']);

        $updated = $this->service()->update($w['school'], $paper, ['status' => 'active'], $w['actor']);

        $this->assertSame('active', $updated->status);
    }

    #[Test]
    public function reactivation_is_rejected_when_the_examination_is_inactive(): void
    {
        $w = $this->examinationPaperWorld();
        $paper = $this->create($w, ['status' => 'inactive']);
        $this->inExaminationSchool($w['school'], fn () => $w['examination']->forceFill(['status' => 'inactive'])->save());

        $this->expectException(ExaminationNotActiveException::class);
        $this->service()->update($w['school'], $paper, ['status' => 'active'], $w['actor']);
    }

    #[Test]
    public function reactivation_is_rejected_when_the_offering_is_inactive(): void
    {
        $w = $this->examinationPaperWorld();
        $paper = $this->create($w, ['status' => 'inactive']);
        $this->inExaminationSchool($w['school'], fn () => $w['subjectOffering']->forceFill(['status' => 'inactive'])->save());

        $this->expectException(SubjectOfferingNotAvailableException::class);
        $this->service()->update($w['school'], $paper, ['status' => 'active'], $w['actor']);
    }

    #[Test]
    public function an_ordinary_correction_remains_allowed_while_a_parent_is_inactive(): void
    {
        // Deliberately NOT a reactivation -- the Paper stays active
        // throughout, only its schedule changes. Historical correction
        // must remain possible even beneath a withdrawn parent.
        $w = $this->examinationPaperWorld();
        $paper = $this->create($w);
        $this->inExaminationSchool($w['school'], fn () => $w['examination']->forceFill(['status' => 'inactive'])->save());

        $updated = $this->service()->update($w['school'], $paper, [
            'max_marks' => '75.00',
        ], $w['actor']);

        $this->assertSame('75.00', (string) $updated->max_marks);
        $this->assertSame('active', $updated->status, 'The correction must not itself be treated as a reactivation.');
    }
}

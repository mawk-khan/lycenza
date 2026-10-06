<?php

namespace Tests\Feature\Examinations;

use App\Domain\AcademicStructure\Application\AcademicYearService;
use App\Domain\Examinations\Application\ExaminationPaperService;
use App\Domain\Examinations\Application\Exceptions\ExaminationPaperMarksRecordedException;
use App\Domain\Examinations\Application\Exceptions\StudentMarkAcademicYearClosedException;
use App\Domain\Examinations\Application\Exceptions\StudentMarkInvalidValueException;
use App\Domain\Examinations\Application\Exceptions\StudentMarkNotEligibleException;
use App\Domain\Examinations\Application\Exceptions\StudentMarkPaperInactiveException;
use App\Domain\Examinations\Application\Exceptions\StudentMarkProcessingBasisUnavailableException;
use App\Domain\Examinations\Application\Exceptions\StudentMarkVersionConflictException;
use App\Domain\Examinations\Application\Marks\StudentMarkReadService;
use App\Domain\Examinations\Infrastructure\ExaminationPaper;
use App\Domain\Examinations\Infrastructure\StudentMark;
use App\Domain\Students\Application\StudentEnrollmentService;
use App\Domain\Students\Application\SubjectOfferingEligibility;
use App\Models\SchoolAuditEvent;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Examinations\Concerns\CreatesStudentMarkFixtures;
use Tests\TestCase;

/**
 * RES.2 (ADR 0068 §6, §7.4, §19; RES-L0 2026-10-07): internal StudentMark
 * entry through StudentMarkService and the per-paper grid read. Synthetic
 * fixtures only.
 */
class StudentMarkServiceTest extends TestCase
{
    use CreatesStudentMarkFixtures;

    /** @param  array<string, mixed>  $w */
    private function audits(array $w, string $type): array
    {
        return $this->inMarksSchool($w['school'], fn () => SchoolAuditEvent::query()->where('event_type', $type)->get()->all());
    }

    #[Test]
    public function an_eligible_authorised_student_gets_one_mark_with_its_provenance_and_history(): void
    {
        $w = $this->marksWorld();
        $student = $this->markStudent($w);

        $written = $this->recordMarks($w, [$this->entry($student, 'present', '72.5')]);
        $mark = $this->markOf($w, $student);

        $this->assertSame([['studentId' => $student->id, 'studentMarkId' => $mark->id, 'version' => 1]], $written);
        $this->assertSame(['present', '72.50', 1, 'required', null], [$mark->status, (string) $mark->value, $mark->version, $mark->eligibility_source, $mark->student_subject_enrollment_id]);
        $this->assertSame($this->placementOf($w, $student)->id, $mark->student_enrollment_id);
        $this->assertSame($w['admin']->id, $mark->recorded_by_user_id);
        $this->assertNotNull($mark->processing_authorization_id);

        [$revision] = $this->revisionsOf($w, $mark);
        $this->assertSame([1, null, null, 'present', '72.50', $mark->processing_authorization_id, $w['admin']->id],
            [$revision->revision, $revision->previous_status, $revision->previous_value, $revision->new_status, (string) $revision->new_value, $revision->processing_authorization_id, $revision->recorded_by_user_id]);
    }

    #[Test]
    public function every_change_appends_history_and_needs_the_version_it_replaces(): void
    {
        $w = $this->marksWorld();
        $student = $this->markStudent($w);
        $this->recordMarks($w, [$this->entry($student, 'present', '40')]);

        $this->assertThrows(fn () => $this->recordMarks($w, [$this->entry($student, 'present', '41')]), StudentMarkVersionConflictException::class);
        $this->assertThrows(fn () => $this->recordMarks($w, [$this->entry($student, 'present', '41', 2)]), StudentMarkVersionConflictException::class);
        $this->recordMarks($w, [$this->entry($student, 'absent', null, 1)]);
        $this->recordMarks($w, [$this->entry($student, 'present', '55.25', 2)]);

        $mark = $this->markOf($w, $student);
        $history = array_map(fn ($r) => [$r->revision, $r->previous_status, $r->previous_value === null ? null : (string) $r->previous_value, $r->new_status, $r->new_value === null ? null : (string) $r->new_value], $this->revisionsOf($w, $mark));
        $this->assertSame([[1, null, null, 'present', '40.00'], [2, 'present', '40.00', 'absent', null], [3, 'absent', null, 'present', '55.25']], $history, 'no write overwrites history, pre-lock included (§19.3 a)');
        $this->assertSame(3, $mark->version);
    }

    #[Test]
    public function the_value_shape_is_enforced_and_a_failed_batch_saves_nothing(): void
    {
        $w = $this->marksWorld();
        $a = $this->markStudent($w);
        $b = $this->markStudent($w);
        foreach ([['present', null], ['present', '-1'], ['present', '80.01'], ['present', '7.123'], ['absent', '0'], ['exempt', '5'], ['withheld', null]] as [$status, $value]) {
            $this->assertThrows(fn () => $this->recordMarks($w, [$this->entry($a, 'present', '10'), $this->entry($b, $status, $value)]), StudentMarkInvalidValueException::class);
        }
        $this->assertNull($this->markOf($w, $a), 'atomic per request: the valid row was not saved either');

        $this->recordMarks($w, [$this->entry($a, 'present', '80'), $this->entry($b, 'exempt', null)]);
        $this->assertSame('80.00', (string) $this->markOf($w, $a)->value);
        $this->assertSame(['exempt', null], [$this->markOf($w, $b)->status, $this->markOf($w, $b)->value]);
    }

    #[Test]
    public function p3_decides_eligibility_on_the_papers_date_with_the_historical_placement(): void
    {
        $w = $this->marksWorld();
        $elector = $this->markStudent($w);
        $electiveRow = $this->elect($w, $elector);
        $nonElector = $this->markStudent($w);

        $this->recordMarks($w, [$this->entry($elector, 'present', '30')], $w['electivePaper']);
        $mark = $this->markOf($w, $elector, $w['electivePaper']);
        $this->assertSame(['elective', $electiveRow], [$mark->eligibility_source, $mark->student_subject_enrollment_id]);

        $refused = fn () => $this->recordMarks($w, [$this->entry($nonElector, 'present', '30')], $w['electivePaper']);
        $this->assertThrows($refused, StudentMarkNotEligibleException::class);
        try {
            $refused();
        } catch (StudentMarkNotEligibleException $e) {
            $this->assertSame([$nonElector->id, SubjectOfferingEligibility::NO_ELECTIVE_ENROLLMENT], [$e->studentId, $e->reason]);
        }

        // A Student who joined after the paper's date was not eligible on it.
        $late = $this->createStudent($w['school'], ['date_of_birth' => '2016-01-01']);
        app(StudentEnrollmentService::class)->enroll($late, $w['a1'], '901', '2026-09-20');
        $this->authorise($w, $late);
        $this->assertThrows(fn () => $this->recordMarks($w, [$this->entry($late, 'present', '30')]), StudentMarkNotEligibleException::class);

        // A transfer after the paper's date: the mark records the placement the Student had on the date.
        $mover = $this->markStudent($w);
        $source = $this->placementOf($w, $mover);
        app(StudentEnrollmentService::class)->transferPlacement($source, $w['a2'], '902', '2026-10-01');
        $this->recordMarks($w, [$this->entry($mover, 'present', '61')]);
        $this->assertSame($source->id, $this->markOf($w, $mover)->student_enrollment_id);
    }

    #[Test]
    public function no_current_processing_basis_refuses_writes_and_withholds_reads_but_keeps_the_mark(): void
    {
        $w = $this->marksWorld();
        $unauthorised = $this->markStudent($w, authorised: false);
        $this->assertThrows(fn () => $this->recordMarks($w, [$this->entry($unauthorised, 'present', '10')]), StudentMarkProcessingBasisUnavailableException::class);

        $student = $this->markStudent($w, authorised: false);
        $grant = $this->authorise($w, $student);
        $this->recordMarks($w, [$this->entry($student, 'present', '70')]);
        $this->withdrawAuthorisation($w, $grant);

        $this->assertThrows(fn () => $this->recordMarks($w, [$this->entry($student, 'present', '71', 1)]), StudentMarkProcessingBasisUnavailableException::class);
        $mark = $this->markOf($w, $student);
        $this->assertSame(['present', '70.00', 1, $grant->id], [$mark->status, (string) $mark->value, $mark->version, $mark->processing_authorization_id], 'withdrawal never deletes, invalidates or rewrites the mark');

        $row = collect($this->grid($w)['rows'])->firstWhere('studentId', $student->id);
        $this->assertSame([StudentMarkReadService::BASIS_UNAVAILABLE, ['withheld' => true]], [$row['processingBasis'], $row['mark']], 'no status, value or version without a current basis');
        $this->assertStringNotContainsString('70.00', json_encode($this->grid($w)));
    }

    #[Test]
    public function the_grid_lists_eligible_students_and_existing_marks_minimally_and_audits_the_read(): void
    {
        $w = $this->marksWorld();
        $marked = $this->markStudent($w);
        $unmarked = $this->markStudent($w, 'a2');
        $this->recordMarks($w, [$this->entry($marked, 'present', '12.5')]);

        $grid = $this->grid($w);
        $this->assertSame(['id', 'subjectOfferingId', 'scheduledOn', 'maxMarks', 'status'], array_keys($grid['paper']));
        $rows = collect($grid['rows'])->keyBy('studentId');
        $this->assertSame(['studentId', 'studentEnrollmentId', 'rollNumber', 'fullName', 'eligible', 'eligibilitySource', 'processingBasis', 'mark'], array_keys($rows[$marked->id]));
        $this->assertSame(['present', '12.50', 1], [$rows[$marked->id]['mark']['status'], $rows[$marked->id]['mark']['value'], $rows[$marked->id]['mark']['version']]);
        $this->assertNull($rows[$unmarked->id]['mark']);
        $this->assertTrue($rows[$unmarked->id]['eligible']);

        $viewed = $this->audits($w, 'examinations.student_marks.viewed');
        $this->assertCount(1, $viewed);
        $this->assertEquals(['examinationPaperId' => $w['paper']->id, 'rowCount' => 2, 'withheldCount' => 0], $viewed[0]->metadata);
    }

    #[Test]
    public function audit_metadata_carries_ids_only_never_values_statuses_or_names(): void
    {
        $w = $this->marksWorld();
        $student = $this->markStudent($w);
        $this->recordMarks($w, [$this->entry($student, 'present', '33.75')]);
        $this->recordMarks($w, [$this->entry($student, 'exempt', null, 1)]);

        $events = [...$this->audits($w, 'examinations.student_mark.recorded'), ...$this->audits($w, 'examinations.student_mark.changed')];
        $this->assertCount(2, $events);
        foreach ($events as $event) {
            $keys = array_keys($event->metadata);
            sort($keys); // jsonb does not keep key order
            $this->assertSame(['examinationPaperId', 'studentId', 'studentMarkId', 'version'], $keys);
            $encoded = json_encode($event->metadata);
            foreach (['33.75', 'present', 'exempt', $student->first_name] as $secret) {
                $this->assertStringNotContainsString($secret, $encoded);
            }
        }
    }

    #[Test]
    public function a_closed_year_or_an_inactive_paper_refuses_ordinary_entry(): void
    {
        $w = $this->marksWorld();
        $student = $this->markStudent($w);
        $this->recordMarks($w, [$this->entry($student, 'present', '20')]);

        $this->inMarksSchool($w['school'], fn () => ExaminationPaper::query()->whereKey($w['electivePaper']->id)->update(['status' => 'inactive']));
        $this->elect($w, $student);
        $this->assertThrows(fn () => $this->recordMarks($w, [$this->entry($student, 'present', '1')], $w['electivePaper']), StudentMarkPaperInactiveException::class);

        app(AcademicYearService::class)->close($w['year'], $w['admin']);
        $this->assertThrows(fn () => $this->recordMarks($w, [$this->entry($this->markStudent($w), 'present', '20')]), StudentMarkAcademicYearClosedException::class);
        $this->assertThrows(fn () => $this->recordMarks($w, [$this->entry($student, 'present', '21', 1)]), StudentMarkAcademicYearClosedException::class);
        $this->assertSame('20.00', (string) $this->markOf($w, $student)->value);
    }

    #[Test]
    public function a_marked_paper_keeps_its_maximum_and_date(): void
    {
        $w = $this->marksWorld();
        $paperService = app(ExaminationPaperService::class);
        $actor = $this->fullExaminationPaperActor($w['school']);
        $fresh = fn (ExaminationPaper $paper) => $this->inMarksSchool($w['school'], fn () => ExaminationPaper::query()->findOrFail($paper->id));
        $paperService->update($w['school'], $w['electivePaper'], ['max_marks' => '60.00'], $actor);

        $this->recordMarks($w, [$this->entry($this->markStudent($w), 'present', '20')]);
        $this->assertThrows(fn () => $paperService->update($w['school'], $fresh($w['paper']), ['max_marks' => '100.00'], $actor), ExaminationPaperMarksRecordedException::class);
        $this->assertThrows(fn () => $paperService->update($w['school'], $fresh($w['paper']), ['scheduled_on' => '2026-09-16'], $actor), ExaminationPaperMarksRecordedException::class);
        $paperService->update($w['school'], $fresh($w['paper']), ['starts_at' => '10:00:00'], $actor);
        $this->assertSame('80.00', (string) $this->inMarksSchool($w['school'], fn () => ExaminationPaper::query()->findOrFail($w['paper']->id))->max_marks);
        $this->assertSame(1, $this->inMarksSchool($w['school'], fn () => StudentMark::query()->count()));
    }
}

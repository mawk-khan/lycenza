<?php

namespace Tests\Feature\Examinations;

use App\Domain\AcademicStructure\Application\AcademicYearService;
use App\Domain\AcademicStructure\Application\Exceptions\SubjectOfferingClassificationLockedException;
use App\Domain\AcademicStructure\Application\SubjectOfferingService;
use App\Domain\Examinations\Application\Exceptions\StudentMarkCorrectionAlreadyDecidedException;
use App\Domain\Examinations\Application\Exceptions\StudentMarkCorrectionContextChangedException;
use App\Domain\Examinations\Application\Exceptions\StudentMarkCorrectionInvalidException;
use App\Domain\Examinations\Application\Exceptions\StudentMarkCorrectionPaperNotLockedException;
use App\Domain\Examinations\Application\Exceptions\StudentMarkCorrectionPendingExistsException;
use App\Domain\Examinations\Application\Exceptions\StudentMarkCorrectionSelfDecisionException;
use App\Domain\Examinations\Application\Exceptions\StudentMarkInvalidValueException;
use App\Domain\Examinations\Application\Exceptions\StudentMarkPaperLockedException;
use App\Domain\Examinations\Application\Exceptions\StudentMarkProcessingBasisUnavailableException;
use App\Domain\Examinations\Application\Exceptions\StudentMarksAlreadyLockedException;
use App\Domain\Examinations\Application\Exceptions\StudentMarkVersionConflictException;
use App\Domain\Examinations\Application\Marks\StudentMarkCorrectionService;
use App\Domain\Examinations\Application\Marks\StudentMarkReadService;
use App\Domain\Examinations\Infrastructure\ExaminationPaper;
use App\Domain\Examinations\Infrastructure\ExaminationPaperMarkState;
use App\Domain\Examinations\Infrastructure\StudentMark;
use App\Domain\Examinations\Infrastructure\StudentMarkCorrection;
use App\Domain\Students\Application\StudentEnrollmentService;
use App\Domain\Students\Infrastructure\Student;
use App\Models\SchoolAuditEvent;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Examinations\Concerns\CreatesStudentMarkFixtures;
use Tests\TestCase;

/**
 * RES.3 (ADR 0068 §7, §21): the one-way per-paper marks lock and the
 * append-only, maker/checker post-lock correction, through the services.
 * Synthetic fixtures only.
 */
class StudentMarkCorrectionServiceTest extends TestCase
{
    use CreatesStudentMarkFixtures;

    /** @param  array<string, mixed>  $w @return list<SchoolAuditEvent> */
    private function audits(array $w, string $type): array
    {
        return $this->inMarksSchool($w['school'], fn () => SchoolAuditEvent::query()->where('event_type', $type)->get()->all());
    }

    /** @return array{0: array<string, mixed>, 1: Student, 2: StudentMark} a locked paper with one mark of 40 */
    private function lockedWorld(): array
    {
        $w = $this->marksWorld();
        $student = $this->markStudent($w);
        $this->recordMarks($w, [$this->entry($student, 'present', '40')]);
        $this->lockMarks($w);

        return [$w, $student, $this->markOf($w, $student)];
    }

    #[Test]
    public function locking_is_one_way_and_refuses_every_ordinary_create_and_change(): void
    {
        $w = $this->marksWorld();
        $student = $this->markStudent($w);
        $late = $this->markStudent($w);
        $this->recordMarks($w, [$this->entry($student, 'present', '40')]);

        $state = $this->lockMarks($w);
        $this->assertSame([ExaminationPaperMarkState::STATE_LOCKED, $w['admin']->id], [$state->state, $state->locked_by_user_id]);
        $this->assertNotNull($state->locked_at);

        $this->assertThrows(fn () => $this->lockMarks($w, actor: $this->checker($w)), StudentMarksAlreadyLockedException::class);
        $this->assertThrows(fn () => $this->recordMarks($w, [$this->entry($student, 'present', '41', 1)]), StudentMarkPaperLockedException::class);
        $this->assertThrows(fn () => $this->recordMarks($w, [$this->entry($late, 'present', '10')]), StudentMarkPaperLockedException::class);
        $this->assertSame(['40.00', 1], [(string) $this->markOf($w, $student)->value, $this->markOf($w, $student)->version]);
        $this->assertNull($this->markOf($w, $late));

        // Only this paper is locked; the other paper's entry is untouched.
        $this->elect($w, $late);
        $this->recordMarks($w, [$this->entry($late, 'present', '30')], $w['electivePaper']);

        $locked = $this->audits($w, 'examinations.student_marks.locked');
        $this->assertCount(1, $locked);
        $this->assertEquals(['examinationPaperId' => $w['paper']->id, 'markCount' => 1], $locked[0]->metadata);
        $this->assertSame('locked', $this->grid($w)['paper']['marksState']);
        $this->assertSame('open', $this->grid($w, $w['electivePaper'])['paper']['marksState']);
    }

    #[Test]
    public function a_paper_with_no_marks_can_be_locked_and_then_takes_none(): void
    {
        $w = $this->marksWorld();
        $this->lockMarks($w);

        $this->assertThrows(fn () => $this->recordMarks($w, [$this->entry($this->markStudent($w), 'absent', null)]), StudentMarkPaperLockedException::class);
        $this->assertEquals(['examinationPaperId' => $w['paper']->id, 'markCount' => 0], $this->audits($w, 'examinations.student_marks.locked')[0]->metadata);
    }

    #[Test]
    public function a_correction_needs_a_locked_paper(): void
    {
        $w = $this->marksWorld();
        $student = $this->markStudent($w);
        $this->recordMarks($w, [$this->entry($student, 'present', '40')]);

        $this->assertThrows(fn () => $this->requestCorrection($w, $this->markOf($w, $student), 'present', '45'), StudentMarkCorrectionPaperNotLockedException::class);
        $this->assertSame(0, $this->inMarksSchool($w['school'], fn () => StudentMarkCorrection::query()->count()));
    }

    #[Test]
    public function an_approved_correction_changes_the_mark_once_through_the_writer_and_its_history(): void
    {
        [$w, $student, $mark] = $this->lockedWorld();
        $checker = $this->checker($w);

        $correction = $this->requestCorrection($w, $mark, 'present', '45.5', StudentMarkCorrection::REASON_TOTALLING_ERROR);
        $this->assertSame([StudentMarkCorrection::STATUS_PENDING, 1, 'present', '40.00', 'present', '45.50', 'totalling_error', $w['admin']->id],
            [$correction->status, $correction->base_version, $correction->previous_status, (string) $correction->previous_value, $correction->proposed_status, (string) $correction->proposed_value, $correction->reason_code, $correction->requested_by_user_id]);
        $this->assertSame($mark->processing_authorization_id, $correction->request_processing_authorization_id);
        $this->assertSame(['40.00', 1], [(string) $this->markOf($w, $student)->value, $this->markOf($w, $student)->version], 'a request changes nothing');

        $this->approveCorrection($w, $correction, $checker);
        // The deferred "approved in the same transaction" check would fire only at the outer test transaction's
        // commit; fire it now to prove the real approval path satisfies it.
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        $mark = $this->markOf($w, $student);
        $this->assertSame(['present', '45.50', 2, $checker->id], [$mark->status, (string) $mark->value, $mark->version, $mark->recorded_by_user_id]);
        $history = array_map(fn ($r) => [$r->revision, $r->previous_value === null ? null : (string) $r->previous_value, $r->new_value === null ? null : (string) $r->new_value], $this->revisionsOf($w, $mark));
        $this->assertSame([[1, null, '40.00'], [2, '40.00', '45.50']], $history);

        $decided = $this->freshCorrection($w, $correction);
        $this->assertSame([StudentMarkCorrection::STATUS_APPROVED, $checker->id, $mark->processing_authorization_id], [$decided->status, $decided->decided_by_user_id, $decided->decision_processing_authorization_id]);
        $this->assertNotNull($decided->decided_at);

        // Audit: ids, versions and the closed reason -- never a value or status.
        $this->assertEquals(['studentMarkCorrectionId' => $correction->id, 'studentMarkId' => $mark->id, 'examinationPaperId' => $w['paper']->id, 'studentId' => $student->id, 'baseVersion' => 1, 'reasonCode' => 'totalling_error'],
            $this->audits($w, 'examinations.student_mark_correction.requested')[0]->metadata);
        $this->assertEquals(['studentMarkCorrectionId' => $correction->id, 'studentMarkId' => $mark->id, 'examinationPaperId' => $w['paper']->id, 'studentId' => $student->id, 'version' => 2],
            $this->audits($w, 'examinations.student_mark_correction.approved')[0]->metadata);
        $this->assertCount(0, $this->audits($w, 'examinations.student_mark.changed'), 'a correction is not ordinary entry');

        // The paper stays locked; ordinary entry is still refused at the new version.
        $this->assertThrows(fn () => $this->recordMarks($w, [$this->entry($student, 'present', '46', 2)]), StudentMarkPaperLockedException::class);
    }

    #[Test]
    public function the_requester_never_decides_their_own_correction(): void
    {
        [$w, $student, $mark] = $this->lockedWorld();
        $correction = $this->requestCorrection($w, $mark, 'absent', null);

        $this->assertThrows(fn () => $this->approveCorrection($w, $correction, $w['admin']), StudentMarkCorrectionSelfDecisionException::class);
        $this->assertThrows(fn () => $this->rejectCorrection($w, $correction, $w['admin']), StudentMarkCorrectionSelfDecisionException::class);
        $this->assertSame(StudentMarkCorrection::STATUS_PENDING, $this->freshCorrection($w, $correction)->status);
        $this->assertSame('present', $this->markOf($w, $student)->status);
    }

    #[Test]
    public function decisions_are_terminal_and_a_rejection_changes_nothing(): void
    {
        [$w, $student, $mark] = $this->lockedWorld();
        $checker = $this->checker($w);

        $rejected = $this->requestCorrection($w, $mark, 'exempt', null, StudentMarkCorrection::REASON_STATUS_ERROR);
        $this->rejectCorrection($w, $rejected, $checker);
        $this->assertSame([StudentMarkCorrection::STATUS_REJECTED, $checker->id, null], [$this->freshCorrection($w, $rejected)->status, $this->freshCorrection($w, $rejected)->decided_by_user_id, $this->freshCorrection($w, $rejected)->decision_processing_authorization_id]);
        $this->assertSame(['present', 1], [$this->markOf($w, $student)->status, $this->markOf($w, $student)->version]);
        $this->assertCount(1, $this->revisionsOf($w, $this->markOf($w, $student)));
        $this->assertThrows(fn () => $this->approveCorrection($w, $rejected, $checker), StudentMarkCorrectionAlreadyDecidedException::class);
        $this->assertThrows(fn () => $this->rejectCorrection($w, $rejected, $this->checker($w)), StudentMarkCorrectionAlreadyDecidedException::class);
        $this->assertEquals(['studentMarkCorrectionId' => $rejected->id, 'studentMarkId' => $mark->id, 'examinationPaperId' => $w['paper']->id, 'studentId' => $student->id],
            $this->audits($w, 'examinations.student_mark_correction.rejected')[0]->metadata);

        // A new request after a rejection; one approval only.
        $approved = $this->requestCorrection($w, $mark, 'present', '41');
        $this->approveCorrection($w, $approved, $checker);
        $this->assertThrows(fn () => $this->approveCorrection($w, $approved, $this->checker($w)), StudentMarkCorrectionAlreadyDecidedException::class);
        $this->assertThrows(fn () => $this->rejectCorrection($w, $approved, $this->checker($w)), StudentMarkCorrectionAlreadyDecidedException::class);
        $this->assertSame(2, $this->markOf($w, $student)->version);
    }

    #[Test]
    public function one_pending_request_per_mark_and_a_request_names_the_current_version(): void
    {
        [$w, $student, $mark] = $this->lockedWorld();
        $checker = $this->checker($w);

        $first = $this->requestCorrection($w, $mark, 'present', '42');
        $this->assertThrows(fn () => $this->requestCorrection($w, $mark, 'present', '43', actor: $checker), StudentMarkCorrectionPendingExistsException::class);

        $this->approveCorrection($w, $first, $checker);
        $this->assertThrows(fn () => $this->requestCorrection($w, $mark, 'present', '44', expectedVersion: 1), StudentMarkVersionConflictException::class);
        $this->requestCorrection($w, $this->markOf($w, $student), 'present', '44', expectedVersion: 2);
        $this->assertSame(2, $this->inMarksSchool($w['school'], fn () => StudentMarkCorrection::query()->count()));
    }

    #[Test]
    public function a_request_must_be_a_valid_real_change_with_a_closed_reason(): void
    {
        [$w, , $mark] = $this->lockedWorld();

        $this->assertThrows(fn () => $this->requestCorrection($w, $mark, 'present', '40.00'), StudentMarkCorrectionInvalidException::class);
        $this->assertThrows(fn () => $this->requestCorrection($w, $mark, 'present', '45', 'teacher_asked'), StudentMarkCorrectionInvalidException::class);
        $this->assertThrows(fn () => $this->requestCorrection($w, $mark, 'present', '80.01'), StudentMarkInvalidValueException::class);
        $this->assertThrows(fn () => $this->requestCorrection($w, $mark, 'absent', '1'), StudentMarkInvalidValueException::class);
        $this->assertThrows(fn () => $this->requestCorrection($w, $mark, 'present', null), StudentMarkInvalidValueException::class);
        $this->assertThrows(fn () => $this->requestCorrection($w, $mark, 'graded', null), StudentMarkInvalidValueException::class);
        $this->assertSame(0, $this->inMarksSchool($w['school'], fn () => StudentMarkCorrection::query()->count()));
    }

    #[Test]
    public function a_closed_year_refuses_entry_but_permits_the_correction_workflow(): void
    {
        [$w, $student, $mark] = $this->lockedWorld();
        app(AcademicYearService::class)->close($w['year'], $w['admin']);

        $this->assertThrows(fn () => $this->recordMarks($w, [$this->entry($student, 'present', '41', 1)]), StudentMarkPaperLockedException::class);
        $correction = $this->requestCorrection($w, $mark, 'present', '50');
        $this->approveCorrection($w, $correction, $this->checker($w));
        $this->assertSame(['50.00', 2], [(string) $this->markOf($w, $student)->value, $this->markOf($w, $student)->version]);
    }

    #[Test]
    public function a_closed_years_open_paper_is_corrected_by_locking_it_first(): void
    {
        $w = $this->marksWorld();
        $student = $this->markStudent($w);
        $this->recordMarks($w, [$this->entry($student, 'present', '40')]);
        app(AcademicYearService::class)->close($w['year'], $w['admin']);
        $mark = $this->markOf($w, $student);

        // §7.4 / §21.6: no correction of an unlocked mark; the lock stays available after the year closes.
        $this->assertThrows(fn () => $this->requestCorrection($w, $mark, 'present', '50'), StudentMarkCorrectionPaperNotLockedException::class);
        $this->lockMarks($w);
        $this->approveCorrection($w, $this->requestCorrection($w, $mark, 'present', '50'), $this->checker($w));
        $this->assertSame(['50.00', 2], [(string) $this->markOf($w, $student)->value, $this->markOf($w, $student)->version]);
    }

    #[Test]
    public function an_inactive_paper_still_takes_corrections(): void
    {
        [$w, $student, $mark] = $this->lockedWorld();
        $this->inMarksSchool($w['school'], fn () => ExaminationPaper::query()->whereKey($w['paper']->id)->update(['status' => 'inactive']));

        $this->approveCorrection($w, $this->requestCorrection($w, $mark, 'absent', null), $this->checker($w));
        $this->assertSame('absent', $this->markOf($w, $student)->status);
    }

    #[Test]
    public function without_a_current_basis_nothing_is_requested_or_approved_and_the_grid_withholds_it(): void
    {
        $w = $this->marksWorld();
        $student = $this->markStudent($w, authorised: false);
        $grant = $this->authorise($w, $student);
        $this->recordMarks($w, [$this->entry($student, 'present', '40')]);
        $this->lockMarks($w);
        $mark = $this->markOf($w, $student);
        $checker = $this->checker($w);

        $pending = $this->requestCorrection($w, $mark, 'present', '60');
        $row = $this->grid($w)['rows'][0];
        $this->assertSame(['present', '60.00', 'entry_error', 1], [$row['pendingCorrection']['proposedStatus'], $row['pendingCorrection']['proposedValue'], $row['pendingCorrection']['reasonCode'], $row['pendingCorrection']['baseVersion']]);

        $this->withdrawAuthorisation($w, $grant);
        $this->assertThrows(fn () => $this->approveCorrection($w, $pending, $checker), StudentMarkProcessingBasisUnavailableException::class);
        $this->assertSame([StudentMarkCorrection::STATUS_PENDING, '40.00'], [$this->freshCorrection($w, $pending)->status, (string) $this->markOf($w, $student)->value]);

        $row = $this->grid($w)['rows'][0];
        $this->assertSame([StudentMarkReadService::BASIS_UNAVAILABLE, ['withheld' => true], ['withheld' => true]], [$row['processingBasis'], $row['mark'], $row['pendingCorrection']]);

        // Rejection processes no mark value, so it needs no basis; a new request does.
        $this->rejectCorrection($w, $pending, $checker);
        $this->assertThrows(fn () => $this->requestCorrection($w, $mark, 'present', '61'), StudentMarkProcessingBasisUnavailableException::class);

        // A new grant restores the workflow (the mark kept its original provenance throughout).
        $this->authorise($w, $student);
        $this->approveCorrection($w, $this->requestCorrection($w, $mark, 'present', '61'), $checker);
        $this->assertSame('61.00', (string) $this->markOf($w, $student)->value);
    }

    #[Test]
    public function a_changed_eligibility_context_fails_closed(): void
    {
        [$w, $student, $mark] = $this->lockedWorld();
        $checker = $this->checker($w);
        $pending = $this->requestCorrection($w, $mark, 'present', '41');

        // §20.1: a correction never re-derives what the mark was recorded under. Since ADR 0069 the Offering's
        // classification can no longer change under it (the paper freezes it); a backdated placement transfer still
        // changes the paper date's context, and the correction fails closed.
        $this->assertThrows(fn () => app(SubjectOfferingService::class)->update($w['school'], $w['required']->id, ['is_required' => false],
            $this->createUserWithCapabilities($w['school'], ['academics.subjects.manage'])), SubjectOfferingClassificationLockedException::class);
        app(StudentEnrollmentService::class)->transferPlacement($this->placementOf($w, $student), $w['a2'], '88', '2026-09-01');
        $this->assertThrows(fn () => $this->approveCorrection($w, $pending, $checker), StudentMarkCorrectionContextChangedException::class);
        $this->rejectCorrection($w, $pending, $checker);
        $this->assertThrows(fn () => $this->requestCorrection($w, $mark, 'present', '42'), StudentMarkCorrectionContextChangedException::class);
        $this->assertSame('40.00', (string) $this->markOf($w, $student)->value);
    }

    #[Test]
    public function another_schools_paper_mark_or_correction_is_not_found(): void
    {
        [$w, , $mark] = $this->lockedWorld();
        $correction = $this->requestCorrection($w, $mark, 'present', '41');
        $other = $this->marksWorld();
        $service = app(StudentMarkCorrectionService::class);

        $this->assertThrows(fn () => $this->lockMarks($other, $w['paper'], $other['admin']), ModelNotFoundException::class);
        $this->assertThrows(fn () => $service->request($other['school'], $w['paper']->id, $mark->id, 1, 'present', '42', 'entry_error', $other['admin']), ModelNotFoundException::class);
        $this->assertThrows(fn () => $service->approve($other['school'], $correction->id, $other['admin']), ModelNotFoundException::class);
        $this->assertThrows(fn () => $service->reject($other['school'], $correction->id, $other['admin']), ModelNotFoundException::class);
        // A mark of another paper of the same School is not this paper's.
        $this->lockMarks($w, $w['electivePaper']);
        $this->assertThrows(fn () => $service->request($w['school'], $w['electivePaper']->id, $mark->id, 1, 'present', '42', 'entry_error', $w['admin']), ModelNotFoundException::class);
        $this->assertSame(StudentMarkCorrection::STATUS_PENDING, $this->freshCorrection($w, $correction)->status);
    }
}

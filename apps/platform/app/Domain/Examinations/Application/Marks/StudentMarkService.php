<?php

namespace App\Domain\Examinations\Application\Marks;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\Examinations\Application\Exceptions\StudentMarkAcademicYearClosedException;
use App\Domain\Examinations\Application\Exceptions\StudentMarkInvalidValueException;
use App\Domain\Examinations\Application\Exceptions\StudentMarkNotEligibleException;
use App\Domain\Examinations\Application\Exceptions\StudentMarkPaperInactiveException;
use App\Domain\Examinations\Application\Exceptions\StudentMarkProcessingBasisUnavailableException;
use App\Domain\Examinations\Application\Exceptions\StudentMarkRejectedException;
use App\Domain\Examinations\Application\Exceptions\StudentMarkVersionConflictException;
use App\Domain\Examinations\Infrastructure\ExaminationPaper;
use App\Domain\Examinations\Infrastructure\StudentMark;
use App\Domain\Students\Application\Exceptions\StudentNotAuthorizedForProcessingException;
use App\Domain\Students\Application\StudentProcessingAuthorizationReadService;
use App\Domain\Students\Application\SubjectOfferingEligibilityReadService;
use App\Domain\Students\Domain\ProcessingAuthorizationPurpose;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * RES.2 (ADR 0068 §6, §7.4, §19; RES-L0 determination 2026-10-07): the ONLY
 * writer of `student_marks` -- administrative, internal entry of one
 * ExaminationPaper's marks, development only (production: RES-L1).
 *
 * One request = one paper, many Students, atomic: every row commits or none
 * (R11). Per Student, in Student-id order (a stable global lock order), inside
 * one transaction (§6.2):
 * 1. the paper and its AcademicYear held FOR SHARE (an inactive paper or a
 *    closed year refuses; a concurrent close or paper change waits);
 * 2. P3: SubjectOfferingEligibilityReadService::lockEligibilityAsOf() for the
 *    paper's Offering on the paper's date -- not eligible refuses;
 * 3. ADR 0038: lockQualifyingAuthorizationIdForStudentId() -- no current
 *    basis refuses (deny by default, §19.3 b);
 * 4. the mark row FOR UPDATE, an optimistic version check (a new mark
 *    expects none; a change names the version it replaces), then the write
 *    with its provenance. The database appends the value history
 *    (`student_mark_revisions`) for every write, pre-lock included.
 *
 * Audit: one School event per written mark, ids only -- never a value, a
 * status or a name. Database errors are translated (no SQL, binding or value
 * leaves this class). No outbox event, no webhook, no consumer (§13).
 * Authorization is the caller's: `examinations.marks.manage` + the `mfa`
 * window (route middleware). No retry shortcut: a replay re-runs every check
 * and meets the version guard (§6.3).
 */
class StudentMarkService
{
    public function __construct(
        private readonly SubjectOfferingEligibilityReadService $eligibility,
        private readonly StudentProcessingAuthorizationReadService $authorizations,
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
    ) {}

    /**
     * @param  list<StudentMarkEntry>  $entries
     * @return list<array{studentId: string, studentMarkId: string, version: int}>
     */
    public function record(School $school, string $examinationPaperId, array $entries, User $actor): array
    {
        usort($entries, fn (StudentMarkEntry $a, StudentMarkEntry $b) => strcmp($a->studentId, $b->studentId));

        return $this->context->withSchool($school, fn (): array => DB::transaction(function () use ($school, $examinationPaperId, $entries, $actor): array {
            $paper = ExaminationPaper::query()->where('school_id', $school->id)->whereKey($examinationPaperId)->sharedLock()->firstOrFail();
            if ($paper->status !== ExaminationPaper::STATUS_ACTIVE) {
                throw new StudentMarkPaperInactiveException;
            }
            $year = AcademicYear::query()->where('school_id', $school->id)->whereKey($paper->academic_year_id)->sharedLock()->firstOrFail();
            if ($year->status === 'closed') {
                throw new StudentMarkAcademicYearClosedException;
            }

            $written = [];
            foreach ($entries as $entry) {
                $value = $this->assertShape($entry, (string) $paper->max_marks);

                $eligibility = $this->eligibility->lockEligibilityAsOf($school, $entry->studentId, $paper->subject_offering_id, $paper->scheduled_on->toDateString());
                if (! $eligibility->eligible) {
                    throw new StudentMarkNotEligibleException($entry->studentId, $eligibility->reason);
                }

                try {
                    $authorizationId = $this->authorizations->lockQualifyingAuthorizationIdForStudentId($school, $entry->studentId, ProcessingAuthorizationPurpose::AcademicRecords);
                } catch (StudentNotAuthorizedForProcessingException) {
                    throw new StudentMarkProcessingBasisUnavailableException($entry->studentId);
                }

                $mark = StudentMark::query()->where('school_id', $school->id)->where('examination_paper_id', $paper->id)
                    ->where('student_id', $entry->studentId)->lockForUpdate()->first();
                $created = $mark === null;
                if ($created ? $entry->expectedVersion !== null : $entry->expectedVersion !== $mark->version) {
                    throw new StudentMarkVersionConflictException($entry->studentId);
                }

                $mark ??= new StudentMark;
                $mark->forceFill([
                    ...($created ? [
                        'school_id' => $school->id,
                        'examination_paper_id' => $paper->id,
                        'academic_year_id' => $paper->academic_year_id,
                        'student_id' => $entry->studentId,
                        'version' => 1,
                    ] : ['version' => $mark->version + 1]),
                    'student_enrollment_id' => $eligibility->studentEnrollmentId,
                    'eligibility_source' => $eligibility->source,
                    'student_subject_enrollment_id' => $eligibility->studentSubjectEnrollmentId,
                    'processing_authorization_id' => $authorizationId,
                    'status' => $entry->status,
                    'value' => $value,
                    'recorded_by_user_id' => $actor->id,
                ]);
                $this->save($mark, $entry->studentId);

                $this->audit->school($school, $created ? 'examinations.student_mark.recorded' : 'examinations.student_mark.changed', actor: $actor, metadata: [
                    'studentMarkId' => $mark->id,
                    'examinationPaperId' => $paper->id,
                    'studentId' => $entry->studentId,
                    'version' => $mark->version,
                ]);

                $written[] = ['studentId' => $entry->studentId, 'studentMarkId' => (string) $mark->id, 'version' => (int) $mark->version];
            }

            return $written;
        }));
    }

    /** R3: present needs a value 0..max (two decimals); absent and exempt carry none. Returns the normalized value. */
    private function assertShape(StudentMarkEntry $entry, string $maxMarks): ?string
    {
        if (! in_array($entry->status, StudentMark::STATUSES, true)) {
            throw new StudentMarkInvalidValueException($entry->studentId);
        }
        if ($entry->status !== StudentMark::STATUS_PRESENT) {
            if ($entry->value !== null) {
                throw new StudentMarkInvalidValueException($entry->studentId);
            }

            return null;
        }
        if ($entry->value === null || ! preg_match('/^\d{1,4}(\.\d{1,2})?$/', $entry->value)) {
            throw new StudentMarkInvalidValueException($entry->studentId);
        }
        $value = bcadd($entry->value, '0', 2);
        if (bccomp($value, $maxMarks, 2) > 0) {
            throw new StudentMarkInvalidValueException($entry->studentId);
        }

        return $value;
    }

    private function save(StudentMark $mark, string $studentId): void
    {
        try {
            $mark->save();
        } catch (QueryException $e) {
            // Never rethrow the QueryException: its message carries the SQL and its bindings (the mark value).
            if (str_contains($e->getMessage(), 'student_marks_one_per_student_paper')) {
                throw new StudentMarkVersionConflictException($studentId);
            }
            throw new StudentMarkRejectedException($studentId);
        }
    }
}

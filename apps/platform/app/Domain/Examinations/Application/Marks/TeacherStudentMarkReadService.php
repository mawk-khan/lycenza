<?php

namespace App\Domain\Examinations\Application\Marks;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\Examinations\Application\Exceptions\StudentMarkAcademicYearClosedException;
use App\Domain\Examinations\Application\Exceptions\StudentMarkPaperInactiveException;
use App\Domain\Examinations\Application\Exceptions\TeacherStudentMarkPaperNotFoundException;
use App\Domain\Examinations\Infrastructure\ExaminationPaper;
use App\Domain\Examinations\Infrastructure\ExaminationPaperMarkState;
use App\Domain\Examinations\Infrastructure\StudentMark;
use App\Domain\Students\Application\StudentPlacementDisplayReadService;
use App\Domain\Students\Application\StudentProcessingAuthorizationReadService;
use App\Domain\Students\Application\SubjectOfferingEligibilityReadService;
use App\Domain\Students\Domain\ProcessingAuthorizationPurpose;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Tenancy\TenantContext;

/**
 * RES.4 (ADR 0068 §25.5): the teacher's owned-scope read of ONE
 * ExaminationPaper -- purpose-built, never the administrative grid filtered
 * afterwards. Deny by default:
 *
 * - the paper must exist in the School AND be one whose Offering the teacher
 *   owns on its `scheduled_on`; otherwise the same 404 (never "exists but not
 *   yours"). It must be active and its year not closed;
 * - candidates are only the Students P3-eligible on the paper's date; each is
 *   kept only if the teacher owns that Student on the date (required: the
 *   Student's P3 Section x Offering; elective: the Offering) AND the Student
 *   has a current ADR 0038 basis. Nothing about any other Student -- not a
 *   row, a count, a mark or its existence -- is read into the response;
 * - an owned Student without a current basis is not listed: the response
 *   only counts them (`unavailableCount`), so a hidden mark's existence is
 *   never signalled. The mark itself stays recorded (RES-L0 §3);
 * - no correction, lock decision or administrative field is returned.
 *
 * Every successful read is audited as `examinations.student_marks.teacher_viewed`
 * (ids and counts only). Nothing is cached.
 */
class TeacherStudentMarkReadService
{
    public const string AUDIT_VIEWED = 'examinations.student_marks.teacher_viewed';

    public function __construct(
        private readonly TeacherStudentMarkAccess $access,
        private readonly SubjectOfferingEligibilityReadService $eligibility,
        private readonly StudentProcessingAuthorizationReadService $authorizations,
        private readonly StudentPlacementDisplayReadService $display,
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
    ) {}

    /**
     * @return array{paper: array<string, mixed>, rows: list<array<string, mixed>>, unavailableCount: int}
     */
    public function paper(School $school, string $examinationPaperId, User $actor): array
    {
        $scope = $this->access->scope($actor, $school);

        return $this->context->withSchool($school, function () use ($school, $examinationPaperId, $actor, $scope): array {
            $paper = ExaminationPaper::query()->where('school_id', $school->id)->whereKey($examinationPaperId)->first();
            $date = $paper?->scheduled_on->toDateString();
            if ($paper === null || ! $scope->ownsOffering($paper->subject_offering_id, (string) $date)) {
                throw new TeacherStudentMarkPaperNotFoundException;
            }
            if ($paper->status !== ExaminationPaper::STATUS_ACTIVE) {
                throw new StudentMarkPaperInactiveException;
            }
            if (AcademicYear::query()->where('school_id', $school->id)->whereKey($paper->academic_year_id)->value('status') === 'closed') {
                throw new StudentMarkAcademicYearClosedException;
            }

            $owned = array_filter(
                $this->eligibility->eligibleStudentsAsOf($school, $paper->subject_offering_id, $date),
                fn ($eligibility) => $scope->ownsStudent($paper->subject_offering_id, $date, $eligibility),
            );
            $authorized = $this->authorizations->authorizedStudentIds($school, array_map('strval', array_keys($owned)), ProcessingAuthorizationPurpose::AcademicRecords);
            $visible = array_intersect_key($owned, array_flip($authorized));

            $marks = StudentMark::query()->where('school_id', $school->id)->where('examination_paper_id', $paper->id)
                ->whereIn('student_id', array_keys($visible))->get()->keyBy('student_id');
            $members = $this->display->forPlacements($school, array_values(array_unique(array_map(fn ($e) => (string) $e->studentEnrollmentId, $visible))));

            $rows = [];
            foreach ($visible as $studentId => $eligibility) {
                $member = $members[(string) $eligibility->studentEnrollmentId] ?? null;
                $mark = $marks->get((string) $studentId);
                $rows[] = [
                    'studentId' => (string) $studentId,
                    'rollNumber' => $member->rollNumber ?? '',
                    'fullName' => $member->fullName ?? '',
                    'mark' => $mark === null ? null : [
                        'studentMarkId' => (string) $mark->id,
                        'status' => $mark->status,
                        'value' => $mark->value === null ? null : (string) $mark->value,
                        'version' => (int) $mark->version,
                    ],
                ];
            }
            usort($rows, fn (array $a, array $b) => [$a['rollNumber'], $a['studentId']] <=> [$b['rollNumber'], $b['studentId']]);
            $unavailable = count($owned) - count($visible);

            $this->audit->school($school, self::AUDIT_VIEWED, actor: $actor, metadata: [
                'examinationPaperId' => $paper->id,
                'employeeId' => $scope->employeeId,
                'rowCount' => count($rows),
                'unavailableCount' => $unavailable,
            ]);

            return [
                'paper' => [
                    'id' => $paper->id,
                    'subjectOfferingId' => $paper->subject_offering_id,
                    'scheduledOn' => $date,
                    'maxMarks' => (string) $paper->max_marks,
                    'marksState' => ExaminationPaperMarkState::query()->where('school_id', $school->id)->where('examination_paper_id', $paper->id)
                        ->value('state') ?? ExaminationPaperMarkState::STATE_OPEN,
                ],
                'rows' => $rows,
                'unavailableCount' => $unavailable,
            ];
        });
    }
}

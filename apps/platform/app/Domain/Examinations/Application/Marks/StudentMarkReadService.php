<?php

namespace App\Domain\Examinations\Application\Marks;

use App\Domain\Examinations\Infrastructure\ExaminationPaper;
use App\Domain\Examinations\Infrastructure\ExaminationPaperMarkState;
use App\Domain\Examinations\Infrastructure\StudentMark;
use App\Domain\Examinations\Infrastructure\StudentMarkCorrection;
use App\Domain\Students\Application\StudentPlacementDisplayReadService;
use App\Domain\Students\Application\StudentProcessingAuthorizationReadService;
use App\Domain\Students\Application\SubjectOfferingEligibilityReadService;
use App\Domain\Students\Domain\ProcessingAuthorizationPurpose;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Tenancy\TenantContext;

/**
 * RES.2 (ADR 0068 §19.2, §19.3 b-c): the ONE marks read -- the grid of a
 * single ExaminationPaper, the ordinary administrative surface (never a
 * search, list, report, export or bulk read).
 *
 * Rows: every Student P3-eligible for the paper's Offering on its date, plus
 * every Student who already has a mark on it. Each row is minimal (ids, roll
 * number, display name; Students-owned projections) and carries the mark only
 * while the Student has a CURRENT qualifying processing basis (ADR 0038).
 * Without one, the row says `processing_basis_unavailable`: no status, no
 * value, no version -- the mark itself stays recorded and valid, but there is
 * no authority to expose it (RES-L0 §3; deny by default). Mark existence is
 * never treated as authority.
 *
 * RES.3 (§21): the paper says whether its marks are `open` or `locked`, and a
 * row with a pending correction shows it (proposed status and value, reason,
 * base version) -- withheld exactly like the mark when there is no basis.
 *
 * Every read is audited (Highly Sensitive access), ids and counts only.
 * Authorization is the caller's: `examinations.marks.view` + the `mfa` window.
 */
class StudentMarkReadService
{
    public const BASIS_AVAILABLE = 'available';

    public const BASIS_UNAVAILABLE = 'processing_basis_unavailable';

    public function __construct(
        private readonly SubjectOfferingEligibilityReadService $eligibility,
        private readonly StudentProcessingAuthorizationReadService $authorizations,
        private readonly StudentPlacementDisplayReadService $display,
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
    ) {}

    /**
     * @return array{paper: array<string, mixed>, rows: list<array<string, mixed>>}
     */
    public function grid(School $school, string $examinationPaperId, User $actor): array
    {
        StudentMarkAvailability::assertAvailable();

        return $this->context->withSchool($school, function () use ($school, $examinationPaperId, $actor): array {
            $paper = ExaminationPaper::query()->where('school_id', $school->id)->whereKey($examinationPaperId)->firstOrFail();
            $date = $paper->scheduled_on->toDateString();

            $eligible = $this->eligibility->eligibleStudentsAsOf($school, $paper->subject_offering_id, $date);
            $marks = StudentMark::query()->where('school_id', $school->id)->where('examination_paper_id', $paper->id)->get()->keyBy('student_id');
            $pending = StudentMarkCorrection::query()->where('school_id', $school->id)->where('examination_paper_id', $paper->id)
                ->where('status', StudentMarkCorrection::STATUS_PENDING)->get()->keyBy('student_id');
            $marksState = ExaminationPaperMarkState::query()->where('school_id', $school->id)->where('examination_paper_id', $paper->id)
                ->value('state') ?? ExaminationPaperMarkState::STATE_OPEN;

            $studentIds = array_values(array_unique([...array_keys($eligible), ...$marks->keys()->map(fn ($id) => (string) $id)->all()]));
            $authorized = array_flip($this->authorizations->authorizedStudentIds($school, $studentIds, ProcessingAuthorizationPurpose::AcademicRecords));

            $placementOf = [];
            foreach ($studentIds as $studentId) {
                $placementOf[$studentId] = isset($eligible[$studentId]) ? (string) $eligible[$studentId]->studentEnrollmentId : (string) $marks[$studentId]->student_enrollment_id;
            }
            $members = $this->display->forPlacements($school, array_values(array_unique($placementOf)));

            $rows = [];
            $withheld = 0;
            foreach ($studentIds as $studentId) {
                $member = $members[$placementOf[$studentId]] ?? null;
                $mark = $marks->get($studentId);
                $correction = $pending->get($studentId);
                $basis = isset($authorized[$studentId]) ? self::BASIS_AVAILABLE : self::BASIS_UNAVAILABLE;
                if ($mark !== null && $basis === self::BASIS_UNAVAILABLE) {
                    $withheld++;
                }
                $rows[] = [
                    'studentId' => $studentId,
                    'studentEnrollmentId' => $placementOf[$studentId],
                    'rollNumber' => $member->rollNumber ?? '',
                    'fullName' => $member->fullName ?? '',
                    'eligible' => isset($eligible[$studentId]),
                    'eligibilitySource' => $eligible[$studentId]->source ?? null,
                    'processingBasis' => $basis,
                    'mark' => $mark === null ? null : ($basis === self::BASIS_UNAVAILABLE ? ['withheld' => true] : [
                        'studentMarkId' => (string) $mark->id,
                        'status' => $mark->status,
                        'value' => $mark->value === null ? null : (string) $mark->value,
                        'version' => (int) $mark->version,
                    ]),
                    'pendingCorrection' => $correction === null ? null : ($basis === self::BASIS_UNAVAILABLE ? ['withheld' => true] : [
                        'studentMarkCorrectionId' => (string) $correction->id,
                        'baseVersion' => (int) $correction->base_version,
                        'proposedStatus' => $correction->proposed_status,
                        'proposedValue' => $correction->proposed_value === null ? null : (string) $correction->proposed_value,
                        'reasonCode' => $correction->reason_code,
                        'requestedByUserId' => (string) $correction->requested_by_user_id,
                    ]),
                ];
            }
            usort($rows, fn (array $a, array $b) => [$a['rollNumber'], $a['studentId']] <=> [$b['rollNumber'], $b['studentId']]);

            $this->audit->school($school, 'examinations.student_marks.viewed', actor: $actor, metadata: [
                'examinationPaperId' => $paper->id,
                'rowCount' => count($rows),
                'withheldCount' => $withheld,
                'pendingCorrectionCount' => $pending->count(),
            ]);

            return [
                'paper' => [
                    'id' => $paper->id,
                    'subjectOfferingId' => $paper->subject_offering_id,
                    'scheduledOn' => $date,
                    'maxMarks' => (string) $paper->max_marks,
                    'status' => $paper->status,
                    'marksState' => $marksState,
                ],
                'rows' => $rows,
            ];
        });
    }
}

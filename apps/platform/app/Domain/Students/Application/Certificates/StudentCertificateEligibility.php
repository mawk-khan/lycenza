<?php

namespace App\Domain\Students\Application\Certificates;

use App\Domain\Students\Application\StudentLifecycleReason;
use App\Domain\Students\Infrastructure\Student;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Domain\Students\Infrastructure\StudentLifecycleEvent;
use App\Models\School;

/**
 * SIS.EXP-4 (OD-SIS-2): whether a Student has a canonical, certifiable exit,
 * and the exact facts a certificate would freeze -- read from the canonical
 * history only (placements, lifecycle events, the School profile), never the
 * audit ledger. No new lifecycle state; no back-dated exit.
 *
 * A Student is eligible when ALL of:
 * - no `active` placement exists (current or future): a placed Student never
 *   receives an exit certificate;
 * - the FINAL placement (the latest non-cancelled one) ended as `withdrawn`
 *   or `completed`;
 * - the exit is RECORDED with a qualifying closed reason: the final
 *   placement's withdrawal (`left_school`, `transferred_out`,
 *   `guardian_request`), or the Student's current inactive status from a
 *   deactivation (`left_school`, `transferred_out`, `completed_studies`).
 *   A completed placement of a still-active Student is NOT an exit (the
 *   School has not recorded one -- it may be a pending rollover); a
 *   deactivation for `duplicate_record`/`entered_in_error` is not an exit,
 *   and a Student marked active again since has returned.
 *
 * The exit reason is `transferred_out` when either record says so, else the
 * deactivation's reason, else the withdrawal's. LEAVING is available for every
 * eligible exit; TRANSFER only for `transferred_out`.
 *
 * Labels (School, Campus, year, Grade, Section names) are the CURRENT names of
 * the historical rows, read at issuance and then frozen in the certificate.
 */
final class StudentCertificateEligibility
{
    public const array QUALIFYING_WITHDRAWAL = ['left_school', 'transferred_out', 'guardian_request'];

    public const array QUALIFYING_DEACTIVATION = ['left_school', 'transferred_out', 'completed_studies'];

    /**
     * @return array{eligible: bool, refusal: ?string, types: list<StudentCertificateType>, sourceEnrollmentId: ?string, snapshot: ?array<string, ?string>}
     */
    public function evaluate(School $school, Student $student): array
    {
        $refuse = fn (string $code): array => ['eligible' => false, 'refusal' => $code, 'types' => [], 'sourceEnrollmentId' => null, 'snapshot' => null];

        $placements = StudentEnrollment::query()
            ->with(['academicYear:id,name', 'campus:id,name', 'gradeLevel:id,name', 'section:id,name'])
            ->where('school_id', $school->id)->where('student_id', $student->id)
            ->where('status', '!=', 'cancelled')
            ->orderByDesc('starts_on')->orderByDesc('created_at')->orderByDesc('id')
            ->get();
        if ($placements->isEmpty()) {
            return $refuse('no_placement');
        }
        if ($placements->contains(fn (StudentEnrollment $p) => $p->status === 'active')) {
            return $refuse('still_placed');
        }
        /** @var StudentEnrollment $final */
        $final = $placements->first();
        if (! in_array($final->status, ['withdrawn', 'completed'], true)) {
            return $refuse('exit_not_recorded');
        }

        $events = StudentLifecycleEvent::query()->where('school_id', $school->id)->where('student_id', $student->id)
            ->orderByDesc('occurred_at')->orderByDesc('id')->get(['action', 'reason_code', 'student_enrollment_id']);
        $withdrawal = $final->status === 'withdrawn'
            ? $events->first(fn ($e) => $e->action === StudentLifecycleReason::WITHDRAW_ENROLLMENT && $e->student_enrollment_id === $final->id)?->reason_code
            : null;
        $latestStatus = $events->first(fn ($e) => in_array($e->action, [StudentLifecycleReason::DEACTIVATE, StudentLifecycleReason::REACTIVATE], true));
        $deactivation = $student->status === 'inactive' && $latestStatus?->action === StudentLifecycleReason::DEACTIVATE ? $latestStatus->reason_code : null;

        // Marked active again after leaving: the Student returned, whatever
        // an older withdrawal says.
        if ($latestStatus?->action === StudentLifecycleReason::REACTIVATE) {
            return $refuse('returned');
        }
        if ($deactivation !== null && ! in_array($deactivation, self::QUALIFYING_DEACTIVATION, true)) {
            return $refuse('not_an_exit');
        }
        $withdrawal = in_array($withdrawal, self::QUALIFYING_WITHDRAWAL, true) ? $withdrawal : null;
        if ($withdrawal === null && $deactivation === null) {
            return $refuse('exit_not_recorded');
        }
        $reason = in_array('transferred_out', [$withdrawal, $deactivation], true) ? 'transferred_out' : ($deactivation ?? $withdrawal);

        $joined = $placements->last();

        return [
            'eligible' => true,
            'refusal' => null,
            'types' => $reason === 'transferred_out' ? [StudentCertificateType::LEAVING, StudentCertificateType::TRANSFER] : [StudentCertificateType::LEAVING],
            'sourceEnrollmentId' => $final->id,
            'snapshot' => [
                'student_name' => trim(implode(' ', array_filter([$student->first_name, $student->middle_name, $student->last_name], fn (?string $p) => $p !== null && $p !== ''))),
                'student_number' => $student->student_number,
                'school_name' => $school->name,
                'school_legal_name' => $school->legal_name,
                'school_affiliation_reference' => $school->affiliation_reference,
                'school_registration_reference' => $school->registration_reference,
                'campus_name' => $final->campus->name,
                'academic_year_name' => $final->academicYear->name,
                'grade_name' => $final->gradeLevel->name,
                'section_name' => $final->section->name,
                'joined_on' => $joined->starts_on->toDateString(),
                'left_on' => $final->ends_on?->toDateString(),
                'exit_reason' => $reason,
            ],
        ];
    }

    /**
     * What an issue request must have previewed: the snapshot, the type, the
     * final placement and (for a reissue) the certificate it replaces.
     *
     * @param  array<string, ?string>  $snapshot
     */
    public static function fingerprint(array $snapshot, StudentCertificateType $type, string $sourceEnrollmentId, ?string $supersedesId): string
    {
        ksort($snapshot);

        return hash('sha256', json_encode([$snapshot, $type->value, $sourceEnrollmentId, $supersedesId], JSON_THROW_ON_ERROR));
    }

    public static function reasonLabel(string $reason): string
    {
        return match ($reason) {
            'left_school' => 'Left the School',
            'transferred_out' => 'Transferred to another School',
            'completed_studies' => 'Completed studies',
            'guardian_request' => 'At the request of the Guardian',
            default => $reason,
        };
    }
}

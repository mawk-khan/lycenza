<?php

namespace App\Domain\Admissions\Application;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\GradeLevel;
use App\Domain\Admissions\Application\Exceptions\CrossSchoolAdmissionReferenceException;
use App\Domain\Admissions\Application\Exceptions\InvalidAdmissionApplicationTransitionException;
use App\Domain\Admissions\Application\Exceptions\OpenAdmissionApplicationExistsException;
use App\Domain\Admissions\Infrastructure\AdmissionApplication;
use App\Domain\Admissions\Infrastructure\Applicant;
use App\Models\Campus;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Phase 1D.2: the ONLY sanctioned write path for an AdmissionApplication
 * -- creation (`create()`) and the four lifecycle transitions
 * (`submit()`/`accept()`/`reject()`/`withdraw()`), mirroring
 * App\Domain\Students\Application\StudentEnrollmentService's exact
 * shape (dedicated methods per transition, never a generic
 * `update(status:)`). Never accept/set `converted`,
 * `converted_student_id`, `converted_student_enrollment_id`, or
 * `converted_at` -- the accepted -> converted transition is owned
 * entirely by a future Phase 1D.3 conversion command
 * (docs/modules/ADMISSIONS.md §7/§8).
 *
 * Legal transitions (`ADMISSIONS.md` §6, restricted to what 1D.2 may
 * reach -- `accepted -> converted` is explicitly excluded here):
 *   draft     -> submitted
 *   submitted -> accepted
 *   submitted -> rejected
 *   submitted -> withdrawn
 *   accepted  -> withdrawn
 * Every other combination (including any transition FROM `rejected`,
 * `withdrawn`, or `converted`) is rejected by
 * InvalidAdmissionApplicationTransitionException.
 *
 * Every lifecycle method reloads and `lockForUpdate()`s the
 * authoritative row inside its transaction before evaluating the
 * transition (never trusts a possibly-stale `$application->status` the
 * caller already holds), then performs a conditional
 * `WHERE status IN (...)` UPDATE and checks the affected-row count --
 * the exact double-guard pattern
 * App\Domain\AcademicStructure\Application\AcademicYearService::activate()
 * and App\Domain\Students\Application\StudentEnrollmentService's
 * terminal transitions already established for "reload, lock,
 * conditionally transition, detect a lost race".
 *
 * Deliberately authorization-neutral, matching every other Application
 * service in this codebase -- Phase 1D.4 owns the `admissions.manage`
 * capability check a future controller applies before ever reaching
 * this service.
 *
 * E21.3C: entering `rejected` or `withdrawn` also sets the canonical
 * `terminal_at` in the SAME UPDATE -- the database trigger
 * `admission_applications_guard_terminal_at` assigns it (transaction time)
 * and keeps it immutable. It starts the 1-year retention clock of a
 * non-converted application; `updated_at` never does.
 *
 * Audit metadata never carries an Applicant's name/date_of_birth or an
 * AdmissionApplication's `decision_note` (Sensitive personal data /
 * internal staff note, docs/security/DATA-CLASSIFICATION.md) -- only
 * IDs and non-PII status values, mirroring StudentService/
 * StudentEnrollmentService's identical "identity-safe by construction"
 * audit design.
 */
class AdmissionApplicationService
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
    ) {}

    /**
     * Creates a new AdmissionApplication in `draft` status. Applicant,
     * AcademicYear, Campus, and GradeLevel must all belong to the same
     * School -- checked BEFORE the open-application-conflict check
     * below, so a cross-School reference never leaks whether a
     * (unrelated) open application already exists in the other School
     * (root CLAUDE.md rule 10). No `section_id`/roll number/Guardian
     * input -- those are conversion-time-only decisions (`ADMISSIONS.md`
     * §5/§9), unreachable from this method's signature.
     */
    public function create(Applicant $applicant, AcademicYear $academicYear, Campus $campus, GradeLevel $gradeLevel, ?User $actor = null): AdmissionApplication
    {
        if ($academicYear->school_id !== $applicant->school_id
            || $campus->school_id !== $applicant->school_id
            || $gradeLevel->school_id !== $applicant->school_id) {
            throw new CrossSchoolAdmissionReferenceException;
        }

        return $this->context->withSchool($applicant->school, function () use ($applicant, $academicYear, $campus, $gradeLevel, $actor) {
            try {
                return DB::transaction(function () use ($applicant, $academicYear, $campus, $gradeLevel, $actor) {
                    $application = AdmissionApplication::query()->create([
                        'school_id' => $applicant->school_id,
                        'applicant_id' => $applicant->id,
                        'academic_year_id' => $academicYear->id,
                        'campus_id' => $campus->id,
                        'grade_level_id' => $gradeLevel->id,
                        'status' => 'draft',
                    ]);

                    $this->audit->school($applicant->school, 'admission_application.created', actor: $actor, subject: $application, metadata: [
                        'applicantId' => $applicant->id,
                        'academicYearId' => $academicYear->id,
                        'campusId' => $campus->id,
                        'gradeLevelId' => $gradeLevel->id,
                    ]);

                    return $application;
                });
            } catch (UniqueConstraintViolationException $e) {
                throw $this->translateUniqueViolation($e);
            }
        });
    }

    /**
     * `draft` -> `submitted`. No conversion provenance, no
     * `decision_note` mutation (`ADMISSIONS.md` §6 does not describe a
     * note on submission).
     */
    public function submit(AdmissionApplication $application, ?User $actor = null): AdmissionApplication
    {
        return $this->transitionTo($application, ['draft'], 'submitted', 'admission_application.submitted', $actor);
    }

    /**
     * `submitted` -> `accepted`. `$decisionNote` is optional internal
     * staff text (`ADMISSIONS.md` §5/§11's `decision_note` column is
     * general-purpose -- not restricted to rejection) -- never included
     * in audit metadata (§15).
     */
    public function accept(AdmissionApplication $application, ?string $decisionNote = null, ?User $actor = null): AdmissionApplication
    {
        return $this->transitionTo($application, ['submitted'], 'accepted', 'admission_application.accepted', $actor, [
            'decision_note' => self::normalizeDecisionNote($decisionNote),
        ]);
    }

    /**
     * `submitted` -> `rejected`. `$decisionNote` is optional -- a
     * rejection is never required to carry a sensitive free-text
     * explanation merely to be recorded.
     */
    public function reject(AdmissionApplication $application, ?string $decisionNote = null, ?User $actor = null): AdmissionApplication
    {
        return $this->transitionTo($application, ['submitted'], 'rejected', 'admission_application.rejected', $actor, [
            'decision_note' => self::normalizeDecisionNote($decisionNote),
        ]);
    }

    /**
     * `submitted` -> `withdrawn` or `accepted` -> `withdrawn`. Never
     * touches `decision_note` -- an accepted application's decision
     * note must survive a later withdrawal unchanged (`ADMISSIONS.md`
     * §6: "the decision itself is immutable"; historical decision
     * information is never erased merely because the family later
     * declines).
     */
    public function withdraw(AdmissionApplication $application, ?User $actor = null): AdmissionApplication
    {
        return $this->transitionTo($application, ['submitted', 'accepted'], 'withdrawn', 'admission_application.withdrawn', $actor);
    }

    /**
     * Shared transition guard for every public lifecycle method above.
     * Reloads and locks the authoritative row, verifies the CURRENT
     * database status (never the caller's possibly-stale in-memory
     * `$application->status`) is one of `$fromStatuses`, performs a
     * conditional UPDATE, and detects a lost race via the affected-row
     * count -- exactly like
     * StudentEnrollmentService::transitionToTerminalStatus().
     *
     * @param  array<int, string>  $fromStatuses
     * @param  array<string, mixed>  $extraUpdate  Additional columns to set alongside `status` (e.g. `decision_note`).
     */
    private function transitionTo(AdmissionApplication $application, array $fromStatuses, string $toStatus, string $eventType, ?User $actor, array $extraUpdate = []): AdmissionApplication
    {
        return $this->context->withSchool($application->school, fn () => DB::transaction(function () use ($application, $fromStatuses, $toStatus, $eventType, $actor, $extraUpdate) {
            $locked = AdmissionApplication::query()->whereKey($application->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, $fromStatuses, true)) {
                throw new InvalidAdmissionApplicationTransitionException($locked->status, $toStatus);
            }

            $previousStatus = $locked->status;

            $affected = AdmissionApplication::query()
                ->whereKey($locked->id)
                ->whereIn('status', $fromStatuses)
                ->update(array_merge(['status' => $toStatus], $extraUpdate));

            if ($affected === 0) {
                throw new InvalidAdmissionApplicationTransitionException($locked->fresh()->status ?? 'unknown', $toStatus);
            }

            $this->audit->school($locked->school, $eventType, actor: $actor, subject: $locked, metadata: [
                'applicantId' => $locked->applicant_id,
                'academicYearId' => $locked->academic_year_id,
                'campusId' => $locked->campus_id,
                'gradeLevelId' => $locked->grade_level_id,
                'previousStatus' => $previousStatus,
                'newStatus' => $toStatus,
            ]);

            return $locked->refresh();
        }));
    }

    private static function normalizeDecisionNote(?string $decisionNote): ?string
    {
        if ($decisionNote === null) {
            return null;
        }

        $trimmed = trim($decisionNote);

        return $trimmed === '' ? null : $trimmed;
    }

    private function translateUniqueViolation(UniqueConstraintViolationException $e): Throwable
    {
        return match ($e->index) {
            'admission_applications_one_open_per_context' => new OpenAdmissionApplicationExistsException,
            default => $e,
        };
    }
}

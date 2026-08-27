<?php

namespace App\Domain\Admissions\Application;

use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\Admissions\Application\Exceptions\AdmissionApplicationAlreadyConvertedException;
use App\Domain\Admissions\Application\Exceptions\AdmissionGuardianSelectionRequiredException;
use App\Domain\Admissions\Application\Exceptions\IncompatibleConversionSectionException;
use App\Domain\Admissions\Application\Exceptions\InvalidAdmissionApplicationTransitionException;
use App\Domain\Admissions\Infrastructure\AdmissionApplication;
use App\Domain\Guardians\Application\GuardianContactService;
use App\Domain\Guardians\Application\GuardianService;
use App\Domain\Guardians\Application\StudentGuardianRelationshipService;
use App\Domain\Guardians\Infrastructure\Guardian;
use App\Domain\Guardians\Infrastructure\StudentGuardianRelationship;
use App\Domain\Students\Application\StudentEnrollmentService;
use App\Domain\Students\Application\StudentService;
use App\Domain\Students\Infrastructure\Student;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Phase 1D.3: the single orchestration service fulfilling the
 * `ConvertAcceptedAdmission` role named throughout `docs/modules/
 * ADMISSIONS.md` §7/§8 and `docs/admissions/
 * PHASE-1D-0-ADMISSIONS-ARCHITECTURE.md` §7 -- named
 * `AdmissionConversionService` to match this codebase's `XxxService`
 * Application-layer convention (no `Command`/orchestrator-class
 * precedent exists anywhere in this repository).
 *
 * Composes five EXISTING, UNMODIFIED Students/SIS/Guardians services in
 * the exact order `ADMISSIONS.md` §8 decided (Student -> Guardian ->
 * GuardianContact -> StudentGuardianRelationship -> StudentEnrollment),
 * inside ONE outer `DB::transaction()` inside ONE
 * `TenantContext::withSchool()` block, so a failure at any later step
 * rolls back everything already written by an earlier one
 * (`ADMISSIONS.md` §11's required invariant, proven by
 * `tests/Feature/Admissions/AdmissionConversionServiceTest.php`'s
 * forced-failure test -- see that test's docblock for the exact
 * mechanism). This is safe because every composed service --
 * `StudentService`, `GuardianService`, `GuardianContactService`,
 * `StudentGuardianRelationshipService`, `StudentEnrollmentService` --
 * and `AuditRecorder`'s `SchoolAuditEvent`/`PlatformAuditEvent` models
 * all use Laravel's DEFAULT database connection (confirmed by reading
 * every one of those model/service files directly -- none declares a
 * `protected $connection` override or an explicit
 * `DB::connection('other')` call), so every inner `DB::transaction()`
 * call those services make nests as a PostgreSQL SAVEPOINT under this
 * service's outer transaction rather than escaping to an independent
 * connection.
 *
 * Admissions duplicates NONE of the composed services' own rules:
 * Student identity/number uniqueness (`StudentService`), Guardian
 * identity (`GuardianService`), contact encryption/HMAC lookup
 * (`GuardianContactService`), relationship/cross-School guards
 * (`StudentGuardianRelationshipService`), and Enrollment placement/
 * roll-number rules (`StudentEnrollmentService`) all remain owned
 * exactly where they already lived.
 */
class AdmissionConversionService
{
    public function __construct(
        private readonly StudentService $studentService,
        private readonly GuardianService $guardianService,
        private readonly GuardianContactService $guardianContactService,
        private readonly StudentGuardianRelationshipService $relationshipService,
        private readonly StudentEnrollmentService $enrollmentService,
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
    ) {}

    /**
     * Converts an `accepted` AdmissionApplication into a Student,
     * StudentEnrollment, and (optionally) a Guardian relationship.
     *
     * `$studentNumber`/`$section`/`$rollNumber`/`$startsOn` are
     * conversion-time-only inputs (`ADMISSIONS.md` §5/§12) -- never
     * persisted on AdmissionApplication/Applicant. `$guardian` is
     * `null` for the "no Guardian" mode (`ADMISSIONS.md` §11's
     * optionality); a `GuardianConversionInstruction` selects `create`
     * or `link_existing` explicitly (§9.2's "no silent auto-link"
     * rule) -- never inferred.
     */
    public function convert(
        AdmissionApplication $application,
        string $studentNumber,
        Section $section,
        string $rollNumber,
        string $startsOn,
        ?GuardianConversionInstruction $guardian = null,
        ?User $actor = null,
    ): AdmissionConversionResult {
        // Checked BEFORE entering the transaction / creating any
        // canonical record -- a Section whose academic context
        // disagrees with the application's own intent is rejected
        // immediately (root CLAUDE.md rule 10's "reject mismatches
        // before other work" ordering, applied here since
        // StudentEnrollmentService::enroll() has no concept of "the
        // application's intended context" to check this for us --
        // see IncompatibleConversionSectionException's docblock).
        if ($section->school_id !== $application->school_id
            || $section->academic_year_id !== $application->academic_year_id
            || $section->campus_id !== $application->campus_id
            || $section->grade_level_id !== $application->grade_level_id) {
            throw new IncompatibleConversionSectionException;
        }

        return $this->context->withSchool($application->school, function () use ($application, $studentNumber, $section, $rollNumber, $startsOn, $guardian, $actor) {
            return DB::transaction(function () use ($application, $studentNumber, $section, $rollNumber, $startsOn, $guardian, $actor) {
                // Never trusts the caller's possibly-stale in-memory
                // $application->status -- reloads and locks the
                // authoritative row first, exactly like every Phase
                // 1D.2 lifecycle transition (root CLAUDE.md rule 14's
                // "reload, lock, conditionally transition" pattern).
                $locked = AdmissionApplication::query()->whereKey($application->id)->lockForUpdate()->firstOrFail();

                if ($locked->status === 'converted') {
                    throw new AdmissionApplicationAlreadyConvertedException;
                }
                if ($locked->status !== 'accepted') {
                    throw new InvalidAdmissionApplicationTransitionException($locked->status, 'converted');
                }

                $applicant = $locked->applicant;

                // Step 1 (ADMISSIONS.md §8): Student identity comes
                // from the Applicant -- decision_note is never read
                // here.
                $student = $this->studentService->create($locked->school, [
                    'student_number' => $studentNumber,
                    'first_name' => $applicant->first_name,
                    'middle_name' => $applicant->middle_name,
                    'last_name' => $applicant->last_name,
                    'date_of_birth' => $applicant->date_of_birth->toDateString(),
                ], $actor);

                [$guardianModel, $relationship] = $this->applyGuardianInstruction($locked, $student, $guardian, $actor);

                // Step 5 (ADMISSIONS.md §8): Section/roll_number/
                // starts_on are conversion-time-only, never pre-stored
                // on the application (§5).
                $enrollment = $this->enrollmentService->enroll($student, $section, $rollNumber, $startsOn, $actor);

                // Defense-in-depth double-guard matching every Phase
                // 1D.2 transition (this branch is expected unreachable
                // in correct operation -- $locked is already
                // exclusively row-locked above, so no concurrent
                // transaction could have changed its status between
                // that lock and this UPDATE within THIS transaction --
                // kept for the same reason
                // AcademicYearService::activate()'s conditional UPDATE
                // is kept even alongside its own row lock).
                $affected = AdmissionApplication::query()
                    ->whereKey($locked->id)
                    ->where('status', 'accepted')
                    ->update([
                        'status' => 'converted',
                        'converted_student_id' => $student->id,
                        'converted_student_enrollment_id' => $enrollment->id,
                        'converted_at' => now(),
                    ]);

                if ($affected === 0) {
                    throw new AdmissionApplicationAlreadyConvertedException;
                }

                $this->audit->school($locked->school, 'admission_application.converted', actor: $actor, subject: $locked, metadata: [
                    'applicantId' => $locked->applicant_id,
                    'studentId' => $student->id,
                    'studentEnrollmentId' => $enrollment->id,
                    'academicYearId' => $locked->academic_year_id,
                    'campusId' => $locked->campus_id,
                    'gradeLevelId' => $locked->grade_level_id,
                ]);

                return AdmissionConversionResult::make($locked->refresh(), $student, $enrollment, $guardianModel, $relationship);
            });
        });
    }

    /**
     * Steps 2-4 (ADMISSIONS.md §8), skipped entirely when `$guardian`
     * is `null` (the "no Guardian" mode). `link_existing`'s
     * cross-School safety is NOT duplicated here -- it is provided by
     * `StudentGuardianRelationshipService::link()`'s own
     * `CrossSchoolRelationshipException`, matching root CLAUDE.md rule
     * 13's "reuse an existing compatibility check, don't duplicate it"
     * discipline.
     *
     * @return array{0: ?Guardian, 1: ?StudentGuardianRelationship}
     */
    private function applyGuardianInstruction(AdmissionApplication $application, Student $student, ?GuardianConversionInstruction $guardian, ?User $actor): array
    {
        if ($guardian === null) {
            return [null, null];
        }

        if ($guardian->mode === 'create') {
            if ($guardian->hasContact()) {
                $candidates = $this->guardianContactService->findCandidatesBySchool($application->school, $guardian->contactType, $guardian->contactValue);

                if ($candidates->isNotEmpty()) {
                    throw new AdmissionGuardianSelectionRequiredException($candidates->count());
                }
            }

            $guardianModel = $this->guardianService->create($application->school, [
                'first_name' => $guardian->firstName,
                'middle_name' => $guardian->middleName,
                'last_name' => $guardian->lastName,
            ], $actor);

            if ($guardian->hasContact()) {
                $this->guardianContactService->create($guardianModel, $guardian->contactType, $guardian->contactValue, [], $actor);
            }
        } else {
            $guardianModel = $guardian->existingGuardian;
        }

        $relationship = $this->relationshipService->link($student, $guardianModel, $guardian->relationshipType, [
            'is_legal_guardian' => $guardian->isLegalGuardian,
            'is_emergency_contact' => $guardian->isEmergencyContact,
            'is_authorized_pickup' => $guardian->isAuthorizedPickup,
        ], $actor);

        return [$guardianModel, $relationship];
    }
}

<?php

namespace App\Domain\Students\Application;

use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\Students\Application\Exceptions\ActiveEnrollmentConflictException;
use App\Domain\Students\Application\Exceptions\CrossSchoolEnrollmentException;
use App\Domain\Students\Application\Exceptions\DuplicateEnrollmentRollNumberException;
use App\Domain\Students\Application\Exceptions\InvalidEnrollmentRollNumberException;
use App\Domain\Students\Infrastructure\Student;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Phase 1B.2: the ONLY sanctioned write path for creating a
 * StudentEnrollment -- never create one directly from a future
 * controller/import, and never accept `academic_year_id`/`campus_id`/
 * `grade_level_id`/`section_id` as independent caller input (mirrors
 * rule 19's "school_id never comes from request payload" principle,
 * applied to every academic-placement reference this service writes).
 * `academic_year_id`/`campus_id`/`grade_level_id` are always DERIVED
 * from the caller-supplied Section server-side -- see the migration
 * and docs/modules/STUDENT-ENROLLMENT.md ("Sanctioned Enrollment
 * creation path") for why this is what keeps those denormalized
 * columns consistent with `section_id` in every row this service ever
 * writes.
 *
 * Follows AcademicYearService/StudentGuardianRelationshipService's
 * established shape exactly: a cheap in-memory cross-School check
 * first (defense-in-depth ahead of the composite FKs, which remain the
 * authoritative guarantee -- rule 24's "check first, never rely on the
 * constraint alone" principle), then validate -> write state -> audit,
 * inside one transaction, with School context established from the
 * Student's own School (never an assumed ambient TenantContext).
 *
 * Deliberately authorization-neutral, matching every other Application
 * service in this codebase -- see docs/modules/STUDENT-ENROLLMENT.md
 * ("Authorization boundary") for exactly where a future controller
 * must call `Gate::authorize`/`authorizeCapability` before reaching
 * this service.
 *
 * Only Enrollment CREATION lives here. Historical rows are never
 * updated to represent a different placement (CLAUDE.md's historical-
 * record principle, Phase 1B.1) -- lifecycle transitions
 * (complete/withdraw/transfer/cancel) and the eventual close-old/
 * create-new pattern for transfers/promotion are Phase 1B.3's concern,
 * not this service's.
 */
class StudentEnrollmentService
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
    ) {}

    /**
     * Enrolls a Student into a Section for the AcademicYear/Campus/
     * GradeLevel that Section already belongs to -- the Section is the
     * single, authoritative placement input; a caller has no way to
     * independently express a different AcademicYear/Campus/GradeLevel
     * than the one the chosen Section actually has.
     *
     * Always creates the Enrollment with status `active` and no
     * `ends_on` -- an enrollment record with any other initial status,
     * or a backdated completion, is out of this method's scope (Phase
     * 1B.3).
     */
    public function enroll(Student $student, Section $section, string $rollNumber, string $startsOn, ?User $actor = null): StudentEnrollment
    {
        if ($student->school_id !== $section->school_id) {
            throw new CrossSchoolEnrollmentException;
        }

        $rollNumber = trim($rollNumber);
        if ($rollNumber === '') {
            throw new InvalidEnrollmentRollNumberException;
        }

        return $this->context->withSchool($student->school, function () use ($student, $section, $rollNumber, $startsOn, $actor) {
            try {
                return DB::transaction(function () use ($student, $section, $rollNumber, $startsOn, $actor) {
                    $enrollment = StudentEnrollment::query()->create([
                        'school_id' => $student->school_id,
                        'student_id' => $student->id,
                        'academic_year_id' => $section->academic_year_id,
                        'campus_id' => $section->campus_id,
                        'grade_level_id' => $section->grade_level_id,
                        'section_id' => $section->id,
                        'roll_number' => $rollNumber,
                        'status' => 'active',
                        'starts_on' => $startsOn,
                    ]);

                    $this->audit->school($student->school, 'student_enrollment.created', actor: $actor, subject: $enrollment, metadata: [
                        'studentId' => $student->id,
                        'academicYearId' => $section->academic_year_id,
                        'campusId' => $section->campus_id,
                        'gradeLevelId' => $section->grade_level_id,
                        'sectionId' => $section->id,
                    ]);

                    return $enrollment;
                });
            } catch (UniqueConstraintViolationException $e) {
                throw match ($e->index) {
                    'student_enrollments_one_active_per_student_year' => new ActiveEnrollmentConflictException,
                    'student_enrollments_school_id_academic_year_id_section_id_roll_' => new DuplicateEnrollmentRollNumberException($rollNumber),
                    default => $e,
                };
            }
        });
    }
}

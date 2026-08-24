<?php

namespace Tests\Concerns;

use App\Domain\AcademicStructure\Infrastructure\AcademicDepartment;
use App\Domain\AcademicStructure\Infrastructure\AcademicTerm;
use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\GradeLevel;
use App\Domain\AcademicStructure\Infrastructure\Room;
use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\AcademicStructure\Infrastructure\Subject;
use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use App\Domain\Guardians\Application\GuardianContactService;
use App\Domain\Guardians\Infrastructure\ContactType;
use App\Domain\Guardians\Infrastructure\Guardian;
use App\Domain\Guardians\Infrastructure\GuardianContact;
use App\Domain\Guardians\Infrastructure\StudentGuardianRelationship;
use App\Domain\Students\Infrastructure\EnrollmentRolloverItem;
use App\Domain\Students\Infrastructure\EnrollmentRolloverMapping;
use App\Domain\Students\Infrastructure\EnrollmentRolloverPlan;
use App\Domain\Students\Infrastructure\Student;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Models\Campus;
use App\Models\MembershipRoleAssignment;
use App\Models\PlatformRoleAssignment;
use App\Models\Role;
use App\Models\School;
use App\Models\SchoolMembership;
use App\Models\User;
use App\Support\Tenancy\TenantContext;

/**
 * Test fixtures deliberately go through the SAME rules production code
 * does -- creating a Campus (tenant-owned, RLS-protected) requires
 * TenantContext to be set to its School first, exactly like any real
 * request/job would need. There is no test-only bypass.
 */
trait CreatesTenancyFixtures
{
    protected function createSchool(array $attributes = []): School
    {
        return School::factory()->create($attributes);
    }

    protected function createCampus(School $school, array $attributes = []): Campus
    {
        return app(TenantContext::class)->withSchool(
            $school,
            fn () => Campus::factory()->for($school)->create($attributes),
        );
    }

    protected function createUser(array $attributes = []): User
    {
        return User::factory()->create($attributes);
    }

    protected function createMembership(User $user, School $school, string $status = 'active'): SchoolMembership
    {
        return SchoolMembership::query()->create([
            'user_id' => $user->id,
            'school_id' => $school->id,
            'status' => $status,
            'joined_at' => $status === 'active' ? now() : null,
        ]);
    }

    protected function assignSchoolRole(SchoolMembership $membership, string $roleKey): MembershipRoleAssignment
    {
        $role = Role::query()->where('key', $roleKey)->where('scope', 'school')->firstOrFail();

        return app(TenantContext::class)->withSchool(
            $membership->school,
            fn () => MembershipRoleAssignment::query()->create([
                'school_id' => $membership->school_id,
                'school_membership_id' => $membership->id,
                'role_id' => $role->id,
            ]),
        );
    }

    protected function assignPlatformRole(User $user, string $roleKey): PlatformRoleAssignment
    {
        $role = Role::query()->where('key', $roleKey)->where('scope', 'platform')->firstOrFail();

        return PlatformRoleAssignment::query()->create([
            'user_id' => $user->id,
            'role_id' => $role->id,
        ]);
    }

    /**
     * Convenience: creates a School, an active member with the given
     * school-scoped role, and returns [User, School].
     *
     * @return array{0: User, 1: School}
     */
    protected function createSchoolAdmin(string $roleKey = 'school_admin'): array
    {
        $school = $this->createSchool();
        $user = $this->createUser();
        $membership = $this->createMembership($user, $school);
        $this->assignSchoolRole($membership, $roleKey);

        return [$user, $school];
    }

    // --- Phase 0D: Academic Structure fixtures -----------------------

    protected function createAcademicYear(School $school, array $attributes = []): AcademicYear
    {
        return app(TenantContext::class)->withSchool(
            $school,
            fn () => AcademicYear::factory()->for($school, 'school')->create($attributes),
        );
    }

    protected function createAcademicTerm(AcademicYear $year, array $attributes = []): AcademicTerm
    {
        return app(TenantContext::class)->withSchool(
            $year->school,
            fn () => AcademicTerm::factory()->create(array_merge([
                'school_id' => $year->school_id,
                'academic_year_id' => $year->id,
            ], $attributes)),
        );
    }

    protected function createGradeLevel(School $school, array $attributes = []): GradeLevel
    {
        return app(TenantContext::class)->withSchool(
            $school,
            fn () => GradeLevel::factory()->for($school, 'school')->create($attributes),
        );
    }

    protected function createAcademicDepartment(School $school, array $attributes = []): AcademicDepartment
    {
        return app(TenantContext::class)->withSchool(
            $school,
            fn () => AcademicDepartment::factory()->for($school, 'school')->create($attributes),
        );
    }

    protected function createSubject(School $school, array $attributes = []): Subject
    {
        return app(TenantContext::class)->withSchool(
            $school,
            fn () => Subject::factory()->for($school, 'school')->create($attributes),
        );
    }

    protected function createRoom(Campus $campus, array $attributes = []): Room
    {
        return app(TenantContext::class)->withSchool(
            $campus->school,
            fn () => Room::factory()->create(array_merge([
                'school_id' => $campus->school_id,
                'campus_id' => $campus->id,
            ], $attributes)),
        );
    }

    protected function createSection(AcademicYear $year, Campus $campus, GradeLevel $gradeLevel, array $attributes = []): Section
    {
        return app(TenantContext::class)->withSchool(
            $year->school,
            fn () => Section::factory()->create(array_merge([
                'school_id' => $year->school_id,
                'academic_year_id' => $year->id,
                'campus_id' => $campus->id,
                'grade_level_id' => $gradeLevel->id,
            ], $attributes)),
        );
    }

    protected function createSubjectOffering(AcademicYear $year, Campus $campus, GradeLevel $gradeLevel, Subject $subject, array $attributes = []): SubjectOffering
    {
        return app(TenantContext::class)->withSchool(
            $year->school,
            fn () => SubjectOffering::factory()->create(array_merge([
                'school_id' => $year->school_id,
                'academic_year_id' => $year->id,
                'campus_id' => $campus->id,
                'grade_level_id' => $gradeLevel->id,
                'subject_id' => $subject->id,
            ], $attributes)),
        );
    }

    // --- Phase 1A: Student & Guardian identity fixtures ---------------

    protected function createStudent(School $school, array $attributes = []): Student
    {
        return app(TenantContext::class)->withSchool(
            $school,
            fn () => Student::factory()->for($school, 'school')->create($attributes),
        );
    }

    protected function createGuardian(School $school, array $attributes = []): Guardian
    {
        return app(TenantContext::class)->withSchool(
            $school,
            fn () => Guardian::factory()->for($school, 'school')->create($attributes),
        );
    }

    protected function createStudentGuardianRelationship(Student $student, Guardian $guardian, array $attributes = []): StudentGuardianRelationship
    {
        return app(TenantContext::class)->withSchool(
            $student->school,
            fn () => StudentGuardianRelationship::factory()->create(array_merge([
                'school_id' => $student->school_id,
                'student_id' => $student->id,
                'guardian_id' => $guardian->id,
            ], $attributes)),
        );
    }

    /**
     * Goes through the real GuardianContactService -- not a raw
     * factory create -- so normalization, encryption, and lookup-hash
     * computation are always the genuine production code path, exactly
     * like every other fixture helper above uses real
     * TenantContext::withSchool() rather than a test-only bypass.
     */
    protected function createGuardianContact(Guardian $guardian, ContactType $type, string $rawValue, array $attributes = []): GuardianContact
    {
        return app(GuardianContactService::class)->create($guardian, $type, $rawValue, $attributes);
    }

    // --- Phase 1B.1: Student Enrollment fixtures -----------------------

    /**
     * academic_year_id/campus_id/grade_level_id are always derived from
     * the given Section, exactly like the (not-yet-built) Phase 1B.4
     * StudentEnrollmentService will derive them in production -- a test
     * fixture must never be able to construct an inconsistent
     * Section-vs-denormalized-parent state that real code could not
     * produce.
     */
    protected function createStudentEnrollment(Student $student, Section $section, array $attributes = []): StudentEnrollment
    {
        return app(TenantContext::class)->withSchool(
            $student->school,
            fn () => StudentEnrollment::factory()->create(array_merge([
                'school_id' => $student->school_id,
                'student_id' => $student->id,
                'academic_year_id' => $section->academic_year_id,
                'campus_id' => $section->campus_id,
                'grade_level_id' => $section->grade_level_id,
                'section_id' => $section->id,
            ], $attributes)),
        );
    }

    // --- Phase 1B.7A: Enrollment Rollover fixtures -----------------------

    protected function createEnrollmentRolloverPlan(AcademicYear $sourceYear, AcademicYear $targetYear, array $attributes = []): EnrollmentRolloverPlan
    {
        return app(TenantContext::class)->withSchool(
            $sourceYear->school,
            fn () => EnrollmentRolloverPlan::factory()->create(array_merge([
                'school_id' => $sourceYear->school_id,
                'source_academic_year_id' => $sourceYear->id,
                'target_academic_year_id' => $targetYear->id,
            ], $attributes)),
        );
    }

    protected function createEnrollmentRolloverMapping(EnrollmentRolloverPlan $plan, GradeLevel $sourceGradeLevel, GradeLevel $targetGradeLevel, array $attributes = []): EnrollmentRolloverMapping
    {
        return app(TenantContext::class)->withSchool(
            $plan->school,
            fn () => EnrollmentRolloverMapping::factory()->create(array_merge([
                'school_id' => $plan->school_id,
                'plan_id' => $plan->id,
                'source_grade_level_id' => $sourceGradeLevel->id,
                'target_grade_level_id' => $targetGradeLevel->id,
            ], $attributes)),
        );
    }

    /**
     * `source_enrollment_id` must belong to the exact `$student` given
     * (the DB's own double composite FK on `enrollment_rollover_items`
     * enforces this structurally) -- callers pass a real
     * StudentEnrollment produced for that Student, exactly like
     * createStudentEnrollment() itself is used elsewhere.
     */
    protected function createEnrollmentRolloverItem(EnrollmentRolloverPlan $plan, Student $student, StudentEnrollment $sourceEnrollment, array $attributes = []): EnrollmentRolloverItem
    {
        return app(TenantContext::class)->withSchool(
            $plan->school,
            fn () => EnrollmentRolloverItem::factory()->create(array_merge([
                'school_id' => $plan->school_id,
                'plan_id' => $plan->id,
                'student_id' => $student->id,
                'source_enrollment_id' => $sourceEnrollment->id,
            ], $attributes)),
        );
    }
}

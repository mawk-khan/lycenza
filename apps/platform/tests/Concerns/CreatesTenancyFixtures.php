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
use App\Domain\Admissions\Infrastructure\AdmissionApplication;
use App\Domain\Admissions\Infrastructure\Applicant;
use App\Domain\Documents\Infrastructure\Document;
use App\Domain\Guardians\Application\GuardianContactService;
use App\Domain\Guardians\Infrastructure\ContactType;
use App\Domain\Guardians\Infrastructure\Guardian;
use App\Domain\Guardians\Infrastructure\GuardianContact;
use App\Domain\Guardians\Infrastructure\StudentGuardianRelationship;
use App\Domain\HR\Infrastructure\Department;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmployeeAddress;
use App\Domain\HR\Infrastructure\EmployeeAssignment;
use App\Domain\HR\Infrastructure\EmployeeCertification;
use App\Domain\HR\Infrastructure\EmployeeDocument;
use App\Domain\HR\Infrastructure\EmployeeEmergencyContact;
use App\Domain\HR\Infrastructure\EmployeeExperience;
use App\Domain\HR\Infrastructure\EmployeePersonalDetail;
use App\Domain\HR\Infrastructure\EmployeeQualification;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\HR\Infrastructure\Position;
use App\Domain\Students\Infrastructure\EnrollmentRolloverItem;
use App\Domain\Students\Infrastructure\EnrollmentRolloverMapping;
use App\Domain\Students\Infrastructure\EnrollmentRolloverPlan;
use App\Domain\Students\Infrastructure\Student;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Domain\Students\Infrastructure\StudentSubjectEnrollment;
use App\Models\Campus;
use App\Models\MembershipRoleAssignment;
use App\Models\PlatformRoleAssignment;
use App\Models\Role;
use App\Models\School;
use App\Models\SchoolMembership;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Str;

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

    /**
     * Phase 8A.10: creates a User with an ACTIVE membership at $school
     * and an ad hoc, non-system school-scoped Role holding exactly
     * $capabilities -- no more, no less. This is the standard way
     * 8A.10's authorization tests construct an actor with a precise
     * capability set (e.g. "documents.manage but NOT sensitive.manage")
     * without depending on -- or mutating -- the seeded system roles.
     * Reuses the exact same Role/Capability/MembershipRoleAssignment
     * tables production code uses; this is not a parallel test-only ACL
     * mechanism (root CLAUDE.md rule 2 / 8A.10 brief section 3).
     *
     * @param  array<int, string>  $capabilities
     */
    protected function createUserWithCapabilities(School $school, array $capabilities, string $status = 'active'): User
    {
        $user = $this->createUser();
        $membership = $this->createMembership($user, $school, $status);

        if ($capabilities !== []) {
            $role = Role::query()->create([
                'key' => 'test.capability_grant.'.(string) Str::uuid(),
                'name' => 'Test Capability Grant',
                'scope' => 'school',
                'is_system' => false,
            ]);
            $role->capabilities()->sync($capabilities);
            $this->assignSchoolRole($membership, $role->key);
        }

        return $user;
    }

    /**
     * Phase 8A.10 fixture convenience: an actor holding EVERY HR
     * capability at $school. Used by pre-8A.10 (8A.1-8A.9) tests that
     * are not themselves testing authorization -- once HR Application
     * services require an authorized actor, these tests continue to
     * exercise their original domain invariants (overlap validation,
     * ownership checks, verification-reset rules, ...) unchanged, using
     * an actor that is never the thing under test. 8A.10's OWN
     * authorization tests use createUserWithCapabilities() directly
     * with a narrow, deliberate capability list instead -- never this
     * method, which would defeat the point of a deny test.
     */
    protected function fullHrActor(School $school): User
    {
        return $this->createUserWithCapabilities($school, [
            'hr.employees.view', 'hr.employees.manage',
            'hr.employees.personal.view', 'hr.employees.personal.manage',
            'hr.employees.assignments.view', 'hr.employees.assignments.manage',
            'hr.employees.qualifications.view', 'hr.employees.qualifications.manage',
            'hr.employees.documents.view', 'hr.employees.documents.manage',
            'hr.employees.sensitive.view', 'hr.employees.sensitive.manage',
            'hr.employees.notes.view', 'hr.employees.notes.manage',
            'hr.departments.view', 'hr.departments.manage',
            'hr.positions.view', 'hr.positions.manage',
        ]);
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

    // --- Phase 1C.1: Student Subject Enrollment fixtures ------------------

    protected function createStudentSubjectEnrollment(Student $student, SubjectOffering $offering, array $attributes = []): StudentSubjectEnrollment
    {
        return app(TenantContext::class)->withSchool(
            $student->school,
            fn () => StudentSubjectEnrollment::factory()->create(array_merge([
                'school_id' => $student->school_id,
                'student_id' => $student->id,
                'subject_offering_id' => $offering->id,
                'academic_year_id' => $offering->academic_year_id,
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

    // --- Phase 8A.1: HR / Employee fixtures ---------------------------

    /**
     * Factory-based creation for schema/relationship/isolation tests --
     * `employee_number` here is a plausible fake value, NOT allocated
     * through App\Domain\HR\Application\EmployeeNumberAllocator. A test
     * about allocation/concurrency behavior itself must call
     * App\Domain\HR\Application\EmployeeService::create() directly.
     */
    protected function createEmployee(School $school, array $attributes = []): Employee
    {
        return app(TenantContext::class)->withSchool(
            $school,
            fn () => Employee::factory()->for($school, 'school')->create($attributes),
        );
    }

    // --- Phase 8A.2: Personal details, addresses, emergency contacts --

    protected function createEmployeePersonalDetail(Employee $employee, array $attributes = []): EmployeePersonalDetail
    {
        return app(TenantContext::class)->withSchool(
            $employee->school,
            fn () => EmployeePersonalDetail::factory()->create(array_merge([
                'school_id' => $employee->school_id,
                'employee_id' => $employee->id,
            ], $attributes)),
        );
    }

    protected function createEmployeeAddress(Employee $employee, array $attributes = []): EmployeeAddress
    {
        return app(TenantContext::class)->withSchool(
            $employee->school,
            fn () => EmployeeAddress::factory()->create(array_merge([
                'school_id' => $employee->school_id,
                'employee_id' => $employee->id,
            ], $attributes)),
        );
    }

    protected function createEmployeeEmergencyContact(Employee $employee, array $attributes = []): EmployeeEmergencyContact
    {
        return app(TenantContext::class)->withSchool(
            $employee->school,
            fn () => EmployeeEmergencyContact::factory()->create(array_merge([
                'school_id' => $employee->school_id,
                'employee_id' => $employee->id,
            ], $attributes)),
        );
    }

    // --- Phase 8A.3: Departments & Positions ---------------------------

    protected function createDepartment(School $school, array $attributes = []): Department
    {
        return app(TenantContext::class)->withSchool(
            $school,
            fn () => Department::factory()->for($school, 'school')->create($attributes),
        );
    }

    protected function createPosition(School $school, array $attributes = []): Position
    {
        return app(TenantContext::class)->withSchool(
            $school,
            fn () => Position::factory()->for($school, 'school')->create($attributes),
        );
    }

    // --- Phase 8A.4: Employment Records & Employee Assignments ---------

    protected function createEmploymentRecord(Employee $employee, array $attributes = []): EmploymentRecord
    {
        return app(TenantContext::class)->withSchool(
            $employee->school,
            fn () => EmploymentRecord::factory()->create(array_merge([
                'school_id' => $employee->school_id,
                'employee_id' => $employee->id,
            ], $attributes)),
        );
    }

    protected function createEmployeeAssignment(EmploymentRecord $employment, Position $position, array $attributes = []): EmployeeAssignment
    {
        return app(TenantContext::class)->withSchool(
            $employment->school,
            fn () => EmployeeAssignment::factory()->create(array_merge([
                'school_id' => $employment->school_id,
                'employment_record_id' => $employment->id,
                'position_id' => $position->id,
            ], $attributes)),
        );
    }

    // --- Phase 8A.6: Qualifications, Experience & Certifications -------

    protected function createEmployeeQualification(Employee $employee, array $attributes = []): EmployeeQualification
    {
        return app(TenantContext::class)->withSchool(
            $employee->school,
            fn () => EmployeeQualification::factory()->create(array_merge([
                'school_id' => $employee->school_id,
                'employee_id' => $employee->id,
            ], $attributes)),
        );
    }

    protected function createEmployeeExperience(Employee $employee, array $attributes = []): EmployeeExperience
    {
        return app(TenantContext::class)->withSchool(
            $employee->school,
            fn () => EmployeeExperience::factory()->create(array_merge([
                'school_id' => $employee->school_id,
                'employee_id' => $employee->id,
            ], $attributes)),
        );
    }

    protected function createEmployeeCertification(Employee $employee, array $attributes = []): EmployeeCertification
    {
        return app(TenantContext::class)->withSchool(
            $employee->school,
            fn () => EmployeeCertification::factory()->create(array_merge([
                'school_id' => $employee->school_id,
                'employee_id' => $employee->id,
            ], $attributes)),
        );
    }

    // --- Phase 8A.7: Employee Documents ---------------------------------

    protected function createEmployeeDocument(Employee $employee, array $attributes = []): EmployeeDocument
    {
        return app(TenantContext::class)->withSchool(
            $employee->school,
            fn () => EmployeeDocument::factory()->create(array_merge([
                'school_id' => $employee->school_id,
                'employee_id' => $employee->id,
            ], $attributes)),
        );
    }

    // --- Phase 0E.1: Documents foundation -------------------------------

    protected function createDocumentForEmployee(Employee $employee, array $attributes = []): Document
    {
        return app(TenantContext::class)->withSchool(
            $employee->school,
            fn () => Document::factory()->create(array_merge([
                'school_id' => $employee->school_id,
                'employee_id' => $employee->id,
            ], $attributes)),
        );
    }

    protected function createDocumentForStudent(Student $student, array $attributes = []): Document
    {
        return app(TenantContext::class)->withSchool(
            $student->school,
            fn () => Document::factory()->create(array_merge([
                'school_id' => $student->school_id,
                'student_id' => $student->id,
            ], $attributes)),
        );
    }

    protected function createDocumentForGuardian(Guardian $guardian, array $attributes = []): Document
    {
        return app(TenantContext::class)->withSchool(
            $guardian->school,
            fn () => Document::factory()->create(array_merge([
                'school_id' => $guardian->school_id,
                'guardian_id' => $guardian->id,
            ], $attributes)),
        );
    }

    // --- Phase 1D.1: Admissions fixtures --------------------------------

    protected function createApplicant(School $school, array $attributes = []): Applicant
    {
        return app(TenantContext::class)->withSchool(
            $school,
            fn () => Applicant::factory()->for($school, 'school')->create($attributes),
        );
    }

    protected function createAdmissionApplication(Applicant $applicant, AcademicYear $year, Campus $campus, GradeLevel $gradeLevel, array $attributes = []): AdmissionApplication
    {
        return app(TenantContext::class)->withSchool(
            $applicant->school,
            fn () => AdmissionApplication::factory()->create(array_merge([
                'school_id' => $applicant->school_id,
                'applicant_id' => $applicant->id,
                'academic_year_id' => $year->id,
                'campus_id' => $campus->id,
                'grade_level_id' => $gradeLevel->id,
            ], $attributes)),
        );
    }
}

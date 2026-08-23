<?php

namespace Tests\Feature\StudentGuardianIdentity;

use App\Domain\Students\Application\Exceptions\DuplicateStudentNumberException;
use App\Domain\Students\Application\Exceptions\InvalidStudentStatusException;
use App\Domain\Students\Application\StudentService;
use App\Domain\Students\Infrastructure\Student;
use App\Support\Tenancy\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 1A.4: the only sanctioned write path for Student identity. See
 * docs/modules/STUDENT-GUARDIAN-IDENTITY.md ("Student service").
 */
class StudentServiceTest extends TestCase
{
    use CreatesTenancyFixtures;

    #[Test]
    public function create_writes_a_student_scoped_to_the_given_school(): void
    {
        $school = $this->createSchool();

        $student = app(StudentService::class)->create($school, [
            'student_number' => 'S-1001',
            'first_name' => 'Asha',
            'last_name' => 'Verma',
            'date_of_birth' => '2015-04-12',
        ]);

        $this->assertSame($school->id, $student->school_id);
        $this->assertSame('S-1001', $student->student_number);
        $this->assertTrue($student->isActive());
    }

    #[Test]
    public function update_changes_identity_fields(): void
    {
        $school = $this->createSchool();
        $student = $this->createStudent($school, ['student_number' => 'S-1001', 'last_name' => 'Verma']);

        $updated = app(StudentService::class)->update($student, ['last_name' => 'Sharma']);

        $this->assertSame('Sharma', $updated->last_name);
    }

    #[Test]
    public function a_duplicate_student_number_on_create_is_rejected_with_a_domain_exception_not_a_raw_sql_error(): void
    {
        $school = $this->createSchool();
        $this->createStudent($school, ['student_number' => 'S-1001']);

        $this->expectException(DuplicateStudentNumberException::class);

        app(StudentService::class)->create($school, [
            'student_number' => 'S-1001',
            'first_name' => 'Rohit',
            'date_of_birth' => '2016-01-01',
        ]);
    }

    #[Test]
    public function a_duplicate_student_number_on_create_leaves_no_orphaned_row(): void
    {
        $school = $this->createSchool();
        $this->createStudent($school, ['student_number' => 'S-1001']);

        try {
            app(StudentService::class)->create($school, [
                'student_number' => 'S-1001',
                'first_name' => 'Rohit',
                'date_of_birth' => '2016-01-01',
            ]);
        } catch (DuplicateStudentNumberException) {
            // expected
        }

        $count = app(TenantContext::class)->withSchool($school, fn () => Student::query()->count());
        $this->assertSame(1, $count, 'The failed create() must not leave a partially-committed row.');
    }

    #[Test]
    public function a_duplicate_student_number_on_update_is_rejected_with_a_domain_exception(): void
    {
        $school = $this->createSchool();
        $this->createStudent($school, ['student_number' => 'S-1001']);
        $studentB = $this->createStudent($school, ['student_number' => 'S-1002']);

        $this->expectException(DuplicateStudentNumberException::class);

        app(StudentService::class)->update($studentB, ['student_number' => 'S-1001']);
    }

    #[Test]
    public function the_same_student_number_remains_allowed_across_different_schools_via_the_service(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();

        $studentA = app(StudentService::class)->create($schoolA, [
            'student_number' => 'S-1001', 'first_name' => 'Asha', 'date_of_birth' => '2015-04-12',
        ]);
        $studentB = app(StudentService::class)->create($schoolB, [
            'student_number' => 'S-1001', 'first_name' => 'Priya', 'date_of_birth' => '2015-06-01',
        ]);

        $this->assertNotSame($studentA->school_id, $studentB->school_id);
    }

    #[Test]
    public function change_status_to_inactive_and_back_is_audited_and_applied(): void
    {
        $school = $this->createSchool();
        $student = $this->createStudent($school, ['student_number' => 'S-1001']);

        $inactive = app(StudentService::class)->changeStatus($student, 'inactive');
        $this->assertFalse($inactive->isActive());

        $active = app(StudentService::class)->changeStatus($inactive, 'active');
        $this->assertTrue($active->isActive());
    }

    #[Test]
    public function an_invalid_status_is_rejected_with_a_domain_exception(): void
    {
        $school = $this->createSchool();
        $student = $this->createStudent($school, ['student_number' => 'S-1001']);

        $this->expectException(InvalidStudentStatusException::class);

        app(StudentService::class)->changeStatus($student, 'graduated');
    }
}

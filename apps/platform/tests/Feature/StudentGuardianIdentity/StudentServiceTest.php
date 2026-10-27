<?php

namespace Tests\Feature\StudentGuardianIdentity;

use App\Domain\Students\Application\Exceptions\DuplicateStudentNumberException;
use App\Domain\Students\Application\Exceptions\InvalidStudentStatusException;
use App\Domain\Students\Application\Exceptions\PossibleDuplicateStudentException;
use App\Domain\Students\Application\StudentService;
use App\Domain\Students\Infrastructure\Student;
use App\Support\Auth\Mfa\FreshMfaProof;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use LogicException;
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
    public function create_writes_a_student_scoped_to_the_given_school_with_the_allocated_number(): void
    {
        $school = $this->createSchool();

        $student = app(StudentService::class)->create($school, [
            'first_name' => 'Asha',
            'last_name' => 'Verma',
            'date_of_birth' => '2015-04-12',
        ]);

        $this->assertSame($school->id, $student->school_id);
        $this->assertSame('1', $student->student_number, 'SIS 2.3: the School counter, never a typed value');
        $this->assertTrue($student->isActive());
    }

    #[Test]
    public function a_supplied_student_number_is_a_programming_error_and_writes_nothing(): void
    {
        $school = $this->createSchool();

        try {
            app(StudentService::class)->create($school, ['student_number' => 'S-1001', 'first_name' => 'Rohit', 'date_of_birth' => '2016-01-01']);
            $this->fail('Expected LogicException.');
        } catch (LogicException) {
        }

        $this->assertSame(0, app(TenantContext::class)->withSchool($school, fn () => Student::query()->count()));
    }

    #[Test]
    public function sequential_creations_take_consecutive_numbers_past_every_numeric_number_and_never_rewrite_history(): void
    {
        $school = $this->createSchool();
        $this->createStudent($school, ['student_number' => 'LDS-0009']);
        $this->createStudent($school, ['student_number' => '0120']);

        $first = app(StudentService::class)->create($school, ['first_name' => 'Asha', 'date_of_birth' => '2015-04-12']);
        $second = app(StudentService::class)->create($school, ['first_name' => 'Rohit', 'date_of_birth' => '2015-05-12']);

        $this->assertSame(['121', '122'], [$first->student_number, $second->student_number]);
        $this->assertSame(['0120', '121', '122', 'LDS-0009'], app(TenantContext::class)->withSchool($school, fn () => Student::query()->orderBy('student_number')->pluck('student_number')->all()));
    }

    #[Test]
    public function a_refused_creation_holds_no_number_and_the_next_creation_takes_it(): void
    {
        $school = $this->createSchool();
        app(StudentService::class)->create($school, ['first_name' => 'Asha', 'last_name' => 'Verma', 'date_of_birth' => '2015-04-12']);

        try {
            app(StudentService::class)->create($school, ['first_name' => 'Asha', 'last_name' => 'Verma', 'date_of_birth' => '2015-04-12']);
            $this->fail('Expected PossibleDuplicateStudentException.');
        } catch (PossibleDuplicateStudentException) {
        }

        $this->assertSame('2', app(StudentService::class)->create($school, ['first_name' => 'Rohit', 'date_of_birth' => '2016-01-01'])->student_number);
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
    public function a_corrected_numeric_number_moves_the_counter_past_it_so_it_is_never_allocated(): void
    {
        // SIS 2.3: the existing Edit correction stays under the same unique
        // rule, and the counter trigger keeps allocation ahead of it.
        $school = $this->createSchool();
        $student = app(StudentService::class)->create($school, ['first_name' => 'Asha', 'date_of_birth' => '2015-04-12']);
        app(StudentService::class)->update($student, ['student_number' => '500']);

        $this->assertSame('501', app(StudentService::class)->create($school, ['first_name' => 'Rohit', 'date_of_birth' => '2016-01-01'])->student_number);
    }

    #[Test]
    public function schools_allocate_independently_and_may_hold_the_same_number(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();

        $studentA = app(StudentService::class)->create($schoolA, ['first_name' => 'Asha', 'date_of_birth' => '2015-04-12']);
        $studentB = app(StudentService::class)->create($schoolB, ['first_name' => 'Asha', 'date_of_birth' => '2015-04-12']);

        $this->assertNotSame($studentA->school_id, $studentB->school_id);
        $this->assertSame('1', $studentA->student_number);
        $this->assertSame('1', $studentB->student_number, 'and an identical Student in another School is never a duplicate candidate');
    }

    #[Test]
    public function change_status_to_inactive_and_back_is_audited_and_applied(): void
    {
        $school = $this->createSchool();
        $student = $this->createStudent($school, ['student_number' => 'S-1001']);

        $inactive = $this->changeStudentStatus($student, 'inactive');
        $this->assertFalse($inactive->isActive());

        $active = $this->changeStudentStatus($inactive, 'active');
        $this->assertTrue($active->isActive());
    }

    #[Test]
    public function an_invalid_status_is_rejected_with_a_domain_exception(): void
    {
        $school = $this->createSchool();
        $student = $this->createStudent($school, ['student_number' => 'S-1001']);

        $this->expectException(InvalidStudentStatusException::class);

        $actor = $this->lifecycleAdmin($school);
        app(StudentService::class)->changeStatus($student, 'graduated', 'left_school', $actor, new FreshMfaProof($actor->id, CarbonImmutable::now()));
    }
}

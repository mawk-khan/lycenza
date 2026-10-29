<?php

namespace Tests\Feature\Students;

use App\Domain\Guardians\Application\GuardianService;
use App\Domain\Guardians\Infrastructure\Guardian;
use App\Domain\Students\Application\StudentService;
use App\Domain\Students\Infrastructure\Student;
use App\Support\Tenancy\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * SIS.EXP-1 (SIS.EXP-0 finding L-1): a Student's and a Guardian's School and
 * lifecycle status are structural. They are not mass assignable, so a
 * slipped field whitelist can never move a record to another School or
 * change its status; only the trusted services write them, explicitly. The
 * database (RLS WITH CHECK, lifecycle-evidence triggers) stays the backstop.
 */
class StudentMassAssignmentTest extends TestCase
{
    use CreatesTenancyFixtures;

    #[Test]
    public function school_and_status_are_not_fillable_and_can_never_silently_become_so(): void
    {
        foreach ([new Student, new Guardian] as $model) {
            $this->assertFalse($model->isFillable('school_id'), $model::class.' school_id');
            $this->assertFalse($model->isFillable('status'), $model::class.' status');
            $this->assertTrue($model->isFillable('first_name'), $model::class.' names stay fillable');
        }
        $this->assertSame(['student_number', 'first_name', 'middle_name', 'last_name', 'date_of_birth'], (new Student)->getFillable());
        $this->assertSame(['first_name', 'middle_name', 'last_name'], (new Guardian)->getFillable());
        $this->assertSame((new Student)->getFillable(), StudentService::PROFILE_FIELDS, 'the identity edit writes exactly the fillable fields');
    }

    #[Test]
    public function update_and_fill_drop_smuggled_structural_fields(): void
    {
        $school = $this->createSchool();
        $other = $this->createSchool();
        $student = $this->createStudent($school, ['first_name' => 'Asha']);
        $guardian = $this->createGuardian($school, ['first_name' => 'Ravi']);

        app(TenantContext::class)->withSchool($school, function () use ($student, $guardian, $other): void {
            $student->update(['first_name' => 'Asha K', 'status' => 'inactive', 'school_id' => $other->id]);
            $guardian->update(['first_name' => 'Ravi K', 'status' => 'inactive', 'school_id' => $other->id]);
        });

        $freshStudent = app(TenantContext::class)->withSchool($school, fn () => Student::query()->findOrFail($student->id));
        $freshGuardian = app(TenantContext::class)->withSchool($school, fn () => Guardian::query()->findOrFail($guardian->id));
        $this->assertSame(['Asha K', 'active', $school->id], [$freshStudent->first_name, $freshStudent->status, $freshStudent->school_id]);
        $this->assertSame(['Ravi K', 'active', $school->id], [$freshGuardian->first_name, $freshGuardian->status, $freshGuardian->school_id]);
    }

    #[Test]
    public function the_service_whitelist_ignores_structural_fields_even_if_a_caller_passes_them(): void
    {
        [$admin, $school] = $this->createSchoolAdmin();
        $student = $this->createStudent($school, ['first_name' => 'Asha']);

        $updated = app(StudentService::class)->update($student, ['first_name' => 'Asha R', 'status' => 'inactive', 'school_id' => $this->createSchool()->id], $admin);

        $this->assertSame(['Asha R', 'active', $school->id], [$updated->first_name, $updated->status, $updated->school_id]);
    }

    #[Test]
    public function the_trusted_paths_still_create_and_change_status(): void
    {
        [$admin, $school] = $this->createSchoolAdmin();
        $guardians = app(GuardianService::class);

        $guardian = $guardians->create($school, ['first_name' => 'Meena'], $admin);
        $this->assertSame([$school->id, 'active'], [$guardian->school_id, $guardian->status]);
        $this->assertSame('inactive', $guardians->changeStatus($guardian, 'inactive', $admin)->status);

        $student = app(StudentService::class)->create($school, ['first_name' => 'Kiran', 'middle_name' => null, 'last_name' => null, 'date_of_birth' => '2015-01-01'], $admin);
        $this->assertSame([$school->id, 'active'], [$student->school_id, $student->status]);
    }
}

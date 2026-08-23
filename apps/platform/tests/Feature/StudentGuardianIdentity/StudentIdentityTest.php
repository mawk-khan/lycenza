<?php

namespace Tests\Feature\StudentGuardianIdentity;

use App\Domain\Students\Infrastructure\Student;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 1A foundation slice: proves the canonical Student data model --
 * a permanent School-level identity, independent of enrollment/grade/
 * section state -- before any API/UI is built on top of it. See
 * docs/modules/STUDENT-GUARDIAN-IDENTITY.md.
 */
class StudentIdentityTest extends TestCase
{
    use CreatesTenancyFixtures;

    #[Test]
    public function a_student_can_be_created_within_a_school(): void
    {
        $school = $this->createSchool();

        $student = $this->createStudent($school, ['student_number' => 'S-0001']);

        $this->assertSame($school->id, $student->school_id);
        $this->assertSame('S-0001', $student->student_number);
        $this->assertTrue($student->isActive());
    }

    #[Test]
    public function the_same_student_number_is_rejected_within_the_same_school(): void
    {
        $school = $this->createSchool();
        $this->createStudent($school, ['student_number' => 'S-0001']);

        $this->expectException(QueryException::class);

        // Wrapped in its own transaction (a nested SAVEPOINT) so the
        // expected unique-constraint failure rolls back cleanly instead
        // of leaving the `pgsql` connection in Postgres's "current
        // transaction is aborted" state for withSchool()'s own
        // RESET-on-exit statement -- see
        // AcademicYearLifecycleTest::the_database_check_constraint_rejects_an_inverted_date_range
        // for the identical pattern.
        app(TenantContext::class)->withSchool($school, fn () => DB::transaction(fn () => Student::factory()->for($school, 'school')->create([
            'student_number' => 'S-0001',
        ])));
    }

    #[Test]
    public function the_same_student_number_is_allowed_across_different_schools(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();

        $studentA = $this->createStudent($schoolA, ['student_number' => 'S-0001']);
        $studentB = $this->createStudent($schoolB, ['student_number' => 'S-0001']);

        $this->assertSame('S-0001', $studentA->student_number);
        $this->assertSame('S-0001', $studentB->student_number);
        $this->assertNotSame($studentA->school_id, $studentB->school_id);
    }

    #[Test]
    public function school_a_cannot_see_school_bs_student_through_the_eloquent_scope(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $studentB = $this->createStudent($schoolB, ['student_number' => 'S-0001']);

        $visible = app(TenantContext::class)->withSchool(
            $schoolA,
            fn () => Student::query()->count(),
        );

        $this->assertSame(0, $visible);
        $this->assertNotNull($studentB->id);
    }

    #[Test]
    public function school_a_cannot_retrieve_school_bs_student_by_uuid_through_the_eloquent_scope(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $studentB = $this->createStudent($schoolB, ['student_number' => 'S-0001']);

        $found = app(TenantContext::class)->withSchool(
            $schoolA,
            fn () => Student::query()->find($studentB->id),
        );

        $this->assertNull($found);
    }
}

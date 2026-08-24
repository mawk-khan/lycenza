<?php

namespace Tests\Feature\HR;

use App\Domain\HR\Application\EmployeeImportService;
use App\Domain\HR\Application\EmployeeService;
use App\Domain\HR\Infrastructure\Employee;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 8A.12 -- REQUIRED duplicate-detection proof (checkpoint brief
 * sections 73/50/51/53). No fuzzy/ML matching; two deterministic
 * signals only (User linkage = exact, normalized full_name = potential
 * warning, never a merge).
 */
class HrEmployeeImportDuplicateTest extends TestCase
{
    use CreatesTenancyFixtures;

    #[Test]
    public function importing_the_same_user_linked_row_twice_is_an_exact_duplicate_not_a_second_employee(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);
        $linkedUser = $this->createUser();
        $this->createMembership($linkedUser, $school);

        $service = app(EmployeeImportService::class);
        $first = $service->import($school, $actor, [['full_name' => 'Asha Verma', 'user_id' => $linkedUser->id]]);
        $second = $service->import($school, $actor, [['full_name' => 'Asha Verma', 'user_id' => $linkedUser->id]]);

        $this->assertSame('created', $first->rows[0]->status);
        $this->assertSame('duplicate_exact', $second->rows[0]->status);
        $this->assertSame($first->rows[0]->employeeId, $second->rows[0]->employeeId);
        $this->assertSame(1, $this->employeeCount($school));
    }

    #[Test]
    public function repeated_identical_import_is_idempotent_via_the_authoritative_user_link(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);
        $linkedUser = $this->createUser();
        $this->createMembership($linkedUser, $school);
        $row = ['full_name' => 'Asha Verma', 'user_id' => $linkedUser->id];

        $service = app(EmployeeImportService::class);
        $service->import($school, $actor, [$row]);
        $service->import($school, $actor, [$row]);
        $service->import($school, $actor, [$row]);

        $this->assertSame(1, $this->employeeCount($school));
    }

    #[Test]
    public function same_name_only_is_a_potential_duplicate_not_an_exact_one(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);

        $service = app(EmployeeImportService::class);
        $first = $service->import($school, $actor, [['full_name' => 'Jane Doe']]);
        $second = $service->import($school, $actor, [['full_name' => 'Jane Doe']]);

        $this->assertSame('created', $first->rows[0]->status);
        $this->assertSame('duplicate_potential', $second->rows[0]->status);
    }

    #[Test]
    public function two_legitimate_employees_with_the_same_name_are_not_globally_prohibited(): void
    {
        // Potential duplicate is a WARNING, never a database constraint --
        // an operator who reviews and re-submits deliberately (out of
        // scope for 8A.12's own resolution UI) is not blocked by this
        // service layer itself; this test proves no unique(school_id,
        // full_name) exists and the second Employee CAN be created
        // directly via the authoritative service outside the duplicate
        // gate, i.e. the domain genuinely supports the case.
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);

        $employeeA = app(EmployeeService::class)->create($school, ['full_name' => 'Jane Doe'], $actor);
        $employeeB = app(EmployeeService::class)->create($school, ['full_name' => 'Jane Doe'], $actor);

        $this->assertNotSame($employeeA->id, $employeeB->id);
        $this->assertSame(2, $this->employeeCount($school));
    }

    #[Test]
    public function normalized_case_and_whitespace_variants_are_treated_as_the_same_potential_candidate(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);
        $service = app(EmployeeImportService::class);

        $created = $service->import($school, $actor, [['full_name' => 'Jane Doe']]);
        $this->assertSame('created', $created->rows[0]->status);

        foreach (['  jane   doe  ', 'JANE DOE', 'jAnE dOe'] as $variant) {
            $result = $service->import($school, $actor, [['full_name' => $variant]]);
            $this->assertSame('duplicate_potential', $result->rows[0]->status, "Variant '{$variant}' must be recognized as a potential match.");
        }
    }

    #[Test]
    public function cross_school_same_name_does_not_match(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $actorA = $this->fullHrActor($schoolA);
        $actorB = $this->fullHrActor($schoolB);
        $service = app(EmployeeImportService::class);

        $service->import($schoolA, $actorA, [['full_name' => 'Jane Doe']]);
        $result = $service->import($schoolB, $actorB, [['full_name' => 'Jane Doe']]);

        $this->assertSame('created', $result->rows[0]->status, 'A different School must never see another School\'s Employee as a duplicate candidate.');
    }

    #[Test]
    public function cross_school_same_user_linkage_cannot_leak_as_a_duplicate(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $actorB = $this->fullHrActor($schoolB);
        $user = $this->createUser();
        $this->createMembership($user, $schoolA);
        $this->createMembership($user, $schoolB);

        app(EmployeeService::class)->create($schoolA, ['full_name' => 'Asha Verma', 'user_id' => $user->id], $this->fullHrActor($schoolA));

        // The same User, linked as an Employee in School A, must not
        // be reported as a duplicate when imported fresh in School B --
        // employees.user_id is unique PER SCHOOL (8A.1), and a User may
        // legitimately be an Employee at more than one School.
        $result = app(EmployeeImportService::class)->import($schoolB, $actorB, [['full_name' => 'Asha Verma', 'user_id' => $user->id]]);

        $this->assertSame('created', $result->rows[0]->status);
    }

    #[Test]
    public function duplicate_rows_within_the_same_batch_are_deterministic(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);
        $linkedUser = $this->createUser();
        $this->createMembership($linkedUser, $school);

        $result = app(EmployeeImportService::class)->import($school, $actor, [
            ['full_name' => 'Asha Verma', 'user_id' => $linkedUser->id],
            ['full_name' => 'Asha Verma', 'user_id' => $linkedUser->id],
        ]);

        $this->assertSame('created', $result->rows[0]->status);
        $this->assertSame('duplicate_exact', $result->rows[1]->status);
        $this->assertSame(1, $this->employeeCount($school));
    }

    #[Test]
    public function duplicate_potential_rows_within_the_same_batch_are_deterministic(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);

        $result = app(EmployeeImportService::class)->import($school, $actor, [
            ['full_name' => 'Jane Doe'],
            ['full_name' => 'Jane Doe'],
        ]);

        $this->assertSame('created', $result->rows[0]->status);
        $this->assertSame('duplicate_potential', $result->rows[1]->status, 'The second identical-name row in the same batch must see the first as a candidate.');
    }

    private function employeeCount(School $school): int
    {
        return app(TenantContext::class)->withSchool(
            $school,
            fn () => Employee::query()->where('school_id', $school->id)->count(),
        );
    }
}

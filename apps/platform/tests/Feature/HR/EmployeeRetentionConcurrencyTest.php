<?php

namespace Tests\Feature\HR;

use App\Domain\HR\Infrastructure\Employee;
use App\Models\School;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Concerns\ForcesConcurrentOverlap;
use Tests\TestCase;

/**
 * E21.2E (E21-D9): Employee retention racing a rehire, in two real OS
 * processes with an observed lock wait. Each purge locks the Employee row
 * FOR UPDATE (the lock EmploymentService::create() takes for every hire)
 * and resolves the separation AFTER the lock. An EmploymentRecord insert
 * takes FOR KEY SHARE on its Employee, so they serialize:
 * - a rehire committed first keeps everything;
 * - an evidence purge committed first makes the late rehire fail safely on
 *   its foreign key.
 */
class EmployeeRetentionConcurrencyTest extends TestCase
{
    use CreatesTenancyFixtures, ForcesConcurrentOverlap;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    /** @var list<string> */
    private array $schoolIds = [];

    protected function tearDown(): void
    {
        foreach ($this->schoolIds as $schoolId) {
            $this->deleteSchoolAsAdmin($schoolId);
        }

        parent::tearDown();
    }

    private function script(string ...$args): array
    {
        return ['php', __DIR__.'/../../Support/employee-retention-op.php', ...$args];
    }

    /** @return array{0: School, 1: Employee} a School and an Employee who separated in 2020, with an address */
    private function leaver(): array
    {
        $school = $this->createSchool();
        $this->schoolIds[] = $school->id;
        $employee = $this->createEmployee($school);
        $this->createEmploymentRecord($employee, ['status' => 'separated', 'starts_on' => '2015-01-01', 'ends_on' => '2020-06-30']);
        $this->createEmployeeAddress($employee);

        return [$school, $employee];
    }

    private function admin(string $table, string $column, string $id): int
    {
        return DB::connection('pgsql_admin')->table($table)->where($column, $id)->count();
    }

    #[Test]
    public function a_rehire_committed_first_keeps_the_employee(): void
    {
        [$school, $employee] = $this->leaver();

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->script('rehire', $school->id, $employee->id),
            $this->script('evidence-prune', $school->id, '2060-01-01'),
        );

        $this->assertSame('rehired', $holder);
        $this->assertSame('deleted:0', $contender);
        $this->assertSame(1, $this->admin('employees', 'id', $employee->id));
        $this->assertSame(2, $this->admin('employment_records', 'employee_id', $employee->id));
    }

    #[Test]
    public function an_evidence_purge_committed_first_refuses_the_late_rehire(): void
    {
        [$school, $employee] = $this->leaver();

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->script('evidence-prune', $school->id, '2060-01-01'),
            $this->script('rehire', $school->id, $employee->id),
        );

        $this->assertSame('deleted:1', $holder);
        $this->assertStringStartsWith('rejected:', $contender);
        $this->assertSame(0, $this->admin('employees', 'id', $employee->id));
        $this->assertSame(0, $this->admin('employment_records', 'employee_id', $employee->id));
    }

    #[Test]
    public function a_rehire_committed_first_keeps_the_ancillary_details(): void
    {
        [$school, $employee] = $this->leaver();

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->script('rehire', $school->id, $employee->id),
            $this->script('ancillary-prune', $school->id, '2060-01-01'),
        );

        $this->assertSame('rehired', $holder);
        $this->assertSame('deleted:0', $contender);
        $this->assertSame(1, $this->admin('employee_addresses', 'employee_id', $employee->id));
    }
}

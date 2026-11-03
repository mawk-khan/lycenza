<?php

namespace Tests\Feature\HR\Profile;

use App\Domain\HR\Infrastructure\EmployeeReferenceCode;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Concerns\ForcesConcurrentOverlap;
use Tests\TestCase;

/**
 * HR.2A: the staff-profile races, two real processes, the second proven
 * blocked on the first's uncommitted write (ForcesConcurrentOverlap):
 *
 *  1. the same reference code recorded for two Employees at once -- the
 *     School-unique key decides, never a check-then-insert;
 *  2. two personal-detail saves from forms opened on the same version --
 *     the second waits on the Employee lock, then sees the newer version.
 *
 * Committed fixtures; cleaned up through deleteSchoolAsAdmin().
 */
class StaffProfileConcurrencyTest extends TestCase
{
    use CreatesTenancyFixtures, ForcesConcurrentOverlap;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    private ?School $school = null;

    protected function tearDown(): void
    {
        $this->deleteSchoolAsAdmin($this->school);

        parent::tearDown();
    }

    /** @return array{0: string, 1: string} */
    private function race(array $holder, array $contender): array
    {
        $script = __DIR__.'/../../../Support/hr-profile-op.php';

        return $this->raceWithHeldHolder(['php', $script, $this->school->id, ...$holder], ['php', $script, $this->school->id, ...$contender]);
    }

    #[Test]
    public function the_same_reference_code_for_two_employees_is_recorded_once(): void
    {
        $this->school = $this->createSchool();
        [$a, $b] = [$this->createEmployee($this->school), $this->createEmployee($this->school)];
        $actor = $this->fullHrActor($this->school);

        [$held, $contender] = $this->race(['record-code', $a->id, 'erp', 'RACE-1', $actor->id], ['record-code', $b->id, 'erp', 'RACE-1', $actor->id]);

        $this->assertStringStartsWith('ok:', $held);
        $this->assertSame('rejected:EmployeeReferenceCodeConflictException:value_taken', $contender);
        $this->assertSame([$a->id], app(TenantContext::class)->withSchool($this->school, fn () => EmployeeReferenceCode::query()->where('value', 'RACE-1')->pluck('employee_id')->all()));
    }

    #[Test]
    public function two_saves_of_the_same_personal_form_never_both_land(): void
    {
        $this->school = $this->createSchool();
        $employee = $this->createEmployee($this->school);
        $actor = $this->fullHrActor($this->school);
        $version = $this->employeePersonalVersion($employee->id);

        [$held, $contender] = $this->race(['personal', $employee->id, 'First', $version, $actor->id], ['personal', $employee->id, 'Second', $version, $actor->id]);

        $this->assertStringStartsWith('ok:', $held);
        $this->assertSame('rejected:EmployeeProfileChangedException', $contender, 'the stale second form is refused, never last-save-wins');
        $this->assertSame('First', app(TenantContext::class)->withSchool($this->school, fn () => DB::table('employee_personal_details')->where('employee_id', $employee->id)->value('nationality')));
    }
}

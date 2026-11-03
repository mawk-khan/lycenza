<?php

namespace Tests\Feature\HR\Workforce;

use App\Domain\HR\Infrastructure\EmployeeAssignment;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Concerns\ForcesConcurrentOverlap;
use Tests\TestCase;

/**
 * HR.1A: the database backstops hold under REAL concurrency -- two separate
 * OS processes, RAW writes (no service lock), the second proven blocked on
 * the first's uncommitted write before it commits
 * (Tests\Concerns\ForcesConcurrentOverlap). Without the trigger's advisory
 * lock both would commit, each having seen no overlap.
 *
 * Committed fixtures (no DatabaseTransactions): the subprocesses are other
 * sessions. Cleaned up through deleteSchoolAsAdmin().
 */
class WorkforceConcurrencyTest extends TestCase
{
    use CreatesTenancyFixtures, ForcesConcurrentOverlap;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    private ?School $school = null;

    protected function tearDown(): void
    {
        if ($this->school !== null) {
            $this->deleteSchoolAsAdmin($this->school);
        }

        parent::tearDown();
    }

    /** @return array{0: string, 1: string} */
    private function race(array $holder, array $contender): array
    {
        $script = __DIR__.'/../../../Support/hr-workforce-raw-write.php';

        return $this->raceWithHeldHolder(['php', $script, $this->school->id, ...$holder], ['php', $script, $this->school->id, ...$contender]);
    }

    #[Test]
    public function two_concurrent_overlapping_employments_of_one_employee_commit_exactly_once(): void
    {
        $this->school = $this->createSchool();
        $employee = $this->createEmployee($this->school);

        [$held, $contender] = $this->race(['employment', $employee->id, '2026-04-01'], ['employment', $employee->id, '2026-09-01']);

        $this->assertStringStartsWith('ok:', $held);
        $this->assertSame('rejected:23P01', $contender);
        $this->assertSame(1, app(TenantContext::class)->withSchool($this->school, fn () => EmploymentRecord::query()->where('employee_id', $employee->id)->count()));
    }

    #[Test]
    public function two_concurrent_overlapping_primary_assignments_commit_exactly_once(): void
    {
        $this->school = $this->createSchool();
        $employment = $this->createEmploymentRecord($this->createEmployee($this->school), ['starts_on' => '2026-01-01']);
        $position = $this->createPosition($this->school);

        // Different start dates, so the older open-primary unique index alone would not serialize them.
        [$held, $contender] = $this->race(['primary', $employment->id, $position->id, '2026-04-01'], ['primary', $employment->id, $position->id, '2026-10-01']);

        $this->assertStringStartsWith('ok:', $held);
        $this->assertSame('rejected:23P01', $contender);
        $this->assertSame(1, app(TenantContext::class)->withSchool($this->school, fn () => EmployeeAssignment::query()->where('employment_record_id', $employment->id)->where('is_primary', true)->count()));
    }

    #[Test]
    public function two_concurrent_opposite_department_reparents_never_create_a_cycle(): void
    {
        $this->school = $this->createSchool();
        $a = $this->createDepartment($this->school);
        $b = $this->createDepartment($this->school);

        [$held, $contender] = $this->race(['reparent', $a->id, $b->id], ['reparent', $b->id, $a->id]);

        $this->assertSame('ok:1', $held);
        $this->assertSame('rejected:23514', $contender);
        $parents = app(TenantContext::class)->withSchool($this->school, fn () => DB::table('hr_departments')->whereIn('id', [$a->id, $b->id])->pluck('parent_department_id', 'id')->all());
        $this->assertSame($b->id, $parents[$a->id]);
        $this->assertNull($parents[$b->id]);
    }
}

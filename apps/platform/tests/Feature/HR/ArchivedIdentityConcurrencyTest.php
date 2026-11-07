<?php

namespace Tests\Feature\HR;

use App\Domain\HR\Application\EmployeeService;
use App\Domain\HR\Infrastructure\Employee;
use App\Models\School;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CommitsRetentionFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Concerns\ForcesConcurrentOverlap;
use Tests\TestCase;

/**
 * HR.R1-C9 (HRC-12): archive, restore, rehire and retention in real separate
 * sessions, the holder held open and the contender observed blocked. Archive and
 * restore take FOR NO KEY UPDATE, a service rehire FOR UPDATE, the database guard
 * FOR SHARE, and a purge FOR UPDATE, so each pair serializes on the Employee row
 * and the waiter answers from the committed state.
 */
class ArchivedIdentityConcurrencyTest extends TestCase
{
    use CommitsRetentionFixtures, CreatesTenancyFixtures, ForcesConcurrentOverlap;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    /** @var list<string> */
    private array $schoolIds = [];

    private School $school;

    private User $hr;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = $this->createSchool();
        $this->schoolIds[] = $this->school->id;
        $this->hr = $this->fullHrActor($this->school);
        $this->employee = $this->createEmployee($this->school);
        $this->createEmploymentRecord($this->employee, ['status' => 'separated', 'starts_on' => '2015-01-01', 'ends_on' => '2020-06-30']);
    }

    protected function tearDown(): void
    {
        foreach ($this->schoolIds as $schoolId) {
            $this->deleteSchoolAsAdmin($schoolId);
        }
        parent::tearDown();
    }

    /** @return list<string> */
    private function op(string $operation): array
    {
        return ['php', __DIR__.'/../../Support/archived-identity-op.php', $operation, $this->school->id, $this->employee->id, $this->hr->id];
    }

    /** @return list<string> */
    private function prune(string ...$args): array
    {
        return ['php', __DIR__.'/../../Support/employee-retention-op.php', ...$args];
    }

    private function archive(): void
    {
        app(EmployeeService::class)->archive($this->employee, $this->hr);
    }

    private function employments(): int
    {
        return DB::connection('pgsql_admin')->table('employment_records')->where('employee_id', $this->employee->id)->count();
    }

    private function recordStatus(): ?string
    {
        return DB::connection('pgsql_admin')->table('employees')->where('id', $this->employee->id)->value('record_status');
    }

    private function audits(string $action): int
    {
        return DB::connection('pgsql_admin')->table('school_audit_events')->where('school_id', $this->school->id)->where('event_type', $action)->where('subject_id', $this->employee->id)->count();
    }

    #[Test]
    public function a_rehire_waiting_on_a_restoration_is_admitted_once_it_commits(): void
    {
        $this->archive();

        [$holder, $contender] = $this->raceWithHeldHolder($this->op('restore'), $this->op('rehire'));

        $this->assertSame(['ok:active', 'ok:active'], [$holder, $contender]);
        $this->assertSame(2, $this->employments());
    }

    #[Test]
    public function a_rehire_waiting_on_an_archive_is_refused_once_it_commits(): void
    {
        [$holder, $contender] = $this->raceWithHeldHolder($this->op('archive'), $this->op('rehire'));

        $this->assertSame(['ok:archived', 'rejected:employee_archived'], [$holder, $contender]);
        $this->assertSame(1, $this->employments());
    }

    #[Test]
    public function the_database_guard_waits_for_an_archive_in_flight_on_its_own(): void
    {
        [$holder, $contender] = $this->raceWithHeldHolder($this->op('archive'), $this->op('raw-employment'));

        $this->assertSame(['ok:archived', 'rejected:23514'], [$holder, $contender]);
        $this->assertSame(1, $this->employments());
    }

    #[Test]
    public function a_duplicate_restoration_records_one_event(): void
    {
        $this->archive();

        [$holder, $contender] = $this->raceWithHeldHolder($this->op('restore'), $this->op('restore'));

        $this->assertSame(['ok:active', 'ok:active'], [$holder, $contender]);
        $this->assertSame(1, $this->audits('employee.restored'));
    }

    #[Test]
    public function an_archive_waiting_on_a_rehire_leaves_the_committed_employment_alone(): void
    {
        [$holder, $contender] = $this->raceWithHeldHolder($this->op('rehire'), $this->op('archive'));

        $this->assertSame(['ok:active', 'ok:archived'], [$holder, $contender]);
        $this->assertSame(2, $this->employments());
    }

    #[Test]
    public function a_restoration_waiting_on_a_purge_finds_nothing_and_rebuilds_nothing(): void
    {
        $this->archive();
        $this->backdateEndRecording();

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->prune('evidence-prune', $this->school->id, '2060-01-01'),
            $this->op('restore'),
        );

        $this->assertSame('deleted:1', $holder);
        $this->assertSame('rejected:'.ModelNotFoundException::class, $contender);
        $this->assertNull($this->recordStatus());
    }

    #[Test]
    public function a_restoration_is_no_retention_override(): void
    {
        $this->archive();
        $this->backdateEndRecording();

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->op('restore'),
            $this->prune('evidence-prune', $this->school->id, '2020-07-01'),
        );

        // The cutoff is the day after the last separation, so a restoration that moved the clock would keep the Employee.
        $this->assertSame(['ok:active', 'deleted:1'], [$holder, $contender], 'the clock runs from the last separation, and an identity restored but not rehired stays eligible');
        $this->assertNull($this->recordStatus());
    }

    #[Test]
    public function a_primary_change_waiting_on_the_end_that_cancels_the_placement_is_refused(): void
    {
        $employment = $this->createEmploymentRecord($this->createEmployee($this->school), ['status' => 'active', 'starts_on' => '2024-01-01', 'ends_on' => null]);
        $planned = $this->createEmployeeAssignment($employment, $this->createPosition($this->school), ['starts_on' => '2040-01-01', 'ends_on' => null, 'is_primary' => false]);

        [$holder, $contender] = $this->raceWithHeldHolder(
            ['php', __DIR__.'/../../Support/hr-lifecycle-op.php', $this->school->id, 'end', $employment->id, '2030-01-01', $this->hr->id],
            ['php', __DIR__.'/../../Support/archived-identity-op.php', 'placement-primary', $this->school->id, $planned->id, '1'],
        );

        $this->assertStringStartsWith('ok', $holder);
        $this->assertSame('rejected:23514', $contender);
        $row = DB::connection('pgsql_admin')->table('employee_assignments')->where('id', $planned->id)->first();
        $this->assertFalse((bool) $row->is_primary);
        $this->assertSame('2039-12-31', substr((string) $row->ends_on, 0, 10), 'the plan stays the record of a plan');
    }
}

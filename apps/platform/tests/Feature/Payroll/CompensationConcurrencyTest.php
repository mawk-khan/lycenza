<?php

namespace Tests\Feature\Payroll;

use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\Payroll\Application\SalaryStructureService;
use App\Domain\Payroll\Infrastructure\EmployeeCompensationAssignment;
use App\Domain\Payroll\Infrastructure\SalaryStructure;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Symfony\Component\Uid\UuidV7;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Concerns\ForcesConcurrentOverlap;
use Tests\TestCase;

/**
 * Phase 9.2 -- REQUIRED real-concurrency proofs (ADR 0034 "Mandatory
 * overlap guarantees"): two GENUINELY separate OS processes -- not
 * sequential calls in one PHP process -- race
 * SalaryStructureService::activate() and CompensationService::assign()
 * against real PostgreSQL, mirroring
 * AcademicYearActivationConcurrencyTest's/PrimaryAssignmentConcurrencyTest's
 * exact pattern.
 *
 * Deliberately does NOT use DatabaseTransactions for the fixtures this
 * test creates (see $connectionsToTransact) -- the subprocesses are
 * separate PostgreSQL sessions and can never see this test process's
 * uncommitted rows (confirmed directly while building Checkpoint 9.1's
 * own test coverage).
 */
class CompensationConcurrencyTest extends TestCase
{
    use CreatesTenancyFixtures, ForcesConcurrentOverlap;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    private ?School $school = null;

    protected function tearDown(): void
    {
        if ($this->school !== null) {
            $this->deleteSchoolAsAdmin($this->school); // cascades everything Payroll/HR this test creates
        }

        parent::tearDown();
    }

    private function makeActiveStructure(School $school, User $actor, string $code): SalaryStructure
    {
        $context = app(TenantContext::class);
        $service = app(SalaryStructureService::class);

        $structure = $context->withSchool($school, fn () => SalaryStructure::factory()->for($school, 'school')->create([
            'code' => $code, 'version' => 1, 'status' => 'draft',
        ]));

        return $context->withSchool($school, fn () => $service->activate($structure, $actor));
    }

    private function makeEmploymentRecord(School $school): EmploymentRecord
    {
        $context = app(TenantContext::class);

        return $context->withSchool($school, function () use ($school) {
            $employee = Employee::factory()->for($school, 'school')->create();

            return EmploymentRecord::factory()->create([
                'school_id' => $school->id,
                'employee_id' => $employee->id,
            ]);
        });
    }

    #[Test]
    public function scenario_a_two_real_processes_activating_competing_revisions_leave_exactly_one_active(): void
    {
        $this->school = $this->createSchool();
        $actor = $this->createUser();
        $context = app(TenantContext::class);

        $v1 = $context->withSchool($this->school, fn () => SalaryStructure::factory()->for($this->school, 'school')->create([
            'code' => 'GRADE-CONC', 'version' => 1, 'status' => 'draft',
        ]));
        $v2 = $context->withSchool($this->school, fn () => SalaryStructure::factory()->for($this->school, 'school')->create([
            'code' => 'GRADE-CONC', 'version' => 2, 'status' => 'draft',
        ]));

        // Forced, verified overlap (Tests\Concerns\ForcesConcurrentOverlap).
        // A merely SEQUENTIAL second activation legitimately supersedes
        // the first, so overlap must be proven, never assumed.
        $script = __DIR__.'/../../Support/activate-salary-structure.php';
        $outputs = $this->raceWithHeldHolder(
            ['php', $script, $this->school->id, $v1->id, $actor->id],
            ['php', $script, $this->school->id, $v2->id, $actor->id],
        );
        $activatedCount = count(array_filter($outputs, fn ($o) => $o === 'activated'));

        $this->assertSame('activated', $outputs[0], 'The first (held) activation must succeed.');
        $this->assertSame(1, $activatedCount, 'Exactly one of the two concurrent activations must succeed.');
        $this->assertTrue(
            in_array('rejected:App\\Domain\\Payroll\\Application\\Exceptions\\ConcurrentStructureActivationConflictException', $outputs, true),
            'The loser must receive a domain-specific concurrency exception, got: '.implode(', ', $outputs),
        );

        $activeCount = $context->withSchool(
            $this->school,
            fn () => SalaryStructure::query()->where('school_id', $this->school->id)->where('code', 'GRADE-CONC')->where('status', 'active')->count(),
        );
        $this->assertSame(1, $activeCount, 'The database must contain exactly one active revision for this code.');

        $noIntermediateState = $context->withSchool(
            $this->school,
            fn () => SalaryStructure::query()->where('school_id', $this->school->id)->where('code', 'GRADE-CONC')->pluck('status')->sort()->values()->all(),
        );
        $this->assertSame(['active', 'draft'], $noIntermediateState, 'The loser must remain draft -- never a half-applied intermediate state.');
    }

    #[Test]
    public function scenario_b_two_real_processes_assigning_overlapping_compensation_leave_no_overlap(): void
    {
        $this->school = $this->createSchool();
        $actor = $this->createUser();
        $structure = $this->makeActiveStructure($this->school, $actor, 'GRADE-OVERLAP');
        $employmentRecord = $this->makeEmploymentRecord($this->school);

        $script = __DIR__.'/../../Support/assign-compensation.php';
        $processA = new Process(['php', $script, $this->school->id, $employmentRecord->id, $structure->id, '2026-01-01', $actor->id]);
        $processB = new Process(['php', $script, $this->school->id, $employmentRecord->id, $structure->id, '2026-01-01', $actor->id]);
        $processA->start();
        $processB->start();
        $processA->wait();
        $processB->wait();

        $outputs = [$processA->getOutput(), $processB->getOutput()];
        $assignedCount = count(array_filter($outputs, fn ($o) => $o === 'assigned'));

        $this->assertSame(1, $assignedCount, 'Exactly one of the two overlapping compensation assignments must succeed.');
        $this->assertTrue(
            in_array('rejected:App\\Domain\\Payroll\\Application\\Exceptions\\CompensationAssignmentOverlapException', $outputs, true),
            'The loser must receive CompensationAssignmentOverlapException, got: '.implode(', ', $outputs),
        );

        $context = app(TenantContext::class);
        $rows = $context->withSchool(
            $this->school,
            fn () => EmployeeCompensationAssignment::query()->where('employment_record_id', $employmentRecord->id)->get(['id', 'effective_from', 'effective_to']),
        );

        $this->assertCount(1, $rows, 'The database must contain exactly one assignment for this EmploymentRecord -- no overlap.');
    }

    #[Test]
    public function scenario_c_concurrent_assignments_for_different_employment_records_both_succeed_independently(): void
    {
        $this->school = $this->createSchool();
        $actor = $this->createUser();
        $structure = $this->makeActiveStructure($this->school, $actor, 'GRADE-INDEP');
        $er1 = $this->makeEmploymentRecord($this->school);
        $er2 = $this->makeEmploymentRecord($this->school);

        $script = __DIR__.'/../../Support/assign-compensation.php';
        $processA = new Process(['php', $script, $this->school->id, $er1->id, $structure->id, '2026-01-01', $actor->id]);
        $processB = new Process(['php', $script, $this->school->id, $er2->id, $structure->id, '2026-01-01', $actor->id]);
        $processA->start();
        $processB->start();
        $processA->wait();
        $processB->wait();

        $this->assertSame('assigned', $processA->getOutput(), 'A different EmploymentRecord must never be blocked by an unrelated concurrent assignment.');
        $this->assertSame('assigned', $processB->getOutput(), 'A different EmploymentRecord must never be blocked by an unrelated concurrent assignment.');
    }

    #[Test]
    public function scenario_d_a_raw_sql_bypass_is_rejected_by_postgresql_itself(): void
    {
        $this->school = $this->createSchool();
        $actor = $this->createUser();
        $structure = $this->makeActiveStructure($this->school, $actor, 'GRADE-RAWBYPASS');
        $employmentRecord = $this->makeEmploymentRecord($this->school);

        DB::connection('pgsql_admin')->table('employee_compensation_assignments')->insert([
            'id' => (string) new UuidV7,
            'school_id' => $this->school->id,
            'employment_record_id' => $employmentRecord->id,
            'salary_structure_id' => $structure->id,
            'effective_from' => '2026-01-01',
            'effective_to' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/overlaps existing assignment/');

        DB::connection('pgsql_admin')->table('employee_compensation_assignments')->insert([
            'id' => (string) new UuidV7,
            'school_id' => $this->school->id,
            'employment_record_id' => $employmentRecord->id,
            'salary_structure_id' => $structure->id,
            'effective_from' => '2026-06-01',
            'effective_to' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

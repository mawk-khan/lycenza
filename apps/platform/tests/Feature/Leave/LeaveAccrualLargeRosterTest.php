<?php

namespace Tests\Feature\Leave;

use App\Domain\HR\Application\Workforce\StaffType;
use App\Domain\Leave\Application\Accrual\LeaveAccrualService;
use App\Domain\Leave\Application\LeaveEligibilityService;
use App\Domain\Leave\Application\LeaveLedgerService;
use App\Domain\Leave\Application\LeavePolicyService;
use App\Domain\Leave\Application\Policy\LeaveEligibilityCriteria;
use App\Domain\Leave\Infrastructure\LeaveAccrualRun;
use App\Domain\Leave\Infrastructure\LeaveLedgerEntry;
use App\Models\School;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Feature\Leave\Concerns\CreatesLeaveFixtures;
use Tests\TestCase;

/**
 * HR.10 (ADR 0065 §29.6): a School LARGER than HR.9's former 2,000-row
 * roster cap. Every employment is examined, none twice, by HR.9's
 * eligibility (applicability and run) and by HR.10's accrual run; the run
 * survives an interrupted request and a failing batch, resumes from its last
 * committed batch, and its disjoint outcome counts reconcile exactly.
 */
class LeaveAccrualLargeRosterTest extends TestCase
{
    use CreatesLeaveFixtures;

    private const TEACHERS = 2050;

    private const OTHERS = 10;

    #[Test]
    public function every_employment_of_a_large_school_is_examined_once_and_a_failed_run_resumes(): void
    {
        $this->travelTo('2026-10-05 06:00:00');
        $school = $this->createSchool();
        $admin = $this->leaveAdmin($school);
        $type = $this->leaveType($school, $admin);
        $year = $this->leaveYear($school, $admin);
        app(LeavePolicyService::class)->create($school, $type->id, [
            'name' => 'Monthly teaching', 'annual_allocation_units' => 0, 'carry_forward_allowed' => false, 'carry_forward_cap_units' => null,
            'carry_forward_expiry_days' => null, 'entitlement_method' => 'monthly', 'monthly_accrual_units' => 3, 'accrual_timing' => 'period_start',
        ], null, $admin, [new LeaveEligibilityCriteria(StaffType::TEACHING, null, null)]);
        $this->bulkEmployments($school, self::TEACHERS, 'teaching', 0);
        $this->bulkEmployments($school, self::OTHERS, 'outsourced', self::TEACHERS);

        // HR.9 on the complete roster: every employment counted, every eligible one assigned.
        $eligibility = app(LeaveEligibilityService::class);
        $applicability = $eligibility->applicability($school, $type->id, '2026-04-01', $admin, perPage: 10);
        $this->assertSame(self::TEACHERS, $applicability['counts']['eligible'], 'no 2,000-row truncation');
        $this->assertSame(self::OTHERS, $applicability['counts']['no_matching_rule']);
        $this->assertCount(10, $applicability['rows'], 'the rows are a server page');
        $this->assertSame(self::TEACHERS, $eligibility->executeRun($school, $type->id, '2026-04-01', $admin)->assigned_count);

        // HR.10: an interrupted request (three batches, then nothing more) ...
        $accruals = app(LeaveAccrualService::class);
        $run = $accruals->start($school, $type->id, $year->id, '2026-04-01', $admin);
        foreach (range(1, 3) as $ignored) {
            $run = $accruals->processBatch($school, $run->id, $admin);
        }
        $this->assertSame(['processing', 3 * LeaveAccrualService::BATCH_SIZE], [$run->status, $this->items($school, $run->id)]);

        // ... then a batch that fails: rolled back alone, the run marked failed with a code ...
        $this->app->instance(LeaveLedgerService::class, $this->failingLedger());
        try {
            app(LeaveAccrualService::class)->execute($school, $type->id, $year->id, '2026-04-01', $admin);
            $this->fail('the failing batch must surface');
        } catch (RuntimeException) {
        }
        $failed = $this->inSchool($school, fn () => LeaveAccrualRun::query()->findOrFail($run->id));
        $this->assertSame(['failed', 'LEAVE_ACCRUAL_BATCH_FAILED', 3], [$failed->status, $failed->failure_code, $failed->batch_count]);
        $this->assertSame(3 * LeaveAccrualService::BATCH_SIZE, $this->items($school, $run->id), 'the failed batch left nothing behind');

        // ... and the next request resumes the SAME run from its cursor to the end.
        $this->app->forgetInstance(LeaveLedgerService::class);
        $done = app(LeaveAccrualService::class)->execute($school, $type->id, $year->id, '2026-04-01', $admin);
        $this->assertSame([$run->id, 'completed'], [$done->id, $done->status]);
        $show = $accruals->show($school, $run->id, $admin);
        $this->assertSame(self::TEACHERS + self::OTHERS, $show['examined']);
        $this->assertSame($show['examined'], array_sum($show['counts']), 'examined = the disjoint outcomes');
        $this->assertSame([self::TEACHERS, self::OTHERS], [$show['counts']['credited'], $show['counts']['no_policy']]);
        $this->assertSame(self::TEACHERS + self::OTHERS, $this->distinctItems($school, $run->id), 'no employment examined twice');
        $ledger = $this->inSchool($school, fn () => DB::table('leave_ledger_entries')->where('kind', 'accrual')
            ->selectRaw('count(*) as n, count(distinct employment_record_id) as e, sum(units) as u')->first());
        $this->assertSame([self::TEACHERS, self::TEACHERS, self::TEACHERS * 3], [(int) $ledger->n, (int) $ledger->e, (int) $ledger->u], 'every eligible employment credited exactly once');

        // A re-run of the month is a NEW run that credits nothing.
        $again = $accruals->execute($school, $type->id, $year->id, '2026-04-01', $admin);
        $this->assertNotSame($run->id, $again->id);
        $this->assertSame(self::TEACHERS, $accruals->show($school, $again->id, $admin)['counts']['already_credited']);
        $this->assertSame(self::TEACHERS, $this->inSchool($school, fn () => LeaveLedgerEntry::query()->where('kind', 'accrual')->count()));
    }

    private function bulkEmployments(School $school, int $count, string $staffType, int $offset): void
    {
        $this->inSchool($school, function () use ($school, $count, $staffType, $offset) {
            DB::statement("INSERT INTO employees (id, school_id, employee_number, full_name, record_status, created_at, updated_at)
                SELECT gen_random_uuid(), ?, 'BULK-' || lpad((? + g)::text, 6, '0'), 'Bulk ' || (? + g), 'active', now(), now() FROM generate_series(1, ?) g",
                [$school->id, $offset, $offset, $count]);
            DB::statement("INSERT INTO employment_records (id, school_id, employee_id, employment_type, staff_type, starts_on, status, created_at, updated_at)
                SELECT gen_random_uuid(), e.school_id, e.id, 'permanent', ?, DATE '2024-01-01', 'active', now(), now()
                  FROM employees e WHERE e.school_id = ? AND e.employee_number LIKE 'BULK-%'
                   AND NOT EXISTS (SELECT 1 FROM employment_records r WHERE r.employee_id = e.id)",
                [$staffType, $school->id]);
        });
    }

    private function items(School $school, string $runId): int
    {
        return $this->inSchool($school, fn () => DB::table('leave_accrual_run_items')->where('accrual_run_id', $runId)->count());
    }

    private function distinctItems(School $school, string $runId): int
    {
        return (int) $this->inSchool($school, fn () => DB::table('leave_accrual_run_items')->where('accrual_run_id', $runId)->distinct()->count('employment_record_id'));
    }

    /** The real ledger writer, except that its first accrual of this batch throws (a crash mid-batch). */
    private function failingLedger(): LeaveLedgerService
    {
        return new class(...$this->ledgerDependencies()) extends LeaveLedgerService
        {
            public function recordAccrual(School $school, string $employmentRecordId, string $leaveTypeId, string $leaveYearId, string $assignmentId, string $periodStart, int $units, string $runId, User $actor): LeaveLedgerEntry
            {
                throw new RuntimeException('simulated worker failure');
            }
        };
    }

    /** @return list<object> */
    private function ledgerDependencies(): array
    {
        $constructor = (new \ReflectionClass(LeaveLedgerService::class))->getConstructor();

        return array_map(fn (\ReflectionParameter $p) => app((string) $p->getType()), $constructor?->getParameters() ?? []);
    }
}

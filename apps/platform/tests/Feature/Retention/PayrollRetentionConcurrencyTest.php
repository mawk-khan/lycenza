<?php

namespace Tests\Feature\Retention;

use App\Domain\Finance\Application\Retention\FinanceRetentionService;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\Payroll\Application\Retention\PayrollEvidenceRetentionService;
use App\Domain\Payroll\Infrastructure\PayrollRun;
use App\Models\School;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ForcesConcurrentOverlap;
use Tests\Feature\Retention\Concerns\CreatesPayrollRetentionFixtures;
use Tests\TestCase;

/**
 * E21.3F: payroll evidence expiry racing the writes that matter, in two real
 * OS processes with an observed lock wait (COMMITTED fixtures).
 * - vs a rehire: the unit locks the Employee and its EmploymentRecords FOR
 *   UPDATE; a new EmploymentRecord takes FOR KEY SHARE on the Employee. A
 *   rehire first keeps the evidence; expiry first makes the late rehire
 *   wait, then succeed (HR's contract: a rehire is always allowed; the
 *   expired evidence stays expired).
 * - vs a reversal and a correction: the unit locks every run holding the
 *   evidence; a reversal locks the run, a correction run's insert takes FOR
 *   KEY SHARE on it, and a correction delta on the EmploymentRecord. A
 *   reversal or correction first is new evidence and keeps everything;
 *   expiry first (with the emptied run's release) makes the late one fail
 *   on the vanished run.
 * - vs Finance: a run release in flight still shows its postings to
 *   Finance's snapshot, so Finance keeps the entries (no lock is involved:
 *   Finance never locks an entry a payroll posting claims); once the
 *   release commits, the next Finance run removes them. No foreign key
 *   ever breaks.
 * A closed financial period is never reopened (ADR 0064), so payroll
 * expiry has no race with a period close: it never reads or writes a
 * period, and Finance's own close race is FinanceRetentionConcurrencyTest's.
 */
class PayrollRetentionConcurrencyTest extends TestCase
{
    use CreatesPayrollRetentionFixtures, ForcesConcurrentOverlap;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    /** @var list<School> */
    private array $schools = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->enablePayrollRetention();
        $this->enableFinanceRetention();
    }

    protected function tearDown(): void
    {
        DB::purge('pgsql_race');
        $this->purgeCommittedSchools($this->schools);

        parent::tearDown();
    }

    /** @return array{school: School, setup: array, employee: Employee, run: PayrollRun} a separated Employee in a posted 2015 run */
    private function world(): array
    {
        $school = $this->createSchool();
        $this->schools[] = $school;
        $setup = $this->payrollSetup($school);
        $employee = $this->paidEmployee($school, $setup);
        $run = $this->postedRun($school, '2015-08-01', '2015-08-31');
        $this->separate($employee, '2015-12-31');

        return compact('school', 'setup', 'employee', 'run');
    }

    private function script(string ...$args): array
    {
        return ['php', __DIR__.'/../../Support/payroll-retention-op.php', ...$args];
    }

    private function admin(string $table, string $column, string $id): int
    {
        return DB::connection('pgsql_admin')->table($table)->where($column, $id)->count();
    }

    #[Test]
    public function a_rehire_committed_first_keeps_the_payroll_evidence(): void
    {
        $w = $this->world();

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->script('rehire', $w['school']->id, $w['employee']->id),
            $this->script('payroll-prune', $w['school']->id),
        );

        $this->assertSame('rehired', $holder);
        $this->assertSame('deleted:0/0 errors:0', $contender);
        $this->assertSame(1, $this->admin('payroll_run_results', 'employee_id', $w['employee']->id));
    }

    #[Test]
    public function an_expiry_committed_first_makes_the_late_rehire_wait_and_the_evidence_stays_expired(): void
    {
        $w = $this->world();

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->script('payroll-prune', $w['school']->id),
            $this->script('rehire', $w['school']->id, $w['employee']->id),
        );

        $this->assertSame('deleted:1/1 errors:0', $holder);
        $this->assertSame('rehired', $contender);
        $this->assertSame(0, $this->admin('payroll_run_results', 'employee_id', $w['employee']->id));
        $this->assertSame(1, DB::connection('pgsql_admin')->table('employment_records')->where('employee_id', $w['employee']->id)->where('status', 'active')->count());
    }

    #[Test]
    public function a_reversal_committed_first_is_new_evidence_and_keeps_everything(): void
    {
        $w = $this->world();

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->script('reverse', $w['school']->id, $w['run']->id, $this->createUser()->id),
            $this->script('payroll-prune', $w['school']->id),
        );

        $this->assertSame('reversed', $holder);
        $this->assertSame('deleted:0/0 errors:0', $contender);
        $this->assertSame(1, $this->admin('payroll_run_results', 'employee_id', $w['employee']->id));
        $this->assertSame(2, $this->admin('payroll_run_postings', 'payroll_run_id', $w['run']->id));
    }

    #[Test]
    public function an_expiry_committed_first_makes_the_late_reversal_fail_on_the_released_run(): void
    {
        $w = $this->world();

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->script('payroll-prune', $w['school']->id),
            $this->script('reverse', $w['school']->id, $w['run']->id, $this->createUser()->id),
        );

        $this->assertSame('deleted:1/1 errors:0', $holder);
        $this->assertStringStartsWith('rejected:', $contender);
        $this->assertSame(0, $this->admin('payroll_runs', 'id', $w['run']->id));
    }

    #[Test]
    public function a_correction_committed_first_is_new_evidence_and_keeps_everything(): void
    {
        $w = $this->world();
        $record = (string) DB::connection('pgsql_admin')->table('employment_records')->where('employee_id', $w['employee']->id)->value('id');
        $component = (string) DB::connection('pgsql_admin')->table('salary_components')->where('school_id', $w['school']->id)->value('id');

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->script('correct', $w['school']->id, $w['run']->id, $record, $component, $w['setup']['actor']->id),
            $this->script('payroll-prune', $w['school']->id),
        );

        $this->assertSame('corrected', $holder);
        $this->assertSame('deleted:0/0 errors:0', $contender);
        $this->assertSame(1, $this->admin('payroll_run_results', 'employee_id', $w['employee']->id));
        $this->assertSame(1, $this->admin('payroll_adjustments', 'employment_record_id', $record), 'the late correction delta is never deleted');
    }

    #[Test]
    public function an_expiry_committed_first_makes_the_late_correction_fail_on_the_released_run(): void
    {
        $w = $this->world();
        $record = (string) DB::connection('pgsql_admin')->table('employment_records')->where('employee_id', $w['employee']->id)->value('id');
        $component = (string) DB::connection('pgsql_admin')->table('salary_components')->where('school_id', $w['school']->id)->value('id');

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->script('payroll-prune', $w['school']->id),
            $this->script('correct', $w['school']->id, $w['run']->id, $record, $component, $w['setup']['actor']->id),
        );

        $this->assertSame('deleted:1/1 errors:0', $holder);
        $this->assertStringStartsWith('rejected:', $contender);
        $this->assertSame(0, DB::connection('pgsql_admin')->table('payroll_runs')->where('corrects_payroll_run_id', $w['run']->id)->count());
    }

    #[Test]
    public function finance_never_takes_an_entry_while_its_release_is_in_flight_and_takes_it_on_the_next_run(): void
    {
        $w = $this->world();
        $this->closeOldYear($w['school']);
        $entries = $this->runEntries($w['school'], $w['run']->id);
        $cutoff = CarbonImmutable::now($w['school']->timezone)->subYearsNoOverflow(8)->startOfDay();
        app(PayrollEvidenceRetentionService::class)->pruneEvidence($w['school'], $cutoff->toDateString(), 100, false);
        $golden = $this->balances($w['school']);

        // The release, uncommitted on a second runtime connection.
        $race = $this->race($w['school']);
        $this->assertSame(1, (int) $race->selectOne('SELECT retention_expire_payroll_run(?, ?, ?, false) AS n', [$w['school']->id, $w['run']->id, $cutoff->utc()->format('Y-m-d H:i:s')])->n);

        $finance = app(FinanceRetentionService::class)->prune($w['school'], 100, false, 8);
        $this->assertSame([0, 1, 0], [$finance['deleted'], $finance['dependency_blocked'], $finance['errors']], 'its snapshot still shows the payroll posting');
        $this->assertSame(1, $this->admin('journal_entries', 'id', $entries[0]));

        $race->commit();
        $finance = app(FinanceRetentionService::class)->prune($w['school'], 100, false, 8);
        $this->assertSame([1, 0, 0], [$finance['deleted'], $finance['dependency_blocked'], $finance['errors']]);
        $this->assertSame(0, $this->admin('journal_entries', 'id', $entries[0]));
        $this->assertSame($golden, $this->balances($w['school']));
    }

    /** A second, independent runtime connection, in the School's context, with an open transaction. */
    private function race(School $school): Connection
    {
        Config::set('database.connections.pgsql_race', config('database.connections.pgsql'));
        $race = DB::connection('pgsql_race');
        $race->beginTransaction();
        $race->select("select set_config('app.current_school_id', ?, true)", [$school->id]);

        return $race;
    }
}

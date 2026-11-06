<?php

namespace Tests\Feature\Postgres;

use App\Domain\HR\Application\AccountLinks\EmployeeAccountLinkHistory;
use App\Domain\HR\Application\EmployeeService;
use App\Domain\HR\Infrastructure\Employee;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantRls;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * HR.R1-C7 (OD-HR-25), as the real runtime role, with positive controls: raw SQL can
 * change the current link, which leaves history, and can never touch the history.
 */
class EmployeeAccountLinkRawRoleTest extends TestCase
{
    use CreatesTenancyFixtures;

    /** @var array<int, string> committed, so the admin connection sees what the runtime role wrote */
    protected $connectionsToTransact = [];

    private School $school;

    private School $other;

    private Employee $employee;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = $this->createSchool();
        $this->other = $this->createSchool();
        $this->employee = $this->createEmployee($this->school, ['user_id' => null]);
        $this->user = $this->createUser();
        $this->createMembership($this->user, $this->school);
    }

    protected function tearDown(): void
    {
        $this->purgeSchoolsAsAdmin([$this->school->id, $this->other->id]);
        parent::tearDown();
    }

    private function in(School $school, callable $fn): mixed
    {
        return app(TenantContext::class)->withSchool($school, $fn);
    }

    private function rawLink(): string
    {
        $this->in($this->school, fn () => DB::statement('UPDATE employees SET user_id = ? WHERE id = ?', [$this->user->id, $this->employee->id]));

        return (string) DB::connection('pgsql_admin')->table('employee_account_links')->where('employee_id', $this->employee->id)->value('id');
    }

    #[Test]
    public function the_runtime_role_is_the_real_one_and_a_raw_link_leaves_history_it_cannot_avoid(): void
    {
        $this->assertSame('school_os_app', DB::selectOne('select current_user as u')->u);

        $linkId = $this->rawLink();

        $row = DB::connection('pgsql_admin')->table('employee_account_links')->where('id', $linkId)->first();
        $this->assertSame($this->user->id, $row->user_id);
        $this->assertNull($row->linked_by_user_id, 'a write outside the service records no actor, and stays recorded');

        $this->in($this->school, fn () => DB::statement('UPDATE employees SET user_id = NULL WHERE id = ?', [$this->employee->id]));
        $this->assertNotNull(DB::connection('pgsql_admin')->table('employee_account_links')->where('id', $linkId)->value('ended_at'), 'nor can it end one silently');
    }

    /** @return array<string, array{0: string}> */
    public static function forgeries(): array
    {
        return [
            'insert' => ["INSERT INTO employee_account_links (id, school_id, employee_id, user_id, origin) VALUES (gen_random_uuid(), '%school%', '%employee%', '%user%', 'recorded')"],
            'update' => ["UPDATE employee_account_links SET voided_at = now(), void_reason = 'wrong_person' WHERE id = '%link%'"],
            'reassign' => ["UPDATE employee_account_links SET user_id = '%user%' WHERE id = '%link%'"],
            'delete' => ["DELETE FROM employee_account_links WHERE id = '%link%'"],
            'truncate' => ['TRUNCATE employee_account_links'],
        ];
    }

    #[Test]
    #[DataProvider('forgeries')]
    public function the_runtime_role_can_never_forge_or_remove_a_row(string $sql): void
    {
        $linkId = $this->rawLink();
        $statement = str_replace(['%school%', '%employee%', '%user%', '%link%'], [$this->school->id, $this->employee->id, $this->user->id, $linkId], $sql);

        try {
            $this->in($this->school, fn () => DB::transaction(fn () => DB::statement($statement)));
            $this->fail('the runtime role wrote the history');
        } catch (QueryException $e) {
            $this->assertStringContainsString('permission denied', $e->getMessage());
        }
        $this->assertSame(1, DB::connection('pgsql_admin')->table('employee_account_links')->where('employee_id', $this->employee->id)->whereNull('voided_at')->count());
    }

    #[Test]
    public function the_runtime_role_cannot_delete_an_employee_and_the_history_with_it(): void
    {
        $this->rawLink();

        $this->expectExceptionMessage('permission denied');
        $this->in($this->school, fn () => DB::statement('DELETE FROM employees WHERE id = ?', [$this->employee->id]));
    }

    #[Test]
    public function the_trigger_function_cannot_be_called_or_disabled_by_the_runtime_role(): void
    {
        $this->assertFalse((bool) DB::connection('pgsql_admin')->selectOne("select has_function_privilege('school_os_app', 'employees_record_account_link()', 'EXECUTE') as x")->x);

        $this->expectExceptionMessage('must be owner');
        DB::statement('ALTER TABLE employees DISABLE TRIGGER trg_employees_record_account_link_update');
    }

    #[Test]
    public function the_correction_function_only_acts_in_the_session_school(): void
    {
        $linkId = $this->rawLink();
        $this->in($this->school, fn () => DB::statement('UPDATE employees SET user_id = NULL WHERE id = ?', [$this->employee->id]));

        $this->expectExceptionMessage('not this School');
        $this->in($this->other, fn () => DB::select('SELECT employee_account_link_void(?, ?, ?, ?)', [$this->school->id, $linkId, 'wrong_person', $this->user->id]));
    }

    #[Test]
    public function the_table_is_forced_rls_and_school_a_never_reads_school_b(): void
    {
        $row = DB::connection('pgsql_admin')->selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'employee_account_links' and relnamespace = 'public'::regnamespace");
        $this->assertTrue($row->relrowsecurity);
        $this->assertTrue($row->relforcerowsecurity);

        $linkId = $this->rawLink();

        $this->assertSame(1, (int) $this->in($this->school, fn () => DB::selectOne('select count(*) as c from employee_account_links where id = ?', [$linkId])->c), 'positive control');
        $this->assertSame(0, (int) $this->in($this->other, fn () => DB::selectOne('select count(*) as c from employee_account_links where id = ?', [$linkId])->c));

        DB::statement('RESET '.TenantRls::SESSION_VAR);
        $this->assertSame(0, (int) DB::selectOne('select count(*) as c from employee_account_links')->c, 'no context, no rows');
    }

    #[Test]
    public function an_interval_can_never_run_backwards_and_two_rows_can_never_be_open(): void
    {
        $linkId = $this->rawLink();
        $admin = DB::connection('pgsql_admin');

        try {
            $admin->transaction(fn () => $admin->table('employee_account_links')->where('id', $linkId)->update(['ended_at' => '2000-01-01 00:00:00']));
            $this->fail('a link ended before it began');
        } catch (QueryException $e) {
            $this->assertStringContainsString('employee_account_links_ended_check', $e->getMessage());
        }

        $this->expectExceptionMessage('employee_account_links_one_open_per_employee');
        $admin->table('employee_account_links')->insert(['id' => (string) Str::uuid(), 'school_id' => $this->school->id, 'employee_id' => $this->employee->id, 'user_id' => $this->createUser()->id, 'origin' => 'recorded']);
    }

    #[Test]
    public function a_history_row_can_never_name_an_employee_of_another_school(): void
    {
        $this->expectExceptionMessage('employee_account_links_employee_fk');
        DB::connection('pgsql_admin')->table('employee_account_links')->insert(['id' => (string) Str::uuid(), 'school_id' => $this->other->id, 'employee_id' => $this->employee->id, 'user_id' => $this->user->id, 'origin' => 'recorded']);
    }

    #[Test]
    public function a_duplicate_link_of_one_account_is_refused_by_the_existing_unique_key(): void
    {
        $this->rawLink();
        $second = $this->createEmployee($this->school, ['user_id' => null]);

        $this->expectExceptionMessage('employees_school_id_user_id_unique');
        $this->in($this->school, fn () => DB::statement('UPDATE employees SET user_id = ? WHERE id = ?', [$this->user->id, $second->id]));
    }

    #[Test]
    public function a_cutover_link_never_reads_as_observed_from_its_start(): void
    {
        $linkId = $this->rawLink();
        DB::connection('pgsql_admin')->table('employee_account_links')->where('id', $linkId)->update(['origin' => 'cutover']);
        $hr = $this->fullHrActor($this->school);

        $history = $this->in($this->school, fn () => app(EmployeeAccountLinkHistory::class)->forEmployee($this->employee->fresh(), $hr));

        $this->assertSame('cutover', $history[0]['evidence']);
    }

    #[Test]
    public function the_service_records_the_actor_only_for_its_own_transaction(): void
    {
        $hr = $this->fullHrActor($this->school);
        $this->in($this->school, fn () => app(EmployeeService::class)->linkUser($this->employee->fresh(), $this->user->id, $hr));
        $this->assertSame($hr->id, DB::connection('pgsql_admin')->table('employee_account_links')->where('employee_id', $this->employee->id)->value('linked_by_user_id'));
    }
}

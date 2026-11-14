<?php

namespace Tests\Feature\Postgres;

use App\Domain\HR\Application\EmploymentService;
use App\Domain\HR\Application\Separation\ClearanceResponsibility;
use App\Domain\HR\Application\Separation\SeparationCaseService;
use App\Domain\HR\Application\Separation\SeparationClearanceRequirementService;
use App\Domain\HR\Application\Separation\SeparationReason;
use App\Domain\WorkforceTasks\Application\TaskAssignmentService;
use App\Domain\WorkforceTasks\Application\TaskCatalogueService;
use App\Domain\WorkforceTasks\Application\TaskGenerationService;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantRls;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * HR.R1-C10 (R1-06): the ten HR tables no raw-role test named. Every statement here
 * runs on the `pgsql` connection as `school_os_app`, with no Eloquent and no
 * SchoolScope, so only the database stands between it and the rows. The rows were
 * written by the real services as that same role, which is the positive control for
 * INSERT. Each refusal asserts its cause: an RLS filter leaves zero rows only when a
 * same-School control proves the row exists, and a denial names RLS, the privilege
 * or the key that refused it.
 */
class HrSeparationAndTaskRawIsolationTest extends TestCase
{
    use CreatesTenancyFixtures;

    private const UPDATABLE = [
        'employment_separation_cases', 'separation_clearance_items', 'separation_clearance_requirements',
        'workforce_task_categories', 'workforce_task_generation_runs', 'workforce_task_templates', 'workforce_tasks',
    ];

    private const APPEND_ONLY = ['employment_separation_events', 'workforce_task_events', 'workforce_task_template_versions'];

    private School $a;

    private School $b;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-06-15 10:00:00');
        $this->a = $this->createSchool();
        $this->b = $this->createSchool();
        $this->world($this->a);
        $this->world($this->b);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Every one of the ten tables gets a row in the School, through the real services. */
    private function world(School $school): void
    {
        $hr = $this->createUserWithCapabilities($school, [
            'hr.employees.view', 'hr.employees.assignments.view', 'hr.employees.assignments.manage', 'hr.separations.view', 'hr.separations.manage',
            'hr.separations.configure', 'hr.tasks.view', 'hr.tasks.configure', 'hr.tasks.manage',
        ]);
        $approver = $this->createUserWithCapabilities($school, ['hr.separations.view', 'hr.separations.approve']);
        app(TenantContext::class)->withSchool($school, function () use ($school, $hr, $approver): void {
            $leaver = app(EmploymentService::class)->create($this->createEmployee($school), ['employment_type' => 'permanent', 'staff_type' => 'teaching', 'starts_on' => '2024-01-01'], $hr);
            app(SeparationClearanceRequirementService::class)->create($school, 'keys', 'Keys returned', ClearanceResponsibility::HR, true, null, $hr);
            $cases = app(SeparationCaseService::class);
            $case = $cases->record($school, $leaver->id, SeparationReason::RESIGNATION, '2026-06-15', '2026-07-31', $hr);
            $cases->approve($school, $case->id, $case->version, $approver, $this->freshMfaProofFor($approver));

            $tasks = app(TaskCatalogueService::class);
            $category = $tasks->createCategory($school, 'admin', 'Administrative', $hr);
            $tasks->createTemplate($school, 'daily', [
                'title' => 'Open the office', 'category_id' => $category->id, 'completion_mode' => 'self', 'frequency' => 'daily', 'weekdays' => 127, 'month_day' => null,
                'last_day' => false, 'year_month' => null, 'due_days' => 0, 'applies_teaching' => true, 'applies_non_teaching' => false, 'applies_outsourced' => false,
                'effective_from' => '2026-06-15',
            ], $hr);
            $staff = $this->createEmploymentRecord($this->createEmployee($school), ['starts_on' => '2024-01-01', 'status' => 'active', 'staff_type' => 'teaching']);
            $this->createEmployeeAssignment($staff, $this->createPosition($school), ['starts_on' => '2024-01-01', 'ends_on' => null, 'is_primary' => true]);
            app(TaskGenerationService::class)->run($school);
            app(TaskAssignmentService::class)->assign($school, $staff->id, [
                'title' => 'Prepare the notice board', 'category_id' => $category->id, 'completion_mode' => 'reviewed', 'available_on' => '2026-06-15', 'due_on' => '2026-06-20',
            ], $hr);
        });
    }

    /** @return array<string, array{0: string}> */
    public static function tables(): array
    {
        $names = [...self::UPDATABLE, ...self::APPEND_ONLY];

        return array_combine($names, array_map(fn (string $t) => [$t], $names));
    }

    private function as(?School $school, callable $work): mixed
    {
        if ($school === null) {
            DB::connection('pgsql')->statement('RESET '.TenantRls::SESSION_VAR);

            return $work();
        }

        return app(TenantContext::class)->withSchool($school, $work);
    }

    private function rowCount(string $table): int
    {
        return (int) DB::connection('pgsql')->selectOne("select count(*) as c from {$table}")->c;
    }

    private function firstRow(string $table, School $school): array
    {
        return (array) $this->as($school, fn () => DB::connection('pgsql')->table($table)->first());
    }

    /** Runs $write in a savepoint and returns the failure, or null. */
    private function failure(callable $write): ?QueryException
    {
        try {
            DB::connection('pgsql')->transaction($write);

            return null;
        } catch (QueryException $e) {
            return $e;
        }
    }

    #[Test]
    #[DataProvider('tables')]
    public function the_table_is_tenant_owned_and_forced_with_one_policy_on_its_school_key(string $table): void
    {
        $row = DB::connection('pgsql')->selectOne(
            "select c.relrowsecurity as rls, c.relforcerowsecurity as forced,
                    (select count(*) from pg_policies p where p.schemaname = 'public' and p.tablename = c.relname and p.cmd = 'ALL' and p.qual like '%school_id%' and p.with_check like '%school_id%') as policies,
                    (select count(*) from information_schema.columns k where k.table_schema = 'public' and k.table_name = c.relname and k.column_name = 'school_id' and k.is_nullable = 'NO') as school_key
               from pg_class c where c.relname = ? and c.relnamespace = 'public'::regnamespace", [$table]);

        $this->assertTrue($row->rls);
        $this->assertTrue($row->forced);
        $this->assertSame(1, (int) $row->policies);
        $this->assertSame(1, (int) $row->school_key);
    }

    #[Test]
    #[DataProvider('tables')]
    public function each_school_reads_only_its_own_rows_and_no_context_reads_nothing(string $table): void
    {
        $this->assertSame('school_os_app', DB::connection('pgsql')->selectOne('select current_user as u')->u);
        $this->assertFalse((bool) DB::connection('pgsql')->selectOne('select rolbypassrls as b from pg_roles where rolname = current_user')->b);

        $own = $this->as($this->a, fn () => DB::connection('pgsql')->table($table)->pluck('school_id')->unique()->values()->all());
        $this->assertSame([$this->a->id], $own, 'a positive control: School A sees rows, and only its own');
        $other = $this->as($this->b, fn () => DB::connection('pgsql')->table($table)->pluck('school_id')->unique()->values()->all());
        $this->assertSame([$this->b->id], $other);

        $this->assertSame(0, $this->as(null, fn () => $this->rowCount($table)), 'no context fails closed');
    }

    #[Test]
    #[DataProvider('tables')]
    public function a_forged_id_of_another_school_finds_nothing(string $table): void
    {
        $row = $this->firstRow($table, $this->a);

        $this->assertSame(1, $this->as($this->a, fn () => DB::connection('pgsql')->table($table)->where('id', $row['id'])->count()));
        $this->assertSame(0, $this->as($this->b, fn () => DB::connection('pgsql')->table($table)->where('id', $row['id'])->count()));
    }

    #[Test]
    #[DataProvider('tables')]
    public function the_runtime_role_can_never_delete(string $table): void
    {
        $row = $this->firstRow($table, $this->a);

        $this->assertFalse((bool) DB::connection('pgsql')->selectOne("select has_table_privilege(current_user, ?, 'DELETE') as p", ["public.{$table}"])->p);
        $e = $this->failure(fn () => $this->as($this->a, fn () => DB::connection('pgsql')->table($table)->where('id', $row['id'])->delete()));

        $this->assertSame('42501', (string) $e?->getCode());
        $this->assertStringContainsString('permission denied', (string) $e?->getMessage());
        $this->assertSame(1, $this->as($this->a, fn () => DB::connection('pgsql')->table($table)->where('id', $row['id'])->count()));
    }

    /** A case can only be born `requested`, so a copied row is put back to that, or the guard answers before the key does. */
    private function asNewRow(string $table, array $row): array
    {
        if ($table === 'employment_separation_cases') {
            // HR.R1-C35 WO-21: and without the clearance basis an approval records.
            $row = ['status' => 'requested', 'version' => 1, 'decided_by_user_id' => null, 'decided_at' => null,
                'clearance_staff_type' => null, 'clearance_skipped_requirement_ids' => null] + $row;
        }

        return ['id' => (string) Str::uuid7()] + $row;
    }

    private function assertForeignRowRefused(string $table, QueryException $e, string $expected): void
    {
        $this->assertStringContainsString($expected, (string) $e->getCode().' '.$e->getMessage(), "{$table}: refused for another reason");
    }

    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public static function clearanceReferences(): array
    {
        return [
            'to its case' => ['case_id', 'employment_separation_cases', 'separation_clearance_items_case_id_school_id_foreign'],
            'to its requirement' => ['requirement_id', 'separation_clearance_requirements', 'exact snapshot of an active requirement'],
        ];
    }

    #[Test]
    #[DataProvider('clearanceReferences')]
    public function a_clearance_item_of_another_schools_parent_is_refused_with_the_key_behind_it(string $column, string $parent, string $cause): void
    {
        // The guard reads the requirement as this School, so for that column it answers first and the key cannot be
        // reached by a statement. Each key is the structural backstop, so it is also asserted from the catalogue.
        $theirs = $this->firstRow('separation_clearance_items', $this->a);
        $row = $this->asNewRow('separation_clearance_items', $this->firstRow('separation_clearance_items', $this->b));
        $row[$column] = $theirs[$column];

        $e = $this->failure(fn () => $this->as($this->b, fn () => DB::connection('pgsql')->table('separation_clearance_items')->insert($row)));

        $this->assertStringContainsString($cause, (string) $e?->getMessage());
        $key = DB::connection('pgsql')->selectOne("select pg_get_constraintdef(oid) as d from pg_constraint where conrelid = 'separation_clearance_items'::regclass and contype = 'f' and confrelid = ?::regclass", [$parent]);
        $this->assertStringContainsString("({$column}, school_id)", (string) $key?->d);
    }

    #[Test]
    #[DataProvider('tables')]
    public function a_row_for_another_school_cannot_be_inserted(string $table): void
    {
        $row = $this->asNewRow($table, $this->firstRow($table, $this->a));

        // In School B's context, a row claiming School A.
        $e = $this->failure(fn () => $this->as($this->b, fn () => DB::connection('pgsql')->table($table)->insert($row)));

        $this->assertNotNull($e, 'the database accepted a row for another School');
        $this->assertSame(0, $this->as($this->a, fn () => DB::connection('pgsql')->table($table)->where('id', $row['id'])->count()));
        // The clearance guard reads the case and requirement as School B, so a School A row is no such snapshot.
        $this->assertForeignRowRefused($table, $e, $table === 'separation_clearance_items' ? 'exact snapshot of an active requirement' : 'row-level security');
    }

    /** @return array<string, array{0: string}> */
    public static function updatable(): array
    {
        return array_combine(self::UPDATABLE, array_map(fn (string $t) => [$t], self::UPDATABLE));
    }

    #[Test]
    #[DataProvider('updatable')]
    public function an_update_from_another_school_touches_nothing(string $table): void
    {
        $row = $this->firstRow($table, $this->a);

        $touched = $this->as($this->b, fn () => DB::connection('pgsql')->table($table)->where('id', $row['id'])->update(['updated_at' => '2020-01-01 00:00:00']));
        $this->assertSame(0, $touched);
        $this->assertSame((array) $row, $this->firstRow($table, $this->a), 'nothing changed');
    }

    #[Test]
    #[DataProvider('updatable')]
    public function a_row_cannot_be_moved_to_another_school(string $table): void
    {
        $row = $this->firstRow($table, $this->a);

        $e = $this->failure(fn () => $this->as($this->a, fn () => DB::connection('pgsql')->table($table)->where('id', $row['id'])->update(['school_id' => $this->b->id])));

        $this->assertNotNull($e, 'the database moved a row between Schools');
        // RLS, unless the table's own immutability guard answers first, as it does for these four.
        $this->assertMatchesRegularExpression('/row-level security|terms are immutable|immutable \\(hr_|never edited/', $e->getMessage(), "{$table}: refused for another reason");
        $this->assertSame($this->a->id, $this->as($this->a, fn () => DB::connection('pgsql')->table($table)->where('id', $row['id'])->value('school_id')));
    }

    /** @return array<string, array{0: string}> */
    public static function appendOnly(): array
    {
        return array_combine(self::APPEND_ONLY, array_map(fn (string $t) => [$t], self::APPEND_ONLY));
    }

    #[Test]
    #[DataProvider('appendOnly')]
    public function history_cannot_be_rewritten_by_the_runtime_role(string $table): void
    {
        $row = $this->firstRow($table, $this->a);

        $this->assertFalse((bool) DB::connection('pgsql')->selectOne("select has_table_privilege(current_user, ?, 'UPDATE') as p", ["public.{$table}"])->p);
        $e = $this->failure(fn () => $this->as($this->a, fn () => DB::connection('pgsql')->table($table)->where('id', $row['id'])->update(['school_id' => $this->a->id])));

        $this->assertSame('42501', (string) $e?->getCode());
        $this->assertStringContainsString('permission denied', (string) $e?->getMessage());
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function references(): array
    {
        return [
            'case to employment' => ['employment_separation_cases', 'employment_record_id'],
            'case event to case' => ['employment_separation_events', 'case_id'],
            'task to employment' => ['workforce_tasks', 'employment_record_id'],
            'task to category' => ['workforce_tasks', 'category_id'],
            'task to template' => ['workforce_tasks', 'template_id'],
            'task to template version' => ['workforce_tasks', 'template_version_id'],
            'task event to task' => ['workforce_task_events', 'task_id'],
            'template version to category' => ['workforce_task_template_versions', 'category_id'],
            'template version to template' => ['workforce_task_template_versions', 'template_id'],
        ];
    }

    #[Test]
    #[DataProvider('references')]
    public function a_row_of_this_school_cannot_point_at_another_schools_parent(string $table, string $column): void
    {
        $theirs = $this->firstRow($table, $this->a);
        $row = $this->asNewRow($table, $this->firstRow($table, $this->b));
        $row[$column] = $theirs[$column];
        if ($table === 'employment_separation_cases') {
            // An employment of School A with no case yet, so `one_live` is not what answers.
            $row[$column] = $this->as($this->a, fn () => DB::connection('pgsql')->table('workforce_tasks')->where('assignment_path', '<>', 'generated')->value('employment_record_id'));
        }
        // Clear of every unique index, so that the key under test is what answers.
        foreach (['version_no' => 100, 'event_no' => 100, 'sequence' => 100] as $key => $step) {
            if (array_key_exists($key, $row)) {
                $row[$key] += $step;
            }
        }
        foreach (['occurrence_on', 'effective_from'] as $key) {
            if (($row[$key] ?? null) !== null) {
                $row[$key] = '2030-01-01';
            }
        }

        $e = $this->failure(fn () => $this->as($this->b, fn () => DB::connection('pgsql')->table($table)->insert($row)));

        $this->assertNotNull($e, "{$table}.{$column} accepted another School's parent");
        $this->assertForeignRowRefused($table, $e, '23503');
        $this->assertSame(0, $this->as($this->b, fn () => DB::connection('pgsql')->table($table)->where('id', $row['id'])->count()));
    }
}

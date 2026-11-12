<?php

namespace Tests\Feature\Postgres;

use App\Support\Database\RollbackRefusedException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * HR.R1-C5 (PAY-15): the whole path on the shape production uses, not a superuser.
 * A real LOGIN role, NOSUPERUSER NOCREATEDB NOCREATEROLE with BYPASSRLS, owns a real
 * scratch database with the real schema, and the real migrations run as that role
 * over a real connection. A superuser connection only arranges rows and reads the
 * truth.
 *
 * It keeps three things true for every future migration: the whole schema installs
 * as that role, everything reachable rolls back and reapplies identically, and real
 * evidence in each domain survives a refusal with and without bypass.
 */
class RollbackProductionShapeTest extends TestCase
{
    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    private const ROLE = 'c5_production_shape';

    private const OWNER = 'pgsql_c5_owner';

    private const SUPER = 'pgsql_c5_super';

    /** The newest migration whose down() always refuses: a rollback stops here (ADR 0066 §15.1). */
    private const FENCE = '2026_12_01_090100_anchor_retention_eligibility_clocks';

    /**
     * Real guards over real tables: migration, table, a sentinel meeting the guard's own
     * condition, and the words of that guard's own refusal.
     *
     * @var array<string, array{0: string, 1: string, 2: array<string, string>, 3: string}>
     */
    private const EVIDENCE = [
        'payroll provenance' => ['2027_02_02_090000_record_payroll_compensation_sources', 'payroll_run_calculation_sources', [], 'payroll compensation provenance is recorded'],
        'payroll attestation' => ['2027_02_06_090000_create_payroll_posting_attestations', 'payroll_posting_attestations', [], 'posting attestations are recorded'],
        'payroll reconciliation' => ['2027_02_07_090000_create_payroll_reversal_reconciliations', 'payroll_reversal_reconciliations', [], 'reconciliation obligations are recorded'],
        'account link corrections' => ['2027_02_10_090100_require_two_people_to_correct_an_account_link', 'employee_account_link_corrections', [], 'account link correction requests are recorded'],
        'credential verification decisions' => ['2027_02_18_090000_bind_credential_verification_to_a_revision', 'employee_credential_verifications', [], 'credential verification decisions are recorded'],
        'account link history' => ['2027_02_08_090000_create_employee_account_links', 'employee_account_links', ['origin' => "'recorded'"], 'account link history is recorded'],
        'payroll calculations' => ['2027_01_01_090000_record_payroll_run_calculations_and_participant_approval', 'payroll_run_calculations', [], 'payroll_run_calculations holds'],
        'leave ledger' => ['2026_11_17_090000_create_leave_foundation', 'leave_ledger_entries', [], 'Leave holds Employee evidence'],
        'leave requests' => ['2026_11_19_090000_create_leave_requests_and_year_close', 'leave_requests', [], 'Leave holds request or year-close'],
        'employment history' => ['2027_02_03_090000_harden_employment_lifecycle_admission', 'employee_assignments', ['starts_on' => "'2026-02-02'", 'ends_on' => "'2026-02-01'"], 'never-effective assignments are recorded'],
        'staff attendance' => ['2026_11_20_090000_create_staff_attendance', 'staff_attendance_records', [], 'Staff Attendance holds Employee evidence'],
        'attendance correction' => ['2027_01_04_090100_create_attendance_record_corrections', 'attendance_record_corrections', [], 'attendance_record_corrections holds'],
        'student lifecycle' => ['2027_01_04_090000_create_student_lifecycle_events', 'student_lifecycle_events', [], 'student_lifecycle_events holds'],
        'payment correction' => ['2026_12_15_090000_create_payment_corrections', 'payment_corrections', [], 'correction records exist'],
        'charge cancellation' => ['2027_01_05_090100_record_charge_cancellation_assurance', 'charges', ['cancellation_reason_code' => "'c5'"], 'charges records ORG.8G cancellation'],
        'financial expiry' => ['2026_11_10_090000_add_finance_retention_expiry', 'financial_period_expiries', [], 'Finance detail has been expired'],
        'retention hold' => ['2026_11_27_090000_make_retention_holds_authoritative', 'retention_holds', ['released_at' => 'now()'], 'retention_holds holds'],
        'stock write-off' => ['2027_01_06_090000_add_issue_recipient_and_write_off_to_stock_movements', 'stock_movements', ['movement_type' => "'write_off'"], 'stock_movements holds write-offs'],
        'applicant document' => ['2027_01_21_090000_add_document_compliance', 'applicant_documents', [], 'Applicant or birth-certificate documents'],
    ];

    private string $database;

    protected function setUp(): void
    {
        parent::setUp();
        $this->database = 'school_os_test_c5_'.strtolower(Str::random(10));
        $password = bin2hex(random_bytes(16));
        $admin = DB::connection('pgsql_admin');
        $this->dropRole();
        $admin->statement('CREATE ROLE '.self::ROLE." LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE BYPASSRLS PASSWORD '{$password}'");
        $admin->statement("CREATE DATABASE {$this->database} OWNER ".self::ROLE);

        $base = array_merge(config('database.connections.pgsql_admin'), ['database' => $this->database, 'url' => null]);
        config([
            'database.connections.'.self::OWNER => array_merge($base, ['username' => self::ROLE, 'password' => $password]),
            'database.connections.'.self::SUPER => $base,
        ]);
        DB::purge(self::OWNER);
        DB::purge(self::SUPER);

        // What production-bootstrap.sql does for the migration role.
        $super = DB::connection(self::SUPER);
        $super->statement('GRANT USAGE ON SCHEMA public TO school_os_app, school_os_retention');
        $super->statement('ALTER DEFAULT PRIVILEGES FOR ROLE '.self::ROLE.' IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO school_os_app');
        $super->statement('ALTER DEFAULT PRIVILEGES FOR ROLE '.self::ROLE.' IN SCHEMA public GRANT USAGE, SELECT ON SEQUENCES TO school_os_app');
    }

    protected function tearDown(): void
    {
        DB::purge(self::OWNER);
        DB::purge(self::SUPER);
        $this->dropRole();
        parent::tearDown();
    }

    /** A killed run can leave a scratch database behind, which would keep this role from ever being dropped. */
    private function dropRole(): void
    {
        $admin = DB::connection('pgsql_admin');
        foreach ($admin->select('SELECT d.datname FROM pg_database d JOIN pg_roles r ON r.oid = d.datdba WHERE r.rolname = ?', [self::ROLE]) as $orphan) {
            $admin->statement("DROP DATABASE \"{$orphan->datname}\" WITH (FORCE)");
        }
        $admin->statement('DROP ROLE IF EXISTS '.self::ROLE);
    }

    private function bypass(bool $on): void
    {
        DB::connection('pgsql_admin')->statement('ALTER ROLE '.self::ROLE.($on ? ' BYPASSRLS' : ' NOBYPASSRLS'));
        DB::purge(self::OWNER);
    }

    /** @return array<string, list<string>> every object a rollback could lose, with what makes it safe */
    private function schema(): array
    {
        $sections = [
            'relations' => "SELECT c.relname, c.relkind, c.relrowsecurity, c.relforcerowsecurity, c.relowner::regrole::text AS owner, c.relacl::text AS acl
                FROM pg_class c WHERE c.relnamespace = 'public'::regnamespace AND c.relkind IN ('r', 'p', 'v', 'm', 'S')",
            'columns' => "SELECT table_name, column_name, data_type, is_nullable, column_default FROM information_schema.columns WHERE table_schema = 'public'",
            'constraints' => "SELECT c.conrelid::regclass::text AS relation, c.conname, c.convalidated, pg_get_constraintdef(c.oid) AS definition FROM pg_constraint c WHERE c.connamespace = 'public'::regnamespace",
            'indexes' => "SELECT tablename, indexname, indexdef FROM pg_indexes WHERE schemaname = 'public'",
            'triggers' => 'SELECT t.tgrelid::regclass::text AS relation, t.tgname, t.tgenabled, pg_get_triggerdef(t.oid) AS definition FROM pg_trigger t WHERE NOT t.tgisinternal',
            'policies' => "SELECT tablename, policyname, permissive, roles::text, cmd, qual, with_check FROM pg_policies WHERE schemaname = 'public'",
            'functions' => "SELECT p.proname, pg_get_function_identity_arguments(p.oid) AS arguments, p.prosecdef, p.proconfig::text AS config, p.proacl::text AS acl, pg_get_functiondef(p.oid) AS definition
                FROM pg_proc p WHERE p.pronamespace = 'public'::regnamespace AND p.prokind IN ('f', 'p')",
            'default_privileges' => 'SELECT defaclrole::regrole::text AS role, defaclobjtype, defaclacl::text AS acl FROM pg_default_acl',
        ];

        $schema = [];
        foreach ($sections as $name => $query) {
            $rows = array_map(fn (object $row) => (string) json_encode($row), DB::connection(self::SUPER)->select($query));
            sort($rows);
            $schema[$name] = $rows;
        }

        return $schema;
    }

    private function rollback(string $migration): void
    {
        Artisan::call('migrate:rollback', [
            '--database' => self::OWNER,
            '--path' => database_path("migrations/{$migration}.php"),
            '--realpath' => true,
            '--batch' => DB::connection(self::SUPER)->table('migrations')->where('migration', $migration)->value('batch'),
            '--force' => true,
        ]);
    }

    private function migrationCount(): int
    {
        return DB::connection(self::SUPER)->table('migrations')->count();
    }

    /** @return list<string> sentinels of two Schools; the superuser writes them, with FKs and triggers off */
    private function sentinels(string $table, array $values): array
    {
        $super = DB::connection(self::SUPER);
        $school = [(string) Str::uuid7(), (string) Str::uuid7()];
        $ids = [];
        $super->transaction(function () use ($super, $table, $values, $school, &$ids): void {
            $super->statement("SET LOCAL session_replication_role = 'replica'");
            // A sentinel needs to exist, not to be well formed. This database is thrown away.
            foreach ($super->select("SELECT conname FROM pg_constraint WHERE conrelid = ?::regclass AND contype = 'c'", ["public.{$table}"]) as $check) {
                $super->statement("ALTER TABLE {$table} DROP CONSTRAINT \"{$check->conname}\"");
            }
            foreach ($school as $id) {
                $ids[] = $super->selectOne($this->insert($table, $values, $id))->id;
            }
        });
        sort($ids);

        return $ids;
    }

    private function insert(string $table, array $values, string $school): string
    {
        $columns = DB::connection(self::SUPER)->select("SELECT column_name AS name, data_type AS type, is_nullable AS nullable, column_default AS fallback, character_maximum_length AS length
            FROM information_schema.columns WHERE table_schema = 'public' AND table_name = ? AND is_generated = 'NEVER' AND is_identity = 'NO' ORDER BY ordinal_position", [$table]);
        // HR.R1-C29: an identity column (a decision's order) is the database's to assign, never a sentinel's.

        $row = $values + ['id' => 'gen_random_uuid()', 'school_id' => "'{$school}'"];
        foreach ($columns as $column) {
            if (! isset($row[$column->name]) && $column->nullable === 'NO' && $column->fallback === null) {
                $row[$column->name] = match (true) {
                    $column->type === 'uuid' => 'gen_random_uuid()',
                    str_contains($column->type, 'char') || $column->type === 'text' => 'left(md5(random()::text), '.min($column->length ?? 24, 24).')',
                    str_contains($column->type, 'int') => '(floor(random() * 30000) + 1)::int',
                    $column->type === 'numeric' => '1',
                    $column->type === 'boolean' => 'false',
                    $column->type === 'date' => "'2026-01-01'",
                    str_starts_with($column->type, 'timestamp') => 'now()',
                    str_starts_with($column->type, 'json') => "'{}'",
                    $column->type === 'ARRAY' => "'{}'",
                    default => throw new RuntimeException("No sentinel value for {$table}.{$column->name} ({$column->type})."),
                };
            }
        }

        return "INSERT INTO {$table} (".implode(', ', array_keys($row)).') VALUES ('.implode(', ', $row).') RETURNING id';
    }

    /** @return list<string> */
    private function ids(string $table): array
    {
        return DB::connection(self::SUPER)->table($table)->orderBy('id')->pluck('id')->map(fn ($id) => (string) $id)->all();
    }

    #[Test]
    public function the_whole_path_holds_on_a_role_that_is_not_a_superuser(): void
    {
        // Fresh install, as that role.
        $this->assertSame(0, Artisan::call('migrate', ['--database' => self::OWNER, '--force' => true]), Artisan::output());
        $files = array_map(fn (string $file) => basename($file, '.php'), glob(database_path('migrations/*.php')) ?: []);
        $this->assertSame(count($files), $this->migrationCount());
        $super = DB::connection(self::SUPER);
        $this->assertSame(0, $super->table('pg_class')->whereRaw("relnamespace = 'public'::regnamespace AND relkind IN ('r', 'p') AND relowner <> ?::regrole", [self::ROLE])->count(), 'that role owns every table');
        $this->assertGreaterThan(0, $super->table('pg_class')->whereRaw("relnamespace = 'public'::regnamespace AND relforcerowsecurity")->count());
        $installed = $this->schema();

        // Everything reachable rolls back, the fence refuses and changes nothing, and it all reapplies identically.
        $reachable = count(array_filter($files, fn (string $file) => $file > self::FENCE));
        $this->assertSame(0, Artisan::call('migrate:rollback', ['--database' => self::OWNER, '--step' => $reachable, '--force' => true]), Artisan::output());
        $this->assertSame(self::FENCE, $super->table('migrations')->orderByDesc('migration')->value('migration'));
        $atTheFence = $this->schema();
        try {
            Artisan::call('migrate:rollback', ['--database' => self::OWNER, '--step' => 1, '--force' => true]);
            $this->fail('the retention fence rolled back');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('deliberately irreversible', $e->getMessage());
        }
        $this->assertSame($atTheFence, $this->schema());
        $this->assertSame(0, Artisan::call('migrate', ['--database' => self::OWNER, '--force' => true]), Artisan::output());
        $this->assertSame($installed, $this->schema(), 'schema, RLS, grants, triggers and functions all come back');

        // Real evidence in every domain survives a refusal, whichever role asks.
        $sentinels = [];
        foreach (self::EVIDENCE as $name => [, $table, $values]) {
            $sentinels[$name] = $this->sentinels($table, $values);
        }
        $seeded = $this->schema();

        foreach (self::EVIDENCE as $name => [$migration, $table, , $refusal]) {
            $this->bypass(false);
            $forced = (bool) DB::connection(self::SUPER)->selectOne('SELECT relforcerowsecurity AS f FROM pg_class WHERE oid = ?::regclass', ["public.{$table}"])->f;
            $this->assertSame(! $forced, DB::connection(self::OWNER)->table($table)->exists(), "{$name}: under FORCE RLS this role really is blind");
            try {
                $this->rollback($migration);
                $this->fail("{$name}: rolled back on rows the role could not see");
            } catch (RollbackRefusedException $e) {
                $this->assertStringContainsString('cannot see every row', $e->getMessage(), $name);
            }

            $this->bypass(true);
            $this->assertTrue(DB::connection(self::OWNER)->table($table)->exists(), "{$name}: with bypass it sees them");
            try {
                $this->rollback($migration);
                $this->fail("{$name}: its guard let evidence go");
            } catch (RuntimeException $e) {
                $this->assertSame(RuntimeException::class, $e::class, "{$name}: a guard's refusal, never a stray SQL error: {$e->getMessage()}");
                $this->assertStringContainsString($refusal, $e->getMessage(), "{$name}: that guard's own refusal");
            }

            foreach ($sentinels[$name] as $id) {
                $this->assertContains($id, $this->ids($table), "{$name}: the exact rows survive");
            }
        }

        $this->assertSame($seeded, $this->schema(), 'a refusal changes nothing, in any domain');
        $this->assertSame(count($files), $this->migrationCount(), 'and every migration stays applied');

        // An unguarded down() that drops the audit ledger: nothing reads it, so only the runner stands in the way.
        $audit = $this->sentinels('school_audit_events', []);
        $this->bypass(false);
        try {
            $this->rollback('2026_08_22_091200_create_school_audit_events_table');
            $this->fail('the audit ledger was dropped by a role that could not see it');
        } catch (RollbackRefusedException) {
        }
        $this->assertSame($audit, $this->ids('school_audit_events'));
    }
}

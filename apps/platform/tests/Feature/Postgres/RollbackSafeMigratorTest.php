<?php

namespace Tests\Feature\Postgres;

use App\Support\Database\RollbackRefusedException;
use App\Support\Database\RollbackSafeMigrator;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\Concerns\ForcesConcurrentOverlap;
use Tests\TestCase;

/**
 * HR.R1-C5 (PAY-15): a rollback guard can never mistake hidden rows for no rows.
 * A real LOGIN role owns real FORCE RLS tables, and the real `migrate:rollback`
 * runs the guard as that role, over a real connection. The superuser is used only
 * to arrange the world and to read the truth afterwards.
 */
class RollbackSafeMigratorTest extends TestCase
{
    use ForcesConcurrentOverlap;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    private const ROLE = 'c5_rollback_owner';

    private const CONNECTION = 'pgsql_c5';

    private const BATCH = 999_999;

    private string $directory;

    private string $name;

    protected function setUp(): void
    {
        parent::setUp();
        $password = bin2hex(random_bytes(16));
        $this->dropWorld();
        $admin = DB::connection('pgsql_admin');
        $admin->statement('CREATE ROLE '.self::ROLE." LOGIN NOSUPERUSER NOBYPASSRLS PASSWORD '{$password}'");
        $admin->statement('GRANT USAGE, CREATE ON SCHEMA public TO '.self::ROLE);
        $admin->statement('GRANT SELECT, INSERT, UPDATE, DELETE ON migrations TO '.self::ROLE);
        $admin->statement('GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO '.self::ROLE);
        foreach (['c5_guarded', 'c5_second'] as $table) {
            $admin->statement("CREATE TABLE {$table} (id uuid PRIMARY KEY, school_id uuid NOT NULL)");
            $admin->statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            $admin->statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
            $admin->statement("CREATE POLICY {$table}_tenant ON {$table} USING (school_id::text = current_setting('app.current_school_id', true))");
            $admin->statement("ALTER TABLE {$table} OWNER TO ".self::ROLE);
        }
        config(['database.connections.'.self::CONNECTION => array_merge(config('database.connections.pgsql_admin'), ['username' => self::ROLE, 'password' => $password, 'url' => null])]);
        DB::purge(self::CONNECTION);

        $this->directory = sys_get_temp_dir().'/c5_rollback_'.Str::random(10);
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        DB::purge(self::CONNECTION);
        $this->dropWorld();
        foreach (glob($this->directory.'/*') ?: [] as $file) {
            unlink($file);
        }
        @rmdir($this->directory);
        parent::tearDown();
    }

    private function dropWorld(): void
    {
        $admin = DB::connection('pgsql_admin');
        $admin->table('migrations')->where('migration', 'like', '2099_%')->delete();
        $admin->statement('DROP FUNCTION IF EXISTS c5_visible()');
        $admin->statement('DROP TABLE IF EXISTS c5_guarded, c5_second');
        // A killed run can leave the definer role of the security-definer test too.
        foreach ([self::ROLE, 'c5_definer'] as $role) {
            if ($admin->selectOne('select 1 as x from pg_roles where rolname = ?', [$role]) !== null) {
                $admin->statement("DROP OWNED BY {$role}");
                $admin->statement("DROP ROLE {$role}");
            }
        }
    }

    /** A fixture migration whose down() runs $body; recorded as the latest applied migration. */
    private function migration(string $body): void
    {
        $this->name = '2099_01_01_000000_c5_fixture_'.strtolower(Str::random(6));
        file_put_contents("{$this->directory}/{$this->name}.php", <<<PHP
            <?php
            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Support\Facades\DB;
            use Illuminate\Support\Facades\Schema;
            return new class extends Migration {
                public function up(): void {}
                public function down(): void {
                    {$body}
                }
            };
            PHP);
        DB::connection('pgsql_admin')->table('migrations')->insert(['migration' => $this->name, 'batch' => self::BATCH]);
    }

    private function guardedDrop(): string
    {
        return <<<'PHP'
            if (DB::table('c5_guarded')->exists()) {
                throw new RuntimeException('Refusing to roll back: evidence exists.');
            }
            Schema::drop('c5_guarded');
            PHP;
    }

    private function rollback(): void
    {
        Artisan::call('migrate:rollback', ['--database' => self::CONNECTION, '--path' => $this->directory, '--realpath' => true, '--force' => true]);
    }

    /** @return list<string> ids of the sentinels, read as the superuser */
    private function sentinels(string $table, string ...$schools): array
    {
        $admin = DB::connection('pgsql_admin');
        $ids = [];
        foreach ($schools as $school) {
            $admin->table($table)->insert(['id' => $ids[] = (string) Str::uuid7(), 'school_id' => $school]);
        }
        sort($ids);

        return $ids;
    }

    /** @return list<string> */
    private function rows(string $table): array
    {
        return DB::connection('pgsql_admin')->table($table)->orderBy('id')->pluck('id')->map(fn ($id) => (string) $id)->all();
    }

    private function assertWorldIntact(): void
    {
        $admin = DB::connection('pgsql_admin');
        $state = $admin->selectOne("select relrowsecurity as rls, relforcerowsecurity as forced from pg_class where oid = 'public.c5_guarded'::regclass");
        $this->assertTrue($state !== null && $state->rls && $state->forced, 'the table and its FORCE RLS survive');
        $this->assertSame(1, $admin->table('pg_policy')->where('polname', 'c5_guarded_tenant')->count());
        $this->assertSame(1, $admin->table('migrations')->where('migration', $this->name)->count(), 'still recorded as applied');
    }

    private function grantBypass(): void
    {
        DB::connection('pgsql_admin')->statement('ALTER ROLE '.self::ROLE.' BYPASSRLS');
        DB::purge(self::CONNECTION);
    }

    #[Test]
    public function every_framework_path_uses_the_fail_closed_migrator(): void
    {
        $this->assertInstanceOf(RollbackSafeMigrator::class, app('migrator'));
    }

    #[Test]
    public function a_plain_read_really_is_blind_here_which_is_what_this_all_guards_against(): void
    {
        $this->sentinels('c5_guarded', (string) Str::uuid7());

        $this->assertFalse(DB::connection(self::CONNECTION)->table('c5_guarded')->exists(), 'the owner sees nothing, with no error');
    }

    #[Test]
    public function hidden_rows_of_several_schools_are_refused_with_no_tenant_context_and_survive_exactly(): void
    {
        $ids = $this->sentinels('c5_guarded', (string) Str::uuid7(), (string) Str::uuid7());
        $this->migration($this->guardedDrop());

        try {
            $this->rollback();
            $this->fail('the rollback went ahead on rows it could not see');
        } catch (RollbackRefusedException $e) {
            $this->assertStringContainsString($this->name, $e->getMessage());
            $this->assertStringContainsString('cannot see every row', $e->getMessage());
            $this->assertStringNotContainsString($ids[0], $e->getMessage(), 'never a row');
        }
        $this->assertSame($ids, $this->rows('c5_guarded'));
        $this->assertWorldIntact();
    }

    #[Test]
    public function a_tenant_context_with_no_rows_of_its_own_proves_nothing_about_the_other_school(): void
    {
        $visible = (string) Str::uuid7();
        $ids = $this->sentinels('c5_guarded', (string) Str::uuid7());
        $this->migration("DB::select(\"select set_config('app.current_school_id', '{$visible}', true)\");\n".$this->guardedDrop());

        $this->expectException(RollbackRefusedException::class);
        try {
            $this->rollback();
        } finally {
            $this->assertSame($ids, $this->rows('c5_guarded'));
            $this->assertWorldIntact();
        }
    }

    #[Test]
    public function a_down_that_reads_nothing_is_refused_too_because_drop_is_never_filtered(): void
    {
        $ids = $this->sentinels('c5_guarded', (string) Str::uuid7());
        $this->migration("Schema::drop('c5_guarded');");

        $this->expectException(RollbackRefusedException::class);
        try {
            $this->rollback();
        } finally {
            $this->assertSame($ids, $this->rows('c5_guarded'));
            $this->assertWorldIntact();
        }
    }

    #[Test]
    public function a_read_through_a_security_definer_function_raises_and_is_never_an_empty_answer(): void
    {
        $admin = DB::connection('pgsql_admin');
        $this->grantBypass();
        $ids = $this->sentinels('c5_guarded', (string) Str::uuid7());
        // Owned by a role that cannot bypass: it filters, whoever calls it.
        $admin->statement('CREATE ROLE c5_definer NOLOGIN NOSUPERUSER NOBYPASSRLS');
        try {
            $admin->statement('GRANT USAGE, CREATE ON SCHEMA public TO c5_definer');
            $admin->statement('GRANT SELECT ON c5_guarded TO c5_definer');
            $admin->statement("CREATE FUNCTION c5_visible() RETURNS boolean LANGUAGE sql SECURITY DEFINER SET search_path = public AS 'SELECT EXISTS (SELECT 1 FROM c5_guarded)'");
            $admin->statement('ALTER FUNCTION c5_visible() OWNER TO c5_definer');
            $admin->statement('GRANT EXECUTE ON FUNCTION c5_visible() TO '.self::ROLE);
            $this->migration("if (DB::selectOne('select c5_visible() as v')->v) { throw new RuntimeException('Refusing'); } Schema::drop('c5_guarded');");

            try {
                $this->rollback();
                $this->fail('a filtered answer let the rollback through');
            } catch (RollbackRefusedException $e) {
                $this->assertStringContainsString('cannot see every row of c5_guarded', $e->getMessage());
            }
            $this->assertSame($ids, $this->rows('c5_guarded'));
        } finally {
            $admin->statement('DROP FUNCTION IF EXISTS c5_visible()');
            $admin->statement('REVOKE ALL ON c5_guarded FROM c5_definer');
            $admin->statement('REVOKE ALL ON SCHEMA public FROM c5_definer');
            $admin->statement('DROP ROLE c5_definer');
        }
    }

    #[Test]
    public function a_pretend_run_changes_nothing_and_needs_no_transaction(): void
    {
        $this->sentinels('c5_guarded', (string) Str::uuid7());
        $this->grantBypass();
        $this->migration("DB::statement('ALTER TABLE c5_guarded ADD COLUMN c5_pretend integer');");

        Artisan::call('migrate:rollback', ['--database' => self::CONNECTION, '--path' => $this->directory, '--realpath' => true, '--force' => true, '--pretend' => true]);

        $this->assertWorldIntact();
    }

    #[Test]
    public function a_genuinely_empty_table_is_still_refused_because_this_role_cannot_tell(): void
    {
        $this->migration($this->guardedDrop());

        $this->expectException(RollbackRefusedException::class);
        try {
            $this->rollback();
        } finally {
            $this->assertWorldIntact();
        }
    }

    #[Test]
    public function a_role_that_sees_every_row_gets_the_guards_own_refusal(): void
    {
        $this->grantBypass();
        $ids = $this->sentinels('c5_guarded', (string) Str::uuid7());
        $this->migration($this->guardedDrop());

        try {
            $this->rollback();
            $this->fail('the guard let evidence be dropped');
        } catch (RuntimeException $e) {
            $this->assertNotInstanceOf(RollbackRefusedException::class, $e);
            $this->assertStringContainsString('evidence exists', $e->getMessage());
        }
        $this->assertSame($ids, $this->rows('c5_guarded'));
        $this->assertWorldIntact();
    }

    #[Test]
    public function a_role_that_sees_every_row_rolls_an_empty_table_back(): void
    {
        $this->grantBypass();
        $this->migration($this->guardedDrop());

        $this->rollback();

        $this->assertNull(DB::connection('pgsql_admin')->selectOne("select to_regclass('public.c5_guarded') as t")->t);
        $this->assertSame(0, DB::connection('pgsql_admin')->table('migrations')->where('migration', $this->name)->count());
    }

    #[Test]
    public function evidence_in_a_second_table_stops_it_and_earlier_ddl_of_that_down_is_undone(): void
    {
        $this->grantBypass();
        $ids = $this->sentinels('c5_second', (string) Str::uuid7());
        $this->migration(<<<'PHP'
            DB::statement('ALTER TABLE c5_guarded ADD COLUMN c5_partial integer');
            if (DB::table('c5_guarded')->exists() || DB::table('c5_second')->exists()) {
                throw new RuntimeException('Refusing to roll back: evidence exists.');
            }
            Schema::drop('c5_guarded');
            PHP);

        try {
            $this->rollback();
            $this->fail('one empty table let the rollback through');
        } catch (RuntimeException) {
            $this->assertSame(0, DB::connection('pgsql_admin')->table('information_schema.columns')->where('table_name', 'c5_guarded')->where('column_name', 'c5_partial')->count(), 'no partial rollback');
        }
        $this->assertSame($ids, $this->rows('c5_second'));
        $this->assertWorldIntact();
    }

    #[Test]
    public function a_missing_privilege_is_a_refusal_and_never_an_empty_answer(): void
    {
        $this->grantBypass();
        $admin = DB::connection('pgsql_admin');
        $admin->statement('CREATE TABLE c5_foreign (id uuid PRIMARY KEY)');
        try {
            $this->migration("if (DB::table('c5_foreign')->exists()) { throw new RuntimeException('x'); } Schema::drop('c5_guarded');");
            $this->expectException(RollbackRefusedException::class);
            $this->expectExceptionMessage('lacks a privilege');
            try {
                $this->rollback();
            } finally {
                $this->assertWorldIntact();
            }
        } finally {
            $admin->statement('DROP TABLE c5_foreign');
        }
    }

    #[Test]
    public function the_setting_is_on_inside_the_down_and_never_outlives_it_on_that_connection(): void
    {
        $this->grantBypass();
        $connection = DB::connection(self::CONNECTION);
        $this->migration("DB::statement(\"select set_config('c5.seen', current_setting('row_security'), false)\");");

        $this->rollback();

        $this->assertSame('off', $connection->selectOne("select current_setting('c5.seen') as seen")->seen, 'it really ran with the setting');
        $this->assertSame('on', $connection->selectOne('show row_security')->row_security, 'the SAME connection is back to normal');
        $runtime = DB::selectOne("select rolsuper or rolbypassrls as sees_all from pg_roles where rolname = 'school_os_app'");
        $this->assertFalse((bool) $runtime->sees_all);
        $this->assertSame('on', DB::selectOne('show row_security')->row_security);
    }

    #[Test]
    public function a_refused_down_leaves_that_connection_as_it_found_it(): void
    {
        $this->grantBypass();
        $connection = DB::connection(self::CONNECTION);
        $this->migration("throw new RuntimeException('Refusing to roll back: evidence exists.');");

        try {
            $this->rollback();
            $this->fail('the refusal did not surface');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('evidence exists', $e->getMessage());
        }

        $this->assertSame('on', $connection->selectOne('show row_security')->row_security);
    }

    #[Test]
    public function hardened_environments_refuse_every_destructive_command(): void
    {
        DB::prohibitDestructiveCommands(true);
        try {
            $this->assertSame(1, Artisan::call('migrate:rollback', ['--database' => self::CONNECTION, '--force' => true]));
            foreach (['migrate:reset', 'migrate:refresh', 'migrate:fresh', 'db:wipe'] as $command) {
                $this->assertSame(1, Artisan::call($command, ['--force' => true]), $command);
            }
        } finally {
            DB::prohibitDestructiveCommands(false);
        }
        $this->assertSame(1, DB::connection('pgsql_admin')->table('pg_class')->where('relname', 'migrations')->count());
        $this->assertStringContainsString("Env::get('APP_ENV')", (string) file_get_contents(app_path('Providers/AppServiceProvider.php')), 'the real APP_ENV decides, never only --env');
    }

    #[Test]
    public function the_env_option_cannot_talk_a_hardened_process_out_of_the_refusal(): void
    {
        // A real console process that believes `--env=local`, on a real APP_ENV of staging. The database named does not
        // exist, so this test cannot wipe anything even if the refusal were missing.
        $process = new Process(['php', 'artisan', 'migrate:fresh', '--env=local', '--force'], base_path(), ['APP_ENV' => 'staging', 'DB_DATABASE' => 'c5_no_such_database']);
        $process->run();

        $this->assertNotSame(0, $process->getExitCode());
        $this->assertStringContainsString('prohibited', $process->getOutput().$process->getErrorOutput());
    }

    /** @return list<string> */
    private function op(string ...$args): array
    {
        return ['php', __DIR__.'/../../Support/rollback-guard-op.php', (string) json_encode(config('database.connections.'.self::CONNECTION)), ...$args];
    }

    /** The pattern a guard that must prove emptiness uses: the lift takes ACCESS EXCLUSIVE until commit. */
    private function lockingGuard(): string
    {
        return <<<'PHP'
            DB::statement('ALTER TABLE c5_guarded NO FORCE ROW LEVEL SECURITY');
            if (DB::table('c5_guarded')->exists()) {
                throw new RuntimeException('Refusing to roll back: evidence exists.');
            }
            Schema::drop('c5_guarded');
            PHP;
    }

    #[Test]
    public function an_insert_held_open_is_seen_by_the_waiting_rollback(): void
    {
        $this->grantBypass();
        $this->migration($this->lockingGuard());
        $id = (string) Str::uuid7();

        [$holder, $contender] = $this->raceWithHeldHolder($this->op('insert', 'c5_guarded', $id, (string) Str::uuid7()), $this->op('rollback', $this->directory));

        $this->assertSame('ok', $holder);
        $this->assertStringContainsString('Refusing to roll back', $contender, 'it waited for the row, then saw it');
        $this->assertSame([$id], $this->rows('c5_guarded'));
        $this->assertWorldIntact();
    }

    #[Test]
    public function a_rollback_held_open_keeps_a_waiting_insert_out(): void
    {
        $this->grantBypass();
        $this->migration($this->lockingGuard());

        [$holder, $contender] = $this->raceWithHeldHolder($this->op('rollback', $this->directory), $this->op('insert', 'c5_guarded', (string) Str::uuid7(), (string) Str::uuid7()));

        $this->assertSame('ok', $holder);
        $this->assertStringStartsWith('rejected:', $contender, 'nothing landed in a table being dropped');
        $this->assertNull(DB::connection('pgsql_admin')->selectOne("select to_regclass('public.c5_guarded') as t")->t);
    }
}

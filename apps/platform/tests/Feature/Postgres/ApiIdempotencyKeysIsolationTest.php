<?php

namespace Tests\Feature\Postgres;

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 0C.2 section 32: proof against REAL PostgreSQL, at the raw-SQL
 * level, independent of Eloquent/the application layer entirely --
 * mirrors tests/Feature/Postgres/RawIsolationTest.php's exact pattern
 * for the campuses table, applied to api_idempotency_keys.
 */
class ApiIdempotencyKeysIsolationTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function insertRecord(string $schoolId, string $actorId, string $key): string
    {
        $id = (string) Str::orderedUuid();

        DB::connection('pgsql')->insert(
            'insert into api_idempotency_keys '.
            '(id, school_id, actor_type, actor_id, route_action, idempotency_key, request_fingerprint, request_method, status, expires_at, created_at, updated_at) '.
            "values (?, ?, 'user', ?, 'test.route', ?, repeat('a', 64), 'POST', 'completed', now() + interval '1 day', now(), now())",
            [$id, $schoolId, $actorId, $key],
        );

        return $id;
    }

    #[Test]
    public function api_idempotency_keys_table_has_rls_enabled_and_forced(): void
    {
        $row = DB::connection('pgsql_admin')->selectOne(
            'select relrowsecurity, relforcerowsecurity from pg_class '.
            "where relname = 'api_idempotency_keys' and relnamespace = 'public'::regnamespace",
        );

        $this->assertTrue($row->relrowsecurity);
        $this->assertTrue($row->relforcerowsecurity);
    }

    #[Test]
    public function raw_select_with_no_school_context_returns_zero_rows(): void
    {
        $school = $this->createSchool();
        $user = $this->createUser();

        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $school->id]);
        $this->insertRecord($school->id, $user->id, 'k1');

        DB::connection('pgsql')->statement('RESET '.TenantRls::SESSION_VAR);

        $count = DB::connection('pgsql')->selectOne('select count(*) as c from api_idempotency_keys')->c;
        $this->assertSame(0, (int) $count);
    }

    #[Test]
    public function raw_select_with_school_a_context_sees_only_school_as_rows(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $userA = $this->createUser();
        $userB = $this->createUser();

        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolA->id]);
        $idA = $this->insertRecord($schoolA->id, $userA->id, 'shared-literal-key');

        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolB->id]);
        $this->insertRecord($schoolB->id, $userB->id, 'shared-literal-key');

        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolA->id]);
        $rows = DB::connection('pgsql')->select('select id from api_idempotency_keys');

        $this->assertCount(1, $rows);
        $this->assertSame($idA, $rows[0]->id);
    }

    #[Test]
    public function school_a_cannot_read_school_bs_stored_response_via_raw_sql(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $userB = $this->createUser();

        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolB->id]);
        DB::connection('pgsql')->insert(
            'insert into api_idempotency_keys '.
            '(id, school_id, actor_type, actor_id, route_action, idempotency_key, request_fingerprint, request_method, status, response_status, response_body, expires_at, created_at, updated_at) '.
            "values (?, ?, 'user', ?, 'test.route', 'secret-key', repeat('a', 64), 'POST', 'completed', 200, ?, now() + interval '1 day', now(), now())",
            [(string) Str::orderedUuid(), $schoolB->id, $userB->id, json_encode(['secret' => 'school-b-only'])],
        );

        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolA->id]);
        $rows = DB::connection('pgsql')->select("select response_body from api_idempotency_keys where idempotency_key = 'secret-key'");

        $this->assertCount(0, $rows);
    }

    #[Test]
    public function raw_insert_for_a_different_school_than_the_active_context_is_rejected(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $userB = $this->createUser();

        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolA->id]);

        $this->expectException(QueryException::class);

        DB::connection('pgsql')->transaction(function () use ($schoolB, $userB): void {
            $this->insertRecord($schoolB->id, $userB->id, 'rogue-key');
        });
    }

    #[Test]
    public function raw_update_across_schools_affects_zero_rows_not_an_error(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $userB = $this->createUser();

        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolB->id]);
        $idB = $this->insertRecord($schoolB->id, $userB->id, 'k-update');

        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolA->id]);
        $affected = DB::connection('pgsql')->update(
            "update api_idempotency_keys set status = 'failed' where id = ?",
            [$idB],
        );
        $this->assertSame(0, $affected);

        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolB->id]);
        $stillCompleted = DB::connection('pgsql')->selectOne('select status from api_idempotency_keys where id = ?', [$idB]);
        $this->assertSame('completed', $stillCompleted->status);
    }

    #[Test]
    public function raw_delete_across_schools_affects_zero_rows(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $userB = $this->createUser();

        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolB->id]);
        $idB = $this->insertRecord($schoolB->id, $userB->id, 'k-delete');

        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolA->id]);
        $affected = DB::connection('pgsql')->delete('delete from api_idempotency_keys where id = ?', [$idB]);
        $this->assertSame(0, $affected);
    }

    #[Test]
    public function the_scope_unique_constraint_permits_identical_keys_across_different_schools(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $userA = $this->createUser();
        $userB = $this->createUser();

        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolA->id]);
        $this->insertRecord($schoolA->id, $userA->id, 'not-actually-shared-because-scoped');

        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolB->id]);
        // Must NOT throw a unique-constraint violation -- the scope
        // includes school_id, so this is not the same logical claim.
        $this->insertRecord($schoolB->id, $userB->id, 'not-actually-shared-because-scoped');

        $count = DB::connection('pgsql')->selectOne('select count(*) as c from api_idempotency_keys')->c;
        $this->assertSame(1, (int) $count); // only School B visible in School B's context
    }
}

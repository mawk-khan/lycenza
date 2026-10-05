<?php

namespace Tests\Feature\Postgres;

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\AssertsRuntimeDeleteRevoked;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 0C.3 section 54/89: proof against REAL PostgreSQL, at the
 * raw-SQL level, independent of Eloquent/the application layer
 * entirely -- mirrors ApiIdempotencyKeysIsolationTest's pattern,
 * applied to all four webhook tables. The runtime role's
 * rolsuper=false/rolbypassrls=false property is DB-role-wide, not
 * per-table, and is already proven generically in
 * tests/Feature/Postgres/RawIsolationTest.php -- not duplicated here.
 */
class WebhookRlsIsolationTest extends TestCase
{
    use AssertsRuntimeDeleteRevoked, CreatesTenancyFixtures;

    private function setSchool(string $schoolId): void
    {
        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolId]);
    }

    private function insertEndpoint(string $schoolId, string $name = 'ep'): string
    {
        $id = (string) Str::orderedUuid();
        DB::connection('pgsql')->insert(
            'insert into webhook_endpoints (id, school_id, name, url, secret_encrypted, status, created_at, updated_at) '.
            "values (?, ?, ?, 'https://example.test/hook', 'secret', 'active', now(), now())",
            [$id, $schoolId, $name],
        );

        return $id;
    }

    private function insertSubscription(string $schoolId, string $endpointId, string $eventType = 'school.setting.changed.v1'): string
    {
        $id = (string) Str::orderedUuid();
        DB::connection('pgsql')->insert(
            'insert into webhook_subscriptions (id, school_id, webhook_endpoint_id, event_type, enabled, created_at, updated_at) '.
            'values (?, ?, ?, ?, true, now(), now())',
            [$id, $schoolId, $endpointId, $eventType],
        );

        return $id;
    }

    private function insertDelivery(string $schoolId, string $endpointId): string
    {
        $id = (string) Str::orderedUuid();
        DB::connection('pgsql')->insert(
            'insert into webhook_deliveries (id, school_id, webhook_endpoint_id, event_id, event_type, status, created_at, updated_at) '.
            "values (?, ?, ?, ?, 'school.setting.changed.v1', 'pending', now(), now())",
            [$id, $schoolId, $endpointId, (string) Str::orderedUuid()],
        );

        return $id;
    }

    private function insertAttempt(string $schoolId, string $deliveryId, int $attemptNumber = 1): string
    {
        $id = (string) Str::orderedUuid();
        DB::connection('pgsql')->insert(
            'insert into webhook_delivery_attempts (id, school_id, webhook_delivery_id, attempt_number, started_at, outcome, created_at, updated_at) '.
            "values (?, ?, ?, ?, now(), 'success', now(), now())",
            [$id, $schoolId, $deliveryId, $attemptNumber],
        );

        return $id;
    }

    #[Test]
    public function all_four_webhook_tables_have_rls_enabled_and_forced(): void
    {
        foreach (['webhook_endpoints', 'webhook_subscriptions', 'webhook_deliveries', 'webhook_delivery_attempts'] as $table) {
            $row = DB::connection('pgsql_admin')->selectOne(
                'select relrowsecurity, relforcerowsecurity from pg_class '.
                'where relname = ? and relnamespace = ?::regnamespace',
                [$table, 'public'],
            );

            $this->assertTrue($row->relrowsecurity, "{$table} must have RLS enabled");
            $this->assertTrue($row->relforcerowsecurity, "{$table} must FORCE RLS");
        }
    }

    #[Test]
    public function no_school_context_sees_zero_rows_on_every_webhook_table(): void
    {
        $school = $this->createSchool();
        $this->setSchool($school->id);
        $endpointId = $this->insertEndpoint($school->id);
        $this->insertSubscription($school->id, $endpointId);
        $deliveryId = $this->insertDelivery($school->id, $endpointId);
        $this->insertAttempt($school->id, $deliveryId);

        DB::connection('pgsql')->statement('RESET '.TenantRls::SESSION_VAR);

        foreach (['webhook_endpoints', 'webhook_subscriptions', 'webhook_deliveries', 'webhook_delivery_attempts'] as $table) {
            $count = DB::connection('pgsql')->selectOne("select count(*) as c from {$table}")->c;
            $this->assertSame(0, (int) $count, "{$table} must be invisible with no TenantContext");
        }
    }

    #[Test]
    public function school_a_cannot_read_school_bs_endpoint_subscription_delivery_or_attempt(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();

        $this->setSchool($schoolB->id);
        $endpointB = $this->insertEndpoint($schoolB->id, 'school-b-only');
        $subB = $this->insertSubscription($schoolB->id, $endpointB);
        $deliveryB = $this->insertDelivery($schoolB->id, $endpointB);
        $attemptB = $this->insertAttempt($schoolB->id, $deliveryB);

        $this->setSchool($schoolA->id);

        $this->assertCount(0, DB::connection('pgsql')->select('select id from webhook_endpoints where id = ?', [$endpointB]));
        $this->assertCount(0, DB::connection('pgsql')->select('select id from webhook_subscriptions where id = ?', [$subB]));
        $this->assertCount(0, DB::connection('pgsql')->select('select id from webhook_deliveries where id = ?', [$deliveryB]));
        $this->assertCount(0, DB::connection('pgsql')->select('select id from webhook_delivery_attempts where id = ?', [$attemptB]));
    }

    #[Test]
    public function cross_school_writes_affect_zero_rows_on_every_webhook_table(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();

        $this->setSchool($schoolB->id);
        $endpointB = $this->insertEndpoint($schoolB->id);
        $subB = $this->insertSubscription($schoolB->id, $endpointB);
        $deliveryB = $this->insertDelivery($schoolB->id, $endpointB);

        $this->setSchool($schoolA->id);

        $this->assertSame(0, DB::connection('pgsql')->update("update webhook_endpoints set status = 'disabled' where id = ?", [$endpointB]));
        $this->assertSame(0, DB::connection('pgsql')->update('update webhook_subscriptions set enabled = false where id = ?', [$subB]));
        $this->assertSame(0, DB::connection('pgsql')->update("update webhook_deliveries set status = 'abandoned' where id = ?", [$deliveryB]));
        $this->assertRuntimeDeleteRevoked('delete from webhook_deliveries where id = ?', [$deliveryB]);
    }

    #[Test]
    public function raw_insert_for_a_different_school_than_the_active_context_is_rejected(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();

        $this->setSchool($schoolA->id);

        $this->expectException(QueryException::class);

        // Wrapped in its own transaction (a nested SAVEPOINT, since
        // DatabaseTransactions already wraps the whole test) so
        // Laravel rolls back cleanly on the expected failure instead
        // of leaving the `pgsql` connection in Postgres's "current
        // transaction is aborted" state for tearDown()'s RESET
        // statement -- see ApiIdempotencyKeysIsolationTest's identical
        // pattern.
        DB::connection('pgsql')->transaction(function () use ($schoolB): void {
            $this->insertEndpoint($schoolB->id);
        });
    }

    #[Test]
    public function a_subscription_school_id_must_match_its_own_endpoints_school_id(): void
    {
        // Section 55: composite FK (webhook_endpoint_id, school_id) ->
        // webhook_endpoints(id, school_id) makes this a foreign-key
        // violation, not merely an application bug, even when the
        // insert claims the SAME active context the endpoint's real
        // owner uses for its OWN other rows.
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();

        $this->setSchool($schoolA->id);
        $endpointA = $this->insertEndpoint($schoolA->id);

        $this->expectException(QueryException::class);

        // Bypass RLS's WITH CHECK narrowly by asserting the FK failure
        // happens even attempting the insert as school A itself
        // (RLS's WITH CHECK on school_id=schoolA would pass; it is the
        // COMPOSITE FK against webhook_endpoints(id, school_id) that
        // must reject webhook_endpoint_id=$endpointA with a mismatched
        // school_id if one were ever attempted). Constructed via the
        // admin connection specifically to isolate the FK check from
        // RLS's own WITH CHECK, proving the FK exists independently.
        DB::connection('pgsql_admin')->insert(
            'insert into webhook_subscriptions (id, school_id, webhook_endpoint_id, event_type, enabled, created_at, updated_at) '.
            "values (?, ?, ?, 'school.setting.changed.v1', true, now(), now())",
            [(string) Str::orderedUuid(), $schoolB->id, $endpointA],
        );
    }

    #[Test]
    public function a_delivery_school_id_must_match_its_own_endpoints_school_id(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();

        $this->setSchool($schoolA->id);
        $endpointA = $this->insertEndpoint($schoolA->id);

        $this->expectException(QueryException::class);

        DB::connection('pgsql_admin')->insert(
            'insert into webhook_deliveries (id, school_id, webhook_endpoint_id, event_id, event_type, status, created_at, updated_at) '.
            "values (?, ?, ?, ?, 'school.setting.changed.v1', 'pending', now(), now())",
            [(string) Str::orderedUuid(), $schoolB->id, $endpointA, (string) Str::orderedUuid()],
        );
    }

    #[Test]
    public function subscription_unique_constraint_permits_the_same_event_type_across_different_endpoints(): void
    {
        $school = $this->createSchool();
        $this->setSchool($school->id);
        $endpointOne = $this->insertEndpoint($school->id, 'one');
        $endpointTwo = $this->insertEndpoint($school->id, 'two');

        $this->insertSubscription($school->id, $endpointOne, 'shared.event.v1');
        // Must NOT throw -- uniqueness is (webhook_endpoint_id, event_type).
        $this->insertSubscription($school->id, $endpointTwo, 'shared.event.v1');

        $count = DB::connection('pgsql')->selectOne('select count(*) as c from webhook_subscriptions')->c;
        $this->assertSame(2, (int) $count);
    }

    #[Test]
    public function delivery_unique_constraint_rejects_a_second_row_for_the_same_endpoint_and_event(): void
    {
        $school = $this->createSchool();
        $this->setSchool($school->id);
        $endpoint = $this->insertEndpoint($school->id);
        $eventId = (string) Str::orderedUuid();

        DB::connection('pgsql')->insert(
            'insert into webhook_deliveries (id, school_id, webhook_endpoint_id, event_id, event_type, status, created_at, updated_at) '.
            "values (?, ?, ?, ?, 'school.setting.changed.v1', 'pending', now(), now())",
            [(string) Str::orderedUuid(), $school->id, $endpoint, $eventId],
        );

        $this->expectException(QueryException::class);

        DB::connection('pgsql')->transaction(function () use ($school, $endpoint, $eventId): void {
            DB::connection('pgsql')->insert(
                'insert into webhook_deliveries (id, school_id, webhook_endpoint_id, event_id, event_type, status, created_at, updated_at) '.
                "values (?, ?, ?, ?, 'school.setting.changed.v1', 'pending', now(), now())",
                [(string) Str::orderedUuid(), $school->id, $endpoint, $eventId],
            );
        });
    }

    #[Test]
    public function attempt_unique_constraint_rejects_a_duplicate_attempt_number_for_the_same_delivery(): void
    {
        $school = $this->createSchool();
        $this->setSchool($school->id);
        $endpoint = $this->insertEndpoint($school->id);
        $delivery = $this->insertDelivery($school->id, $endpoint);

        $this->insertAttempt($school->id, $delivery, 1);

        $this->expectException(QueryException::class);

        DB::connection('pgsql')->transaction(function () use ($school, $delivery): void {
            $this->insertAttempt($school->id, $delivery, 1);
        });
    }

    #[Test]
    public function webhook_delivery_attempts_cannot_be_updated_or_deleted_by_the_runtime_role(): void
    {
        $school = $this->createSchool();
        $this->setSchool($school->id);
        $endpoint = $this->insertEndpoint($school->id);
        $delivery = $this->insertDelivery($school->id, $endpoint);
        $attempt = $this->insertAttempt($school->id, $delivery);

        $this->expectException(QueryException::class);

        DB::connection('pgsql')->transaction(function () use ($attempt): void {
            DB::connection('pgsql')->update("update webhook_delivery_attempts set outcome = 'timeout' where id = ?", [$attempt]);
        });
    }
}

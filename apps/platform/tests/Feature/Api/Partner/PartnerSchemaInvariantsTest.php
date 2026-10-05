<?php

namespace Tests\Feature\Api\Partner;

use App\Support\Api\PartnerCredentialFormat;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 0O.3 (ADR 0049 sections 4-5): what the DATABASE enforces for
 * partner API clients, with raw SQL on the runtime role, each violation in
 * its own savepoint -- and the explicit, documented RLS exception.
 */
class PartnerSchemaInvariantsTest extends TestCase
{
    use CreatesTenancyFixtures;

    /** @return array{0: string, 1: string, 2: string|null} [school, client, current credential] */
    private function client(bool $withCredential = true): array
    {
        $school = $this->createSchool();
        $user = $this->createUser();
        $client = (string) Str::uuid7();
        DB::table('api_clients')->insert(['id' => $client, 'school_id' => $school->id, 'name' => 'C', 'scopes' => json_encode(['partner.probe.read']), 'status' => 'active', 'created_by_user_id' => $user->id, 'created_at' => now(), 'updated_at' => now()]);

        return [$school->id, $client, $withCredential ? $this->credential($client, $school->id) : null];
    }

    private function credential(string $client, string $school, array $overrides = []): string
    {
        $id = (string) Str::uuid7();
        DB::table('api_client_credentials')->insert([
            'id' => $id, 'api_client_id' => $client, 'school_id' => $school,
            'key_id' => PartnerCredentialFormat::newKeyId(), 'secret_hash' => hash('sha256', 'x'.$id),
            'issued_at' => now(), 'expires_at' => now()->addDays(90), 'created_at' => now(), 'updated_at' => now(),
            ...$overrides,
        ]);

        return $id;
    }

    private function assertRejected(callable $statement, string $needle): void
    {
        try {
            DB::transaction(fn () => $statement());
        } catch (QueryException $e) {
            $this->assertStringContainsString($needle, $e->getMessage());

            return;
        }

        $this->fail("Expected the database to reject the statement ({$needle}).");
    }

    #[Test]
    public function the_school_binding_is_immutable_and_a_credential_follows_its_client(): void
    {
        [$school, $client, $credential] = $this->client();
        [, $bare] = $this->client(withCredential: false);
        $other = $this->createSchool()->id;

        $this->assertRejected(fn () => DB::table('api_clients')->where('id', $client)->update(['school_id' => $other]), 'immutable');
        $this->assertRejected(fn () => DB::table('api_client_credentials')->where('id', $credential)->update(['school_id' => $other]), 'immutable');
        $this->assertRejected(fn () => $this->credential($bare, $other), 'foreign key');
        $this->assertRejected(fn () => DB::table('api_clients')->where('id', $client)->update(['scopes' => json_encode(['x'])]), 'immutable');
    }

    #[Test]
    public function credentials_expire_hash_only_and_keep_at_most_one_current(): void
    {
        [$school, $client, $current] = $this->client();

        $this->assertRejected(fn () => $this->credential($client, $school, ['expires_at' => now()->addDays(366)]), 'api_client_credentials_lifetime_check');
        $this->assertRejected(fn () => $this->credential($client, $school, ['expires_at' => null]), 'null value');
        $this->assertRejected(fn () => $this->credential($client, $school, ['secret_hash' => 'plaintext-secret']), 'api_client_credentials_hash_check');
        $this->assertRejected(fn () => $this->credential($client, $school), 'api_client_credentials_one_current');
        $this->assertRejected(fn () => DB::table('api_client_credentials')->where('id', $current)->update(['expires_at' => now()->addDays(120)]), 'only be shortened');
        $this->assertRejected(fn () => DB::table('api_client_credentials')->where('id', $current)->update(['secret_hash' => hash('sha256', 'other')]), 'immutable');
        $this->assertRejected(fn () => DB::table('api_client_credentials')->where('id', $current)->update(['superseded_at' => now()]), 'api_client_credentials_overlap_check');

        DB::table('api_client_credentials')->where('id', $current)->update(['superseded_at' => now(), 'expires_at' => now()->addHours(24)]);
        $this->assertRejected(fn () => DB::table('api_client_credentials')->where('id', $current)->update(['superseded_at' => null]), 'final');
        DB::table('api_client_credentials')->where('id', $current)->update(['revoked_at' => now()]);
        $this->assertRejected(fn () => DB::table('api_client_credentials')->where('id', $current)->update(['revoked_at' => null]), 'final');

        $key = DB::table('api_client_credentials')->where('id', $current)->value('key_id');
        $this->assertRejected(fn () => $this->credential($client, $school, ['key_id' => $key]), 'unique');
    }

    #[Test]
    public function revocation_is_final_history_cannot_be_deleted_and_wildcards_are_refused(): void
    {
        [$school, $client] = $this->client();
        $user = $this->createUser();

        $this->assertRejected(fn () => DB::table('api_clients')->where('id', $client)->update(['status' => 'revoked']), 'api_clients_revocation_check');
        DB::table('api_clients')->where('id', $client)->update(['status' => 'revoked', 'revoked_at' => now(), 'revoked_by_user_id' => $user->id]);
        $this->assertRejected(fn () => DB::table('api_clients')->where('id', $client)->update(['status' => 'active', 'revoked_at' => null, 'revoked_by_user_id' => null]), 'final');
        $this->assertRejected(fn () => $this->credential($client, $school, ['key_id' => PartnerCredentialFormat::newKeyId()]), 'only for an active client');

        $this->assertRejected(fn () => DB::table('api_client_credentials')->where('api_client_id', $client)->delete(), 'permission denied');
        $this->assertRejected(fn () => DB::table('api_clients')->where('id', $client)->delete(), 'permission denied');

        $this->assertRejected(fn () => DB::table('api_clients')->insert(['id' => (string) Str::uuid7(), 'school_id' => $school, 'name' => 'W', 'scopes' => json_encode(['*']), 'status' => 'active', 'created_by_user_id' => $user->id, 'created_at' => now(), 'updated_at' => now()]), 'api_clients_scopes_check');
        $this->assertRejected(fn () => DB::table('api_clients')->insert(['id' => (string) Str::uuid7(), 'school_id' => $school, 'name' => 'E', 'scopes' => json_encode([]), 'status' => 'active', 'created_by_user_id' => $user->id, 'created_at' => now(), 'updated_at' => now()]), 'api_clients_scopes_check');
    }

    #[Test]
    public function the_bootstrap_tables_are_the_documented_rls_exception_and_hold_no_secret(): void
    {
        $admin = DB::connection('pgsql_admin');

        foreach (['api_clients', 'api_client_credentials'] as $table) {
            $rls = $admin->selectOne("select relrowsecurity from pg_class where relname = ? and relnamespace = 'public'::regnamespace", [$table]);
            $this->assertFalse((bool) $rls->relrowsecurity, "{$table} is a platform-resolvable bootstrap record (ADR 0049 section 4)");
        }

        // Tenant data the partner path reads keeps forced RLS.
        foreach (['campuses', 'school_audit_events', 'academic_years'] as $table) {
            $rls = $admin->selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = ? and relnamespace = 'public'::regnamespace", [$table]);
            $this->assertTrue((bool) $rls->relforcerowsecurity, $table);
        }

        $columns = collect($admin->select("select column_name from information_schema.columns where table_name = 'api_client_credentials'"))->pluck('column_name')->sort()->values()->all();
        // E21-RH.7 (ADR 0066 §15): + the database-recorded retention anchor (no secret).
        $this->assertSame(['api_client_id', 'created_at', 'expires_at', 'id', 'issued_at', 'key_id', 'last_used_at', 'retention_recorded_at', 'revoked_at', 'school_id', 'secret_hash', 'superseded_at', 'updated_at'], $columns);

        $role = $admin->selectOne('select rolsuper, rolbypassrls from pg_roles where rolname = ?', [config('database.connections.pgsql.username')]);
        $this->assertFalse((bool) $role->rolsuper);
        $this->assertFalse((bool) $role->rolbypassrls);
    }
}

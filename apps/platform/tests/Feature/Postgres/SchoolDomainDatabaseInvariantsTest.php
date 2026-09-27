<?php

namespace Tests\Feature\Postgres;

use App\Models\School;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 0O.8A (ADR 0054 sections 3-4): what the DATABASE enforces for custom
 * School domains, independent of application code -- raw SQL as the runtime
 * role, each violation inside its own savepoint. The deferred primary
 * invariant is forced to fire inside the savepoint (SET CONSTRAINTS ...
 * IMMEDIATE); SchoolDomainPrimaryInvariantCommitTest proves it at a real
 * COMMIT.
 */
class SchoolDomainDatabaseInvariantsTest extends TestCase
{
    use CreatesTenancyFixtures;

    /** A stand-in ciphertext (the column holds an encrypted value), computed at run time. */
    private static function fake(string $label): string
    {
        return base64_encode((string) json_encode(['iv' => 'test', 'value' => $label]));
    }

    private function insert(School $school, string $hostname, array $overrides = []): string
    {
        $id = (string) Str::uuid7();
        DB::table('school_domains')->insert([
            'id' => $id, 'school_id' => $school->id, 'hostname' => $hostname, 'type' => 'custom',
            'state' => 'pending_verification', 'is_primary' => false, 'challenge_token' => self::fake('first'),
            'challenge_generation' => 1, 'challenge_expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now(),
            ...$overrides,
        ]);

        return $id;
    }

    private function set(string $id, array $values): void
    {
        DB::table('school_domains')->where('id', $id)->update($values);
    }

    private function activate(string $id, bool $primary): void
    {
        $this->set($id, ['state' => 'verified', 'verified_at' => now(), 'ownership_outcome' => 'match']);
        $this->set($id, ['state' => 'tls_pending', 'routing_outcome' => 'pass']);
        $this->set($id, ['state' => 'active', 'tls_outcome' => 'pass', 'activated_at' => now(), 'is_primary' => $primary]);
    }

    private function assertRejected(callable $statement, string $needle, bool $atCommit = false): void
    {
        try {
            DB::transaction(function () use ($statement, $atCommit): void {
                $statement();
                if ($atCommit) {
                    DB::statement('SET CONSTRAINTS trg_school_domains_primary_invariant IMMEDIATE');
                }
            });
        } catch (QueryException $e) {
            $this->assertStringContainsString($needle, $e->getMessage());

            return;
        } finally {
            DB::statement('SET CONSTRAINTS trg_school_domains_primary_invariant DEFERRED');
        }

        $this->fail("Expected the database to reject the statement ({$needle}).");
    }

    #[Test]
    public function hostnames_are_canonical_ascii_ldh_and_never_idn(): void
    {
        $school = $this->createSchool();

        foreach (['ERP.Northfield.org', 'erp.northfield.org.', 'erp_x.northfield.org', 'xn--bcher-kva.northfield.org', 'erp.xn--p1ai', '10.0.0.1', 'localhost', 'erp..northfield.org', '-a.northfield.org', 'erp.northfield.org:443'] as $bad) {
            $this->assertRejected(fn () => $this->insert($school, $bad), 'school_domains_hostname_check');
        }

        $this->assertNotEmpty($this->insert($school, 'erp.northfield.org'));
        $this->assertRejected(fn () => $this->insert($school, 'b.northfield.org', ['type' => 'platform_subdomain']), 'school_domains_type_check');
    }

    #[Test]
    public function every_row_starts_as_a_pending_non_primary_claim(): void
    {
        $school = $this->createSchool();

        $this->assertRejected(fn () => $this->insert($school, 'a.northfield.org', ['state' => 'active', 'activated_at' => now(), 'ownership_outcome' => 'match', 'routing_outcome' => 'pass', 'tls_outcome' => 'pass']), 'school_domains_insert_state');
        $this->assertRejected(fn () => $this->insert($school, 'b.northfield.org', ['is_primary' => true]), 'school_domains_insert_state');
        $this->assertRejected(fn () => $this->insert($school, 'c.northfield.org', ['challenge_generation' => 2]), 'school_domains_insert_state');
        $this->assertRejected(fn () => $this->insert($school, 'd.northfield.org', ['challenge_token' => null]), 'school_domains_challenge_check');
    }

    #[Test]
    public function one_claiming_row_per_hostname_while_history_never_blocks_a_new_claim(): void
    {
        $a = $this->createSchool();
        $b = $this->createSchool();
        $first = $this->insert($a, 'erp.northfield.org');

        $this->assertRejected(fn () => $this->insert($b, 'erp.northfield.org'), 'school_domains_hostname_claim_unique');
        $this->assertRejected(fn () => $this->insert($a, 'erp.northfield.org'), 'school_domains_hostname_claim_unique');

        $this->set($first, ['state' => 'expired', 'expired_at' => now()]);
        $second = $this->insert($b, 'erp.northfield.org');
        $this->set($second, ['state' => 'revoked', 'revoked_at' => now(), 'revocation_source' => 'school']);
        $third = $this->insert($a, 'erp.northfield.org');

        $this->assertSame(3, DB::table('school_domains')->where('hostname', 'erp.northfield.org')->count(), 'history is kept');
        $this->assertSame([$third], DB::table('school_domains')->where('hostname', 'erp.northfield.org')->whereIn('state', ['pending_verification', 'verified', 'tls_pending', 'active', 'suspended'])->pluck('id')->all());
    }

    #[Test]
    public function at_most_three_claiming_domains_per_school(): void
    {
        $school = $this->createSchool();
        foreach (['a', 'b', 'c'] as $label) {
            $this->insert($school, "{$label}.northfield.org");
        }

        $this->assertRejected(fn () => $this->insert($school, 'd.northfield.org'), 'school_domains_limit_exceeded');

        // Terminal history does not count.
        $this->set(DB::table('school_domains')->where('hostname', 'a.northfield.org')->value('id'), ['state' => 'revoked', 'revoked_at' => now(), 'revocation_source' => 'school']);
        $this->assertNotEmpty($this->insert($school, 'd.northfield.org'));
    }

    #[Test]
    public function only_the_adr_transitions_are_permitted_and_terminal_rows_never_change(): void
    {
        $school = $this->createSchool();
        $id = $this->insert($school, 'erp.northfield.org');

        foreach (['tls_pending', 'active', 'suspended'] as $skip) {
            $this->assertRejected(fn () => $this->set($id, ['state' => $skip, 'activated_at' => now(), 'suspended_at' => now(), 'suspension_reason' => 'tls', 'ownership_outcome' => 'match', 'routing_outcome' => 'pass', 'tls_outcome' => 'pass']), 'school_domains_transition');
        }

        $this->set($id, ['state' => 'verified', 'verified_at' => now(), 'ownership_outcome' => 'match']);
        $this->assertRejected(fn () => $this->set($id, ['state' => 'pending_verification']), 'school_domains_transition');
        $this->assertRejected(fn () => $this->set($id, ['state' => 'expired', 'expired_at' => now()]), 'school_domains_transition');
        $this->set($id, ['state' => 'tls_pending', 'routing_outcome' => 'pass']);

        // Activation carries passing evidence in the same write.
        $this->assertRejected(fn () => $this->set($id, ['state' => 'active', 'activated_at' => now(), 'tls_outcome' => 'proof_mismatch']), 'school_domains_activation_evidence');
        $this->assertRejected(fn () => $this->set($id, ['state' => 'active', 'tls_outcome' => 'pass']), 'school_domains_active_check');
        $this->set($id, ['state' => 'active', 'activated_at' => now(), 'tls_outcome' => 'pass', 'is_primary' => true]);

        $this->assertRejected(fn () => $this->set($id, ['state' => 'suspended', 'is_primary' => false]), 'school_domains_suspended_check');
        $this->set($id, ['state' => 'suspended', 'is_primary' => false, 'suspended_at' => now(), 'suspension_reason' => 'routing']);
        $this->set($id, ['state' => 'active', 'is_primary' => true]);
        $this->set($id, ['state' => 'revoked', 'is_primary' => false, 'revoked_at' => now(), 'revocation_source' => 'school']);

        foreach ([['state' => 'active'], ['state' => 'pending_verification'], ['next_check_at' => now()], ['revocation_source' => 'operator']] as $change) {
            $this->assertRejected(fn () => $this->set($id, $change), 'school_domains_terminal');
        }
    }

    #[Test]
    public function identity_and_the_challenge_are_immutable_except_by_regeneration_while_pending(): void
    {
        $a = $this->createSchool();
        $b = $this->createSchool();
        $id = $this->insert($a, 'erp.northfield.org');

        $this->assertRejected(fn () => $this->set($id, ['hostname' => 'other.northfield.org']), 'school_domains_immutable');
        $this->assertRejected(fn () => $this->set($id, ['school_id' => $b->id]), 'school_domains_immutable');
        $this->assertRejected(fn () => $this->set($id, ['challenge_token' => self::fake('regenerated')]), 'school_domains_challenge');
        $this->assertRejected(fn () => $this->set($id, ['challenge_token' => self::fake('regenerated'), 'challenge_generation' => 3]), 'school_domains_challenge');

        $this->set($id, ['challenge_token' => self::fake('regenerated'), 'challenge_generation' => 2]);
        $this->set($id, ['state' => 'verified', 'verified_at' => now(), 'ownership_outcome' => 'match']);
        $this->assertRejected(fn () => $this->set($id, ['challenge_token' => self::fake('after-verify'), 'challenge_generation' => 3]), 'school_domains_challenge');
    }

    #[Test]
    public function a_school_has_exactly_one_primary_among_its_active_domains(): void
    {
        $school = $this->createSchool();
        $a = $this->insert($school, 'a.northfield.org');
        $b = $this->insert($school, 'b.northfield.org');

        // Only an active row may be primary (row CHECK).
        $this->assertRejected(fn () => $this->set($a, ['is_primary' => true]), 'school_domains_primary_active_check');

        // At most one primary (partial unique index).
        $this->activate($a, true);
        $this->activate($b, false);
        $this->assertRejected(fn () => $this->set($b, ['is_primary' => true]), 'school_domains_one_primary_per_school');

        // At least one -- the cross-row half (deferred constraint trigger).
        $this->assertRejected(fn () => $this->set($a, ['is_primary' => false]), 'school_domains_primary_invariant', atCommit: true);
        $this->assertRejected(function () use ($a): void {
            $this->set($a, ['state' => 'revoked', 'is_primary' => false, 'revoked_at' => now(), 'revocation_source' => 'school']);
        }, 'school_domains_primary_invariant', atCommit: true);

        // A swap inside one transaction is fine; so is the last active going away.
        DB::transaction(function () use ($a, $b): void {
            $this->set($a, ['is_primary' => false]);
            $this->set($b, ['is_primary' => true]);
            DB::statement('SET CONSTRAINTS trg_school_domains_primary_invariant IMMEDIATE');
        });
        DB::statement('SET CONSTRAINTS trg_school_domains_primary_invariant DEFERRED');
        $this->assertSame([$b], DB::table('school_domains')->where('school_id', $school->id)->where('is_primary', true)->pluck('id')->all());

        DB::transaction(function () use ($a, $b): void {
            $this->set($b, ['state' => 'revoked', 'is_primary' => false, 'revoked_at' => now(), 'revocation_source' => 'school']);
            $this->set($a, ['state' => 'revoked', 'revoked_at' => now(), 'revocation_source' => 'school']);
            DB::statement('SET CONSTRAINTS trg_school_domains_primary_invariant IMMEDIATE');
        });
        DB::statement('SET CONSTRAINTS trg_school_domains_primary_invariant DEFERRED');
    }

    #[Test]
    public function the_runtime_role_cannot_delete_and_a_school_with_domains_cannot_vanish_under_them(): void
    {
        $school = $this->createSchool();
        $id = $this->insert($school, 'erp.northfield.org');

        $this->assertRejected(fn () => DB::table('school_domains')->where('id', $id)->delete(), 'permission denied for table school_domains');

        $fk = DB::connection('pgsql_admin')->selectOne("select confdeltype from pg_constraint where conname = 'school_domains_school_id_foreign'");
        $this->assertSame('r', $fk->confdeltype, 'ON DELETE RESTRICT: domain history is never cascaded away');

        $rls = DB::connection('pgsql_admin')->selectOne("select relrowsecurity from pg_class where relname = 'school_domains'");
        $this->assertFalse((bool) $rls->relrowsecurity, 'platform data resolved before any School context (NON_RLS_SCHOOL_TABLES)');
    }
}

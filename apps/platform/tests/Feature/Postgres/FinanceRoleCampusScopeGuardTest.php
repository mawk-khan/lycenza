<?php

namespace Tests\Feature\Postgres;

use App\Models\Role;
use App\Models\School;
use App\Models\SchoolMembership;
use App\Support\Authorization\Campus\CampusScopeCatalogue;
use App\Support\Tenancy\TenantRls;
use App\Support\Testing\LocalCatalogueFixtures;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * ORG.7G-2 (ADR 0076 §51, G2-D2): the database refuses a Campus-scoped grant
 * of any role holding whole-School Finance authority -- by capability, never
 * by role name -- below every application path (raw INSERT under the runtime
 * connection, as the catalogue owner). cashier@Campus and every whole-School
 * grant stay allowed, and a protected key can never be added to a role with
 * active Campus grants.
 */
class FinanceRoleCampusScopeGuardTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function insert(School $school, SchoolMembership $membership, string $roleKey, ?string $campusId): ?string
    {
        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $school->id]);
        $roleId = Role::query()->where('key', $roleKey)->where('scope', Role::SCOPE_SCHOOL)->value('id');
        try {
            DB::connection('pgsql')->transaction(fn () => LocalCatalogueFixtures::asOwner(fn () => DB::table('membership_role_assignments')->insert([
                'id' => (string) Str::uuid7(), 'school_id' => $school->id, 'school_membership_id' => $membership->id,
                'role_id' => $roleId, 'campus_id' => $campusId,
                'assigned_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ])));
        } catch (QueryException $e) {
            return $e->getMessage();
        }

        return null;
    }

    #[Test]
    public function a_protected_finance_role_is_refused_a_campus_grant_while_cashier_and_whole_school_grants_pass(): void
    {
        $school = $this->createSchool();
        $campus = $this->createCampus($school);
        $membership = $this->createMembership($this->createUser(), $school);

        $this->assertStringContainsString('whole-School Finance authority cannot be Campus-scoped', (string) $this->insert($school, $membership, 'accountant', $campus->id));
        $this->assertStringContainsString('access administration', (string) $this->insert($school, $membership, 'school_admin', $campus->id), 'school_admin stays refused by the access-administration rule');
        $this->assertNull($this->insert($school, $membership, 'cashier', $campus->id), 'cashier@Campus is allowed');
        $this->assertNull($this->insert($school, $membership, 'accountant', null), 'a whole-School accountant grant is unaffected');
    }

    #[Test]
    public function the_database_rule_mirrors_the_catalogue_and_never_names_a_role(): void
    {
        $source = (string) DB::connection('pgsql_admin')->selectOne("select prosrc from pg_proc where proname = 'assert_membership_role_assignment_scope'")->prosrc;
        foreach (CampusScopeCatalogue::CAMPUS_INCOMPATIBLE as $key) {
            $sql = str_ends_with($key, '.') ? "LIKE '".str_replace('_', '\_', $key)."%'" : "'{$key}'";
            $this->assertStringContainsString($sql, $source, "the trigger enforces {$key}");
        }
        foreach (['accountant', 'cashier', 'school_admin', 'principal'] as $role) {
            $this->assertStringNotContainsString("'{$role}'", $source, 'decided by capability, never by role name');
        }
    }

    #[Test]
    public function a_protected_key_cannot_be_added_to_a_role_with_active_campus_grants_by_insert_or_update(): void
    {
        $school = $this->createSchool();
        $campus = $this->createCampus($school);
        $this->assertNull($this->insert($school, $this->createMembership($this->createUser(), $school), 'cashier', $campus->id));
        $cashier = Role::query()->where('key', 'cashier')->where('scope', Role::SCOPE_SCHOOL)->value('id');
        // Catalogue writes run with NO tenant context (migrations, the seeder):
        // the guard must still see the grant (it bypasses RLS or refuses).
        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, '']);

        // In this test's own (rolled-back) transaction, as the catalogue owner:
        // the grant above is visible, and nothing is ever committed.
        $writes = [
            'insert' => fn () => DB::table('role_capabilities')->insert(['role_id' => $cashier, 'capability_key' => 'finance.charges.manage', 'created_at' => now()]),
            'update' => fn () => DB::table('role_capabilities')->where('role_id', $cashier)->where('capability_key', 'finance.charges.view')->update(['capability_key' => 'finance.ledger.reverse']),
        ];
        foreach ($writes as $kind => $write) {
            try {
                DB::connection('pgsql')->transaction(fn () => LocalCatalogueFixtures::asOwner($write));
                $this->fail("a protected key was added to a Campus-granted role by {$kind}");
            } catch (QueryException $e) {
                $this->assertStringContainsString('cannot be added to a role with active Campus-scoped grants', $e->getMessage(), $kind);
            }
        }
    }

    #[Test]
    public function the_catalogue_guard_fails_closed_for_a_writer_that_does_not_bypass_rls(): void
    {
        $row = DB::connection('pgsql_admin')->selectOne("select p.prosrc, t.tgtype from pg_proc p join pg_trigger t on t.tgfoid = p.oid where p.proname = 'role_capabilities_campus_finance_guard'");
        $this->assertStringContainsString('rolsuper OR rolbypassrls', (string) $row->prosrc, 'an RLS-bound writer would see no grant: it is refused, never waved through');
        $this->assertSame(4 | 16, ((int) $row->tgtype) & (4 | 16), 'BEFORE INSERT OR UPDATE');
    }
}

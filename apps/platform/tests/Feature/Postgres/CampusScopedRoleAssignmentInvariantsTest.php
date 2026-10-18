<?php

namespace Tests\Feature\Postgres;

use App\Models\Role;
use App\Models\School;
use App\Models\SchoolMembership;
use App\Support\Tenancy\TenantRls;
use App\Support\Testing\LocalCatalogueFixtures;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * ORG.7A (ADR 0076 §11.2, §11.4-§11.6, §33; CLAUDE.md rules 18, 28, 70): the
 * Campus scope of School role assignments, proven through raw PostgreSQL on
 * the RUNTIME role -- the database refuses every invalid combination itself.
 */
class CampusScopedRoleAssignmentInvariantsTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function setSchool(string $schoolId): void
    {
        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolId]);
    }

    /**
     * One fixture INSERT below the administrative boundary (no assigning
     * User; the scope, history and uniqueness rules still apply), in a
     * savepoint. Returns the database error message, or null when accepted.
     */
    private function insert(School $school, SchoolMembership $membership, string $roleKey, ?string $campusId): ?string
    {
        $this->setSchool($school->id);
        $roleId = Role::query()->where('key', $roleKey)->value('id');
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
    public function the_scope_column_is_nullable_and_every_pre_existing_shape_keeps_null(): void
    {
        $column = DB::selectOne("select is_nullable from information_schema.columns where table_name = 'membership_role_assignments' and column_name = 'campus_id'");
        $this->assertSame('YES', $column->is_nullable);

        [, $school] = $this->createSchoolAdmin('school_admin');
        $this->assertSame(0, DB::table('membership_role_assignments')->where('school_id', $school->id)->whereNotNull('campus_id')->count());
    }

    #[Test]
    public function a_campus_of_another_school_cannot_be_attached(): void
    {
        $a = $this->createSchool();
        $b = $this->createSchool();
        $campusB = $this->createCampus($b);
        $membership = $this->createMembership($this->createUser(), $a);

        $error = $this->insert($a, $membership, 'front_office', $campusB->id);
        $this->assertNotNull($error);
        $this->assertStringContainsString('membership_role_assignments', $error);
    }

    #[Test]
    public function only_an_active_campus_and_a_non_administrative_school_role_can_be_scoped(): void
    {
        $school = $this->createSchool();
        $campus = $this->createCampus($school);
        $inactive = $this->createCampus($school, ['status' => 'inactive']);
        $membership = $this->createMembership($this->createUser(), $school);

        $this->assertNull($this->insert($school, $membership, 'front_office', $campus->id), 'an operational role on an active Campus');
        $this->assertNull($this->insert($school, $membership, 'principal', $campus->id), 'principal holds only view access keys');
        $this->assertStringContainsString('access administration', (string) $this->insert($school, $membership, 'school_admin', $campus->id));
        $this->assertStringContainsString('active Campus', (string) $this->insert($school, $membership, 'stores_officer', $inactive->id));
        $this->assertNotNull($this->insert($school, $membership, 'guardian', $campus->id), 'a Guardian grant is never Campus-scoped');
        $this->assertStringContainsString('scope=school', (string) $this->insert($school, $membership, 'platform_auditor', $campus->id));
    }

    #[Test]
    public function one_active_grant_per_membership_role_and_scope(): void
    {
        $school = $this->createSchool();
        $a = $this->createCampus($school);
        $b = $this->createCampus($school);
        $membership = $this->createMembership($this->createUser(), $school);

        $this->assertNull($this->insert($school, $membership, 'front_office', null));
        $this->assertNull($this->insert($school, $membership, 'front_office', $a->id), 'whole School and one Campus coexist');
        $this->assertNull($this->insert($school, $membership, 'front_office', $b->id), 'two Campuses are two grants');
        $this->assertStringContainsString('membership_role_assignments_one_active_campus', (string) $this->insert($school, $membership, 'front_office', $a->id));
        $this->assertStringContainsString('membership_role_assignments_one_active', (string) $this->insert($school, $membership, 'front_office', null));
    }

    #[Test]
    public function the_campus_of_a_grant_is_immutable_and_revocation_keeps_history(): void
    {
        $school = $this->createSchool();
        $a = $this->createCampus($school);
        $b = $this->createCampus($school);
        $grant = $this->assignSchoolRole($this->createMembership($this->createUser(), $school), 'front_office', $a->id);
        $this->setSchool($school->id);

        $rejected = false;
        try {
            DB::connection('pgsql')->transaction(fn () => DB::connection('pgsql')->table('membership_role_assignments')->where('id', $grant->id)->update(['campus_id' => $b->id]));
        } catch (QueryException $e) {
            $rejected = str_contains($e->getMessage(), 'the only permitted change is revocation');
        }
        $this->assertTrue($rejected, 'the scope cannot be moved to another Campus');

        DB::connection('pgsql')->table('membership_role_assignments')->where('id', $grant->id)->update(['revoked_at' => now(), 'revocation_reason' => 'revoked']);
        $this->assertSame($a->id, DB::connection('pgsql')->table('membership_role_assignments')->where('id', $grant->id)->value('campus_id'), 'history keeps its Campus');
        $this->assertNull($this->insert($school, $grant->membership, 'front_office', $a->id), 'a re-grant is a new row');
    }

    #[Test]
    public function a_campus_with_grant_history_cannot_be_deleted_and_the_runtime_role_cannot_delete_grants(): void
    {
        $school = $this->createSchool();
        $campus = $this->createCampus($school);
        $grant = $this->assignSchoolRole($this->createMembership($this->createUser(), $school), 'front_office', $campus->id);

        $definition = DB::selectOne("select pg_get_constraintdef(oid) as d from pg_constraint where conname = 'membership_role_assignments_campus_school_foreign'")->d;
        $this->assertSame('FOREIGN KEY (campus_id, school_id) REFERENCES campuses(id, school_id) ON DELETE RESTRICT', $definition, 'grant history outlives a Campus');
        $this->assertNotNull($campus);

        $this->setSchool($school->id);
        $refused = false;
        try {
            DB::connection('pgsql')->transaction(fn () => DB::connection('pgsql')->table('membership_role_assignments')->where('id', $grant->id)->delete());
        } catch (QueryException) {
            $refused = true;
        }
        $this->assertTrue($refused);
    }

    #[Test]
    public function rls_still_hides_another_schools_scoped_grants(): void
    {
        $a = $this->createSchool();
        $b = $this->createSchool();
        $this->assignSchoolRole($this->createMembership($this->createUser(), $b), 'front_office', $this->createCampus($b)->id);

        $this->setSchool($a->id);
        $this->assertSame(0, (int) DB::connection('pgsql')->selectOne('select count(*) as c from membership_role_assignments where school_id = ?', [$b->id])->c);
    }

    #[Test]
    public function the_grantor_trigger_counts_only_the_issuers_whole_school_grants(): void
    {
        $school = $this->createSchool();
        $campus = $this->createCampus($school);
        $issuer = $this->createUser();
        $issuerMembership = $this->createMembership($issuer, $school);
        // Grant authority whole-School; the Visitor visit capabilities ONLY through a Campus grant.
        LocalCatalogueFixtures::grantRole($issuerMembership, $this->createFixtureRole(['school.roles.manage', 'school.members.view']));
        $this->assignSchoolRole($issuerMembership, 'front_office', $campus->id);
        $target = $this->createMembership($this->createUser(), $school);
        $role = $this->createFixtureRole(['visitor.visits.view']);

        $this->setSchool($school->id);
        $error = null;
        try {
            DB::connection('pgsql')->transaction(fn () => DB::connection('pgsql')->table('membership_role_assignments')->insert([
                'id' => (string) Str::uuid7(), 'school_id' => $school->id, 'school_membership_id' => $target->id,
                'role_id' => $role->id, 'assigned_by_user_id' => $issuer->id,
                'assigned_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]));
        } catch (QueryException $e) {
            $error = $e->getMessage();
        }

        $this->assertStringContainsString('does not hold or cover every capability', (string) $error);
    }
}

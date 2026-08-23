<?php

namespace Tests\Feature\Authorization;

use App\Models\MembershipRoleAssignment;
use App\Models\PlatformRoleAssignment;
use App\Models\Role;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Proves the database-level triggers (not just application code) that
 * guarantee "a School administrator must not be capable of assigning
 * platform.* capabilities" (section 18/51) -- see the
 * platform_role_assignments / membership_role_assignments migrations.
 *
 * Each expected-failure write runs inside its own DB::transaction() to
 * create a Postgres SAVEPOINT: this whole suite already runs inside
 * PHPUnit's own outer transaction (DatabaseTransactions, ADR 0024), and
 * without a savepoint a failed statement would abort that ENTIRE outer
 * transaction, breaking every subsequent statement in the test
 * (including TenantContext's own cleanup) with a confusing "transaction
 * is aborted" error instead of the real one. Laravel's DB::transaction()
 * automatically rolls back to the savepoint on failure, so the
 * connection is healthy again immediately afterward -- exactly what
 * real application code wrapping a validated write should do too.
 */
class RoleScopeTriggerTest extends TestCase
{
    use CreatesTenancyFixtures;

    #[Test]
    public function platform_role_assignments_rejects_a_school_scoped_role_at_the_database_level(): void
    {
        $user = $this->createUser();
        $schoolRole = Role::query()->where('key', 'school_admin')->where('scope', 'school')->firstOrFail();

        try {
            DB::transaction(function () use ($user, $schoolRole): void {
                PlatformRoleAssignment::query()->create([
                    'user_id' => $user->id,
                    'role_id' => $schoolRole->id,
                ]);
            });
            $this->fail('Expected a QueryException from the platform-role-scope trigger.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('scope=platform', $e->getMessage());
        }
    }

    #[Test]
    public function platform_role_assignments_accepts_a_platform_scoped_role(): void
    {
        $user = $this->createUser();
        $platformRole = Role::query()->where('key', 'platform_super_admin')->firstOrFail();

        $assignment = PlatformRoleAssignment::query()->create([
            'user_id' => $user->id,
            'role_id' => $platformRole->id,
        ]);

        $this->assertNotNull($assignment->id);
    }

    #[Test]
    public function membership_role_assignments_rejects_a_platform_scoped_role_at_the_database_level(): void
    {
        $user = $this->createUser();
        $school = $this->createSchool();
        $membership = $this->createMembership($user, $school);
        $platformRole = Role::query()->where('key', 'platform_super_admin')->firstOrFail();

        try {
            app(TenantContext::class)->withSchool($school, function () use ($school, $membership, $platformRole): void {
                DB::transaction(function () use ($school, $membership, $platformRole): void {
                    MembershipRoleAssignment::query()->create([
                        'school_id' => $school->id,
                        'school_membership_id' => $membership->id,
                        'role_id' => $platformRole->id,
                    ]);
                });
            });
            $this->fail('Expected a QueryException from the membership-role-scope trigger.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('scope=school', $e->getMessage());
        }
    }

    #[Test]
    public function membership_role_assignments_rejects_a_school_id_mismatch_via_composite_foreign_key(): void
    {
        // Section 34: a role-assignment row whose school_id doesn't
        // match its own school_membership_id's actual school_id must
        // be rejected by the database, not just application logic.
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $userA = $this->createUser();
        $membershipInA = $this->createMembership($userA, $schoolA);
        $role = Role::query()->where('key', 'school_admin')->firstOrFail();

        try {
            app(TenantContext::class)->withSchool($schoolB, function () use ($schoolB, $membershipInA, $role): void {
                DB::transaction(function () use ($schoolB, $membershipInA, $role): void {
                    // school_id says School B, but school_membership_id
                    // belongs to School A -- the composite FK must
                    // reject this.
                    MembershipRoleAssignment::query()->create([
                        'school_id' => $schoolB->id,
                        'school_membership_id' => $membershipInA->id,
                        'role_id' => $role->id,
                    ]);
                });
            });
            $this->fail('Expected a QueryException from the composite foreign key.');
        } catch (QueryException $e) {
            $this->assertNotNull($e);
        }
    }
}

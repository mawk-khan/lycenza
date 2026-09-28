<?php

namespace Tests\Feature\Identity\Staff;

use App\Domain\HR\Infrastructure\Employee;
use App\Domain\Identity\Application\Staff\StaffAccessService;
use App\Domain\Identity\Application\Staff\StaffAccountException;
use App\Http\Middleware\RequireSchoolContext;
use App\Models\MembershipRoleAssignment;
use App\Models\School;
use App\Models\SchoolAuditEvent;
use App\Models\SchoolMembership;
use App\Models\User;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Authorization\SchoolAdministrators;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Concerns\FakesEmail;
use Tests\Feature\Auth\Mfa\Concerns\CreatesMfaFixtures;
use Tests\Feature\Platform\Groups\GroupTestHelpers;
use Tests\TestCase;

/**
 * Phase 0O.12B (ADR 0059 owner amendment): off-boarding, reactivation and
 * School role grant/revoke. Off-boarding suspends ONE School's access and
 * revokes its role grants (kept as history) -- the User, their password,
 * MFA, sessions elsewhere, other Schools, platform/Group authority and any
 * Employee stay untouched; the School's last qualifying administrator can
 * never be removed; nobody administers their own access here.
 */
class StaffAccessTest extends TestCase
{
    use CreatesMfaFixtures, CreatesTenancyFixtures, FakesEmail, GroupTestHelpers, StaffAccountTestHelpers;

    /** @return array{0: User, 1: School, 2: User, 3: SchoolMembership} [admin, school, staff, staff membership] */
    private function world(string $staffRole = 'principal'): array
    {
        [$admin, $school] = $this->staffAdmin();
        [$staff, $membership] = $this->staffMember($school, $staffRole);

        return [$admin, $school, $staff, $membership];
    }

    private function audit(School $school, string $type): array
    {
        return app(TenantContext::class)->withSchool($school, fn () => SchoolAuditEvent::query()
            ->where('event_type', $type)->orderBy('occurred_at')->orderBy('id')->pluck('metadata')->all());
    }

    #[Test]
    public function off_boarding_suspends_this_schools_access_only_and_revokes_its_roles_as_history(): void
    {
        [$admin, $school, $staff, $membership] = $this->world();
        $elsewhere = $this->createSchool();
        $this->assignSchoolRole($other = $this->createMembership($staff, $elsewhere), 'principal');
        $employee = app(TenantContext::class)->withSchool($school, fn () => Employee::factory()->for($school)->create(['user_id' => $staff->id]));
        $version = $staff->fresh()->credential_version;
        $token = $staff->createToken('device')->plainTextToken;

        $this->staffPost($admin, $school, "/members/{$membership->id}/suspend")->assertOk()->assertJson(['suspended' => true]);

        $this->assertSame('suspended', $membership->fresh()->status);
        $this->assertSame([], $this->activeRoles($membership));
        $this->assertSame([['role' => 'principal', 'revoked' => true, 'reason' => 'membership_suspended']], $this->grantHistory($membership));
        $this->assertSame([], app(CapabilityResolver::class)->schoolCapabilities($staff, $school));

        // Nothing else about the person changes.
        $fresh = $staff->fresh();
        $this->assertFalse($fresh->isDisabled());
        $this->assertSame($version, $fresh->credential_version, 'No global session revocation.');
        $this->assertTrue(Hash::check(self::STAFF_PASSWORD, $fresh->getAuthPassword()));
        $this->assertSame('active', $other->fresh()->status);
        $this->assertSame(['principal'], $this->activeRoles($other));
        $this->assertSame($staff->id, app(TenantContext::class)->withSchool($school, fn () => Employee::query()->findOrFail($employee->id)->user_id), 'Employee untouched.');
        $this->assertSame(1, DB::table('personal_access_tokens')->where('tokenable_id', $staff->id)->count(), 'Human tokens are not revoked globally.');

        // The old token no longer reaches the suspended School, still reaches the other.
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', "Bearer {$token}")->getJson("/api/v1/schools/{$school->id}/context")->assertNotFound();
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', "Bearer {$token}")->getJson("/api/v1/schools/{$elsewhere->id}/context")->assertOk();
        $this->flushHeaders();

        $this->assertEquals([['schoolMembershipId' => $membership->id, 'userId' => $staff->id, 'revokedRoleKeys' => ['principal']]], $this->audit($school, StaffAccessService::SUSPENDED));
        $this->assertEquals([['schoolMembershipId' => $membership->id, 'roleKey' => 'principal', 'reason' => 'membership_suspended']], $this->audit($school, StaffAccessService::ROLE_REVOKED));
    }

    #[Test]
    public function a_signed_in_session_loses_the_suspended_school_on_its_next_request_and_keeps_the_other(): void
    {
        [$admin, $school, $staff, $membership] = $this->world();
        $elsewhere = $this->createSchool();
        $this->assignSchoolRole($this->createMembership($staff, $elsewhere), 'principal');

        $this->enterSchool($staff, $school);
        $this->get('http://localhost/app/settings')->assertOk();

        $this->staffPost($admin, $school, "/members/{$membership->id}/suspend")->assertOk();

        $this->actingAs($staff)->withSession([RequireSchoolContext::SESSION_KEY => $school->id]);
        $this->get('http://localhost/app/settings')->assertRedirect(route('app.dashboard'));
        $this->assertNull(session(RequireSchoolContext::SESSION_KEY), 'The stale selection is cleared.');

        $this->actingAs($staff)->withSession([RequireSchoolContext::SESSION_KEY => $elsewhere->id]);
        $this->get('http://localhost/app/settings')->assertOk();
    }

    #[Test]
    public function reactivation_grants_only_newly_chosen_roles_as_new_rows(): void
    {
        [$admin, $school, $staff, $membership] = $this->world('school_admin');
        $this->staffPost($admin, $school, "/members/{$membership->id}/suspend")->assertOk();

        $this->staffPost($admin, $school, "/members/{$membership->id}/reactivate", ['roles' => []])->assertStatus(422);
        $this->staffPost($admin, $school, "/members/{$membership->id}/reactivate", ['roles' => ['principal']])->assertOk();

        $this->assertSame('active', $membership->fresh()->status);
        $this->assertSame(['principal'], $this->activeRoles($membership), 'The revoked school_admin grant never returns by itself.');
        $this->assertSame([
            ['role' => 'school_admin', 'revoked' => true, 'reason' => 'membership_suspended'],
            ['role' => 'principal', 'revoked' => false, 'reason' => null],
        ], $this->grantHistory($membership));
        $this->assertEquals([['schoolMembershipId' => $membership->id, 'userId' => $staff->id, 'roleKeys' => ['principal']]], $this->audit($school, StaffAccessService::REACTIVATED));

        $this->staffPost($admin, $school, "/members/{$membership->id}/reactivate", ['roles' => ['principal']])->assertStatus(422)
            ->assertJsonPath('error.message', StaffAccountException::MESSAGES['not_suspended']);
    }

    #[Test]
    public function role_grant_and_revoke_keep_history_and_a_regrant_is_a_new_row(): void
    {
        [$admin, $school, , $membership] = $this->world();

        $this->staffPost($admin, $school, "/members/{$membership->id}/roles", ['role' => 'principal'])->assertStatus(422)
            ->assertJsonPath('error.message', StaffAccountException::MESSAGES['role_already_granted']);
        $this->staffPost($admin, $school, "/members/{$membership->id}/roles/principal/revoke")->assertOk();
        $this->staffPost($admin, $school, "/members/{$membership->id}/roles/principal/revoke")->assertStatus(422)
            ->assertJsonPath('error.message', StaffAccountException::MESSAGES['role_not_granted']);
        $this->staffPost($admin, $school, "/members/{$membership->id}/roles", ['role' => 'principal'])->assertOk();

        $this->assertSame([
            ['role' => 'principal', 'revoked' => true, 'reason' => 'revoked'],
            ['role' => 'principal', 'revoked' => false, 'reason' => null],
        ], $this->grantHistory($membership));
    }

    #[Test]
    public function nobody_administers_their_own_access_and_the_last_administrator_stays(): void
    {
        [$admin, $school, $adminMembership] = $this->staffAdmin();

        $this->staffPost($admin, $school, "/members/{$adminMembership->id}/suspend")->assertStatus(422)
            ->assertJsonPath('error.message', StaffAccountException::MESSAGES['self_administration']);
        $this->staffPost($admin, $school, "/members/{$adminMembership->id}/roles/school_admin/revoke")->assertStatus(422)
            ->assertJsonPath('error.message', StaffAccountException::MESSAGES['self_administration']);

        // A second administrator exists: removing them is fine (the actor remains).
        [, $secondMembership] = $this->staffMember($school, 'school_admin');
        $this->staffPost($admin, $school, "/members/{$secondMembership->id}/roles/school_admin/revoke")->assertOk();
        $this->assertSame(['school_admin'], $this->activeRoles($adminMembership));
    }

    #[Test]
    public function the_last_qualifying_administrator_can_never_be_removed(): void
    {
        [$admin, $school, $adminMembership] = $this->staffAdmin();
        // An actor holding the administrator capabilities but not QUALIFYING
        // (no working credential, ADR 0059 section 7.3) -- so removing the
        // one real administrator would leave none.
        $actor = User::query()->create(['name' => 'No Credential', 'email' => 'nocred@example.test', 'password' => null]);
        $this->assignSchoolRole($this->createMembership($actor, $school), 'school_admin');
        $this->assertSame([$admin->id], app(SchoolAdministrators::class)->qualifying($school)->pluck('id')->all());

        foreach ([
            fn () => app(StaffAccessService::class)->suspend($school, $actor, $adminMembership->id),
            fn () => app(StaffAccessService::class)->revokeRole($school, $actor, $adminMembership->id, 'school_admin'),
        ] as $operation) {
            try {
                $operation();
                $this->fail('The last qualifying administrator was removed.');
            } catch (StaffAccountException $e) {
                $this->assertSame('last_administrator', $e->outcome);
            }
        }

        $this->assertSame('active', $adminMembership->fresh()->status);
        $this->assertSame(['school_admin'], $this->activeRoles($adminMembership), 'Rolled back: nothing changed.');
    }

    #[Test]
    public function only_the_right_capabilities_with_a_fresh_code_manage_access(): void
    {
        [$admin, $school, , $membership] = $this->world();
        [$principal] = $this->staffMember($school, 'principal');
        $this->withMfaCodes($principal);

        $this->staffPost($principal, $school, "/members/{$membership->id}/suspend")->assertForbidden();
        $this->staffPost($principal, $school, "/members/{$membership->id}/roles", ['role' => 'principal'])->assertForbidden();
        $this->staffPost($principal, $school, "/members/{$membership->id}/roles/principal/revoke")->assertForbidden();
        $this->staffPost($admin, $school, "/members/{$membership->id}/suspend", ['mfa_code' => '000000'], withCode: false)->assertStatus(422);
        $this->assertSame('active', $membership->fresh()->status);

        // A Guardian's membership is not a staff account.
        $guardian = $this->createUser();
        $guardianMembership = $this->createMembership($guardian, $school);
        $this->staffPost($admin, $school, "/members/{$guardianMembership->id}/suspend")->assertStatus(422)
            ->assertJsonPath('error.message', StaffAccountException::MESSAGES['not_staff']);

        // Another School's membership is not found.
        [$otherAdmin, $other] = $this->staffAdmin();
        $this->staffPost($otherAdmin, $other, "/members/{$membership->id}/suspend")->assertStatus(422)
            ->assertJsonPath('error.message', StaffAccountException::MESSAGES['not_found']);
        $this->assertSame('active', $membership->fresh()->status);
    }

    #[Test]
    public function a_suspended_school_refuses_access_changes(): void
    {
        [$admin, $school, , $membership] = $this->world();
        $school->forceFill(['status' => 'suspended'])->save();

        // School context itself refuses a suspended School.
        $this->staffPost($admin, $school, "/members/{$membership->id}/suspend")->assertStatus(409);

        try {
            app(StaffAccessService::class)->suspend($school, $admin, $membership->id);
            $this->fail('Expected refusal');
        } catch (StaffAccountException $e) {
            $this->assertSame('school_not_operational', $e->outcome);
        }
        $this->assertSame('active', $membership->fresh()->status);
    }

    #[Test]
    public function the_runtime_role_can_never_delete_a_role_grant(): void
    {
        [, $school, , $membership] = $this->world();

        $this->expectExceptionMessage('permission denied for table membership_role_assignments');
        app(TenantContext::class)->withSchool($school, fn () => MembershipRoleAssignment::query()->where('school_membership_id', $membership->id)->delete());
    }
}

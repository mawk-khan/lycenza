<?php

namespace Tests\Feature\Identity\Staff;

use App\Domain\HR\Infrastructure\Employee;
use App\Domain\Identity\Application\Staff\StaffInvitationAcceptanceService;
use App\Domain\Identity\Infrastructure\StaffAccountInvitation;
use App\Http\Controllers\Identity\StaffInvitationAcceptanceController;
use App\Models\PlatformAuditEvent;
use App\Models\School;
use App\Models\SchoolAuditEvent;
use App\Models\SchoolMembership;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Concerns\FakesEmail;
use Tests\Feature\Auth\Mfa\Concerns\CreatesMfaFixtures;
use Tests\TestCase;

/**
 * Phase 0O.12B (ADR 0059 sections 6.3, 13, 14, 15): accepting a staff account
 * invitation. A new person gets one User (their own password through the
 * credential service, no auto-login) and an ACTIVE membership with exactly
 * the invited roles; an existing User must be signed in as that User and
 * keeps their password, MFA and other Schools. Every credential failure,
 * and every ineligible identity, is the same "invalid" answer. No Employee
 * is ever created.
 */
class StaffInvitationAcceptanceTest extends TestCase
{
    use CreatesMfaFixtures, CreatesTenancyFixtures, FakesEmail, StaffAccountTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeEmail();
    }

    /** @return array{0: User, 1: School, 2: string, 3: string} */
    private function invited(string $email = 'new.person@example.test', array $roles = ['principal']): array
    {
        [$admin, $school] = $this->staffAdmin();
        $this->inviteStaff($admin, $school, $email, $roles)->assertCreated();
        [$selector, $secret] = $this->staffLink();
        auth()->logout();
        $this->flushSession();

        return [$admin, $school, $selector, $secret];
    }

    #[Test]
    public function the_get_page_changes_nothing_and_never_sees_the_secret(): void
    {
        [, $school, $selector] = $this->invited();

        $this->get("http://localhost/invitations/{$school->id}/staff/{$selector}")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Invitations/StaffAccept')->where('selector', $selector)
                ->where('schoolName', $school->name)->missing('secret')->missing('email'));
        $this->assertSame('pending', app(TenantContext::class)->withSchool($school, fn () => StaffAccountInvitation::query()->sole()->status));
    }

    #[Test]
    public function a_new_person_gets_one_user_an_active_membership_and_exactly_the_invited_roles(): void
    {
        [$admin, $school, $selector, $secret] = $this->invited('New.Person@example.test', ['principal']);
        $employees = app(TenantContext::class)->withSchool($school, fn () => Employee::query()->count());

        $this->acceptStaff($school, $selector, $secret, ['name' => 'Nia Staff'])
            ->assertRedirect('http://localhost:8000/login');
        $this->assertGuest();

        $user = User::query()->where('email', 'new.person@example.test')->sole();
        $this->assertSame('Nia Staff', $user->name);
        $this->assertTrue(Hash::check(self::STAFF_PASSWORD, $user->getAuthPassword()));
        $this->assertNotNull($user->email_verified_at);
        $this->assertSame(2, $user->credential_version, 'The first password moves the credential version on.');

        $membership = SchoolMembership::query()->where('user_id', $user->id)->sole();
        $this->assertSame([$school->id, 'active'], [$membership->school_id, $membership->status]);
        $this->assertSame(['principal'], $this->activeRoles($membership));
        $this->assertSame($employees, app(TenantContext::class)->withSchool($school, fn () => Employee::query()->count()), 'User is not Employee.');

        $events = app(TenantContext::class)->withSchool($school, fn () => SchoolAuditEvent::query()
            ->whereIn('event_type', [StaffInvitationAcceptanceService::ACTIVATED, StaffInvitationAcceptanceService::ROLE_ASSIGNED])
            ->orderBy('event_type')->get());
        $this->assertEqualsCanonicalizing([StaffInvitationAcceptanceService::ACTIVATED, StaffInvitationAcceptanceService::ROLE_ASSIGNED], $events->pluck('event_type')->all());
        $this->assertStringNotContainsString('example.test', $events->toJson());
        $this->assertStringNotContainsString($secret, $events->toJson());

        // Single use.
        $this->acceptStaff($school, $selector, $secret)->assertSessionHasErrors(['secret' => StaffInvitationAcceptanceController::INVALID_LINK]);

        // The new account signs in normally.
        $this->post('http://localhost/login', ['email' => 'new.person@example.test', 'password' => self::STAFF_PASSWORD])->assertRedirect();
        $this->assertAuthenticatedAs($user);
    }

    #[Test]
    public function the_password_policy_applies_and_a_rejected_attempt_consumes_nothing(): void
    {
        [, $school, $selector, $secret] = $this->invited();

        $this->acceptStaff($school, $selector, $secret, ['password' => 'short', 'password_confirmation' => 'short'])->assertSessionHasErrors('password');
        $this->acceptStaff($school, $selector, $secret, ['name' => ''])->assertSessionHasErrors('name');
        $this->assertSame(0, User::query()->where('email', 'new.person@example.test')->count());

        $this->acceptStaff($school, $selector, $secret)->assertRedirect();
        $this->assertSame(1, User::query()->where('email', 'new.person@example.test')->count());
    }

    #[Test]
    public function every_credential_failure_is_the_same_invalid_answer(): void
    {
        [, $school, $selector, $secret] = $this->invited();
        $other = $this->createSchool();
        $invalid = ['secret' => StaffInvitationAcceptanceController::INVALID_LINK];

        $this->acceptStaff($school, $selector, str_repeat('A', 43))->assertSessionHasErrors($invalid);
        $this->acceptStaff($school, str_repeat('B', 22), $secret)->assertSessionHasErrors($invalid);
        $this->acceptStaff($other, $selector, $secret)->assertSessionHasErrors($invalid);

        $this->travel(8)->days();
        $this->acceptStaff($school, $selector, $secret)->assertSessionHasErrors($invalid);
        $this->assertSame(0, User::query()->where('email', 'new.person@example.test')->count());
    }

    #[Test]
    public function an_existing_user_must_be_signed_in_as_that_user_and_keeps_everything_else(): void
    {
        $existing = $this->createUser(['email' => 'teacher@example.test', 'password' => Hash::make('existing-password-99')]);
        $elsewhere = $this->createSchool();
        $this->assignSchoolRole($this->createMembership($existing, $elsewhere), 'principal');
        $this->enrollActiveMfaFactor($existing);
        [, $school, $selector, $secret] = $this->invited('teacher@example.test', ['principal']);
        $version = $existing->fresh()->credential_version;

        // Not signed in: the holder of the link learns only that they must sign in.
        $this->acceptStaff($school, $selector, $secret, ['mode' => 'new'])
            ->assertSessionHasErrors(['secret' => StaffInvitationAcceptanceController::SIGN_IN_REQUIRED]);

        // Signed in as someone else: the same.
        $this->actingAs($this->createUser());
        $this->acceptStaff($school, $selector, $secret, ['mode' => 'existing'])
            ->assertSessionHasErrors(['secret' => StaffInvitationAcceptanceController::SIGN_IN_REQUIRED]);

        $this->actingAs($existing);
        $this->acceptStaff($school, $selector, $secret, ['mode' => 'existing'])->assertRedirect('/app');

        $fresh = $existing->fresh();
        $this->assertTrue(Hash::check('existing-password-99', $fresh->getAuthPassword()), 'Password untouched.');
        $this->assertSame($version, $fresh->credential_version, 'No credential change.');
        $this->assertSame(1, DB::table('user_mfa_factors')->where('user_id', $existing->id)->where('status', 'active')->count());
        $this->assertSame(1, User::query()->where('email', 'teacher@example.test')->count(), 'No duplicate User.');
        $this->assertSame(['active', 'active'], SchoolMembership::query()->where('user_id', $existing->id)->orderBy('school_id')->pluck('status')->all());
        $this->assertSame(['principal'], $this->activeRoles(SchoolMembership::query()->where('user_id', $existing->id)->where('school_id', $elsewhere->id)->sole()));
        $this->assertSame(1, app(TenantContext::class)->withSchool($school, fn () => SchoolAuditEvent::query()->where('event_type', StaffInvitationAcceptanceService::LINKED_EXISTING)->count()));
    }

    #[Test]
    public function ineligible_existing_identities_get_the_generic_invalid_answer(): void
    {
        $invalid = ['secret' => StaffInvitationAcceptanceController::INVALID_LINK];

        // A disabled account.
        $disabled = $this->createUser(['email' => 'disabled@example.test']);
        [, $school, $selector, $secret] = $this->invited('disabled@example.test');
        $disabled->forceFill(['is_disabled' => true, 'disabled_at' => now()])->save();
        $this->actingAs($disabled);
        $this->acceptStaff($school, $selector, $secret, ['mode' => 'existing'])->assertSessionHasErrors($invalid);

        // A platform operator: refused, recorded on the platform side only.
        $root = $this->createPlatformRoot(['email' => 'operator@example.test']);
        [, $school2, $selector2, $secret2] = $this->invited('operator@example.test');
        $this->actingAs($root);
        $this->acceptStaff($school2, $selector2, $secret2, ['mode' => 'existing'])->assertSessionHasErrors($invalid);
        $refusal = PlatformAuditEvent::query()->where('event_type', StaffInvitationAcceptanceService::REFUSED_PROTECTED)->sole();
        $this->assertEquals(['school_id' => $school2->id, 'invitation_id' => app(TenantContext::class)->withSchool($school2, fn () => StaffAccountInvitation::query()->sole()->id), 'outcome_code' => 'platform_role_holder'], $refusal->metadata);
        $this->assertSame(0, app(TenantContext::class)->withSchool($school2, fn () => SchoolAuditEvent::query()->where('event_type', StaffInvitationAcceptanceService::REFUSED_PROTECTED)->count()));

        // A membership of that School that appeared after issue (any status) --
        // never reactivated or duplicated by an invitation.
        [, $school3, $selector3, $secret3] = $this->invited('late@example.test');
        $late = $this->createUser(['email' => 'late@example.test']);
        $this->createMembership($late, $school3, 'suspended');
        $this->actingAs($late);
        $this->acceptStaff($school3, $selector3, $secret3, ['mode' => 'existing'])->assertSessionHasErrors($invalid);
        $this->assertSame(['suspended'], SchoolMembership::query()->where('user_id', $late->id)->pluck('status')->all());

        foreach ([$disabled, $root] as $user) {
            $this->assertSame(0, SchoolMembership::query()->where('user_id', $user->id)->count());
        }
    }

    #[Test]
    public function an_issuer_who_lost_authority_or_a_suspended_school_voids_the_invitation(): void
    {
        [$admin, $school, $selector, $secret] = $this->invited();
        $invalid = ['secret' => StaffInvitationAcceptanceController::INVALID_LINK];

        $admin->forceFill(['is_disabled' => true, 'disabled_at' => now()])->save();
        $this->acceptStaff($school, $selector, $secret)->assertSessionHasErrors($invalid);
        $admin->forceFill(['is_disabled' => false, 'disabled_at' => null])->save();

        $school->forceFill(['status' => 'suspended'])->save();
        $this->acceptStaff($school, $selector, $secret)->assertSessionHasErrors($invalid);
        $this->assertSame('pending', app(TenantContext::class)->withSchool($school, fn () => StaffAccountInvitation::query()->sole()->status), 'Left pending, not revoked.');

        $school->forceFill(['status' => 'active'])->save();
        $this->acceptStaff($school, $selector, $secret)->assertRedirect();
        $this->assertTrue(Auth::guest());
    }
}

<?php

namespace Tests\Feature\Identity\Staff;

use App\Domain\Identity\Application\Staff\StaffAccountException;
use App\Domain\Identity\Application\Staff\StaffInvitationService;
use App\Domain\Identity\Infrastructure\StaffAccountInvitation;
use App\Models\Capability;
use App\Models\EmailMessage;
use App\Models\Role;
use App\Models\SchoolAuditEvent;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Concerns\FakesEmail;
use Tests\Feature\Auth\Mfa\Concerns\CreatesMfaFixtures;
use Tests\Feature\Platform\Groups\GroupTestHelpers;
use Tests\TestCase;

/**
 * Phase 0O.12B (ADR 0059 sections 6, 9.2, 14, 15, 17): issuing, resending and
 * revoking a School's staff account invitation over HTTP. Issuing needs
 * `school.members.manage` + `school.roles.manage` + a fresh MFA code; the
 * School learns only its OWN facts; the email carries the secret only in
 * the link's fragment; audit carries ids and role keys only.
 */
class StaffInvitationTest extends TestCase
{
    use CreatesMfaFixtures, CreatesTenancyFixtures, FakesEmail, GroupTestHelpers, StaffAccountTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeEmail();
    }

    #[Test]
    public function a_school_admin_invites_staff_with_a_fresh_code_and_the_secret_only_in_the_fragment(): void
    {
        [$admin, $school] = $this->staffAdmin();

        $this->inviteStaff($admin, $school, 'New.Teacher@Example.test')->assertCreated();

        $invitation = app(TenantContext::class)->withSchool($school, fn () => StaffAccountInvitation::query()->sole());
        $this->assertSame('new.teacher@example.test', $invitation->destination_email);
        $this->assertSame('pending', $invitation->status);
        $this->assertEqualsWithDelta(now()->addDays(7)->timestamp, $invitation->expires_at->timestamp, 5);

        $mail = $this->lastAcceptedEmail();
        $this->assertSame('new.teacher@example.test', $mail->to);
        [$selector, $secret] = $this->staffLink($mail);
        $this->assertSame($invitation->selector, $selector);
        $this->assertSame(hash('sha256', $secret), $invitation->secret_hash, 'Only the SHA-256 of the secret is stored.');
        $this->assertStringContainsString("http://localhost:8000/invitations/{$school->id}/staff/{$selector}#", $mail->text, 'The School canonical origin, never a request Host.');
        $this->assertStringContainsString($school->name, $mail->text);

        $audit = app(TenantContext::class)->withSchool($school, fn () => SchoolAuditEvent::query()->where('event_type', StaffInvitationService::INVITED)->sole());
        $this->assertSame($admin->id, $audit->actor_user_id);
        $this->assertEquals(['invitationId' => $invitation->id, 'roleKeys' => ['principal']], $audit->metadata);
        $this->assertStringNotContainsString('example.test', json_encode($audit->metadata), 'Never an email in audit metadata.');
        $this->assertStringNotContainsString($secret, json_encode(DB::table('email_messages')->get()->map(fn ($r) => array_diff_key((array) $r, ['sealed_content' => 1]))));
    }

    #[Test]
    public function only_holders_of_both_capabilities_with_a_fresh_code_may_invite(): void
    {
        [$admin, $school] = $this->staffAdmin();
        [$principal] = $this->staffMember($school, 'principal');
        $this->withMfaCodes($principal);
        $noRole = $this->createUser();
        $this->createMembership($noRole, $school);
        $this->withMfaCodes($noRole);

        foreach ([$principal, $noRole] as $user) {
            $this->inviteStaff($user, $school, 'someone@example.test')->assertForbidden();
        }

        // The capability is checked before the code is spent.
        $this->staffPost($admin, $school, '/invitations', ['email' => 'a@example.test', 'roles' => ['principal']], withCode: false)
            ->assertStatus(422)->assertJsonPath('error.errors.mfa_code.0', 'Enter a current authentication code.');
        $this->staffPost($admin, $school, '/invitations', ['email' => 'a@example.test', 'roles' => ['principal'], 'mfa_code' => '000000'], withCode: false)
            ->assertStatus(422)->assertJsonPath('error.errors.mfa_code.0', 'That code is not valid.');

        $noFactor = $this->createUser();
        $this->assignSchoolRole($this->createMembership($noFactor, $school), 'school_admin');
        $this->staffPost($noFactor, $school, '/invitations', ['email' => 'a@example.test', 'roles' => ['principal'], 'mfa_code' => '123456'], withCode: false)
            ->assertStatus(422)->assertJsonPath('error.errors.mfa_code.0', 'This action requires multi-factor authentication. Enroll a factor under Account security first.');

        $this->assertSame(0, app(TenantContext::class)->withSchool($school, fn () => StaffAccountInvitation::query()->count()));
    }

    #[Test]
    public function platform_elevation_and_group_authority_cannot_reach_staff_accounts(): void
    {
        $school = $this->createSchool();
        $root = $this->createPlatformRoot();
        $group = $this->createGroup([$school]);
        $groupAdmin = $this->groupAdmin($group, withMfa: false);

        foreach ([$root, $groupAdmin] as $user) {
            $this->actingAs($user)->get('http://localhost/app/settings/staff')->assertRedirect(route('app.dashboard'));
            $this->actingAs($user)->postJson('http://localhost/app/settings/staff/invitations', ['email' => 'x@example.test', 'roles' => ['principal']])->assertStatus(409);
        }
        $this->assertSame(0, app(TenantContext::class)->withSchool($school, fn () => StaffAccountInvitation::query()->count()));
    }

    #[Test]
    public function roles_come_from_the_closed_school_catalog_within_the_issuers_own_capabilities(): void
    {
        [$admin, $school] = $this->staffAdmin();

        $this->inviteStaff($admin, $school, 'a@example.test', [])->assertStatus(422);
        $this->inviteStaff($admin, $school, 'a@example.test', ['platform_super_admin'])->assertStatus(422)
            ->assertJsonPath('error.message', StaffAccountException::MESSAGES['role_unknown']);
        $this->inviteStaff($admin, $school, 'a@example.test', ['group_admin'])->assertStatus(422)
            ->assertJsonPath('error.message', StaffAccountException::MESSAGES['role_unknown']);
        $this->inviteStaff($admin, $school, 'a@example.test', ['*'])->assertStatus(422);

        // A School role carrying a capability the admin does not hold is an escalation.
        $held = app(CapabilityResolver::class)->schoolCapabilities($admin, $school);
        $foreign = Capability::query()->where('namespace', 'school')->whereNotIn('key', $held)->value('key')
            ?? Capability::query()->create(['key' => 'school.test_only_capability', 'label' => 'Test only', 'namespace' => 'school'])->key;
        $role = Role::query()->create(['key' => 'test_escalation_role', 'name' => 'Escalation', 'scope' => 'school', 'is_system' => false]);
        $role->capabilities()->attach($foreign);
        $this->inviteStaff($admin, $school, 'a@example.test', ['test_escalation_role'])->assertStatus(422)
            ->assertJsonPath('error.message', StaffAccountException::MESSAGES['role_escalation']);

        $this->inviteStaff($admin, $school, 'a@example.test', ['school_admin', 'principal'])->assertCreated();
    }

    #[Test]
    public function the_school_learns_only_its_own_facts(): void
    {
        [$admin, $school] = $this->staffAdmin();
        [$member] = $this->staffMember($school, 'principal');
        $elsewhere = $this->createUser(['email' => 'elsewhere@example.test']);
        $this->createMembership($elsewhere, $this->createSchool());
        $this->createUser(['email' => 'disabled@example.test', 'is_disabled' => true, 'disabled_at' => now()]);
        $this->createPlatformRoot(['email' => 'operator@example.test']);

        $this->inviteStaff($admin, $school, $member->email)->assertStatus(422)
            ->assertJsonPath('error.message', StaffAccountException::MESSAGES['already_member']);

        $shapes = [];
        foreach (['nobody@example.test', 'elsewhere@example.test', 'disabled@example.test', 'operator@example.test'] as $email) {
            $response = $this->inviteStaff($admin, $school, $email)->assertCreated();
            $shapes[] = array_keys($response->json());
        }
        $this->assertCount(1, array_unique(array_map('json_encode', $shapes)), 'Every other identity state gets the same accepted result.');

        $this->inviteStaff($admin, $school, 'nobody@example.test')->assertStatus(422)
            ->assertJsonPath('error.message', StaffAccountException::MESSAGES['already_invited']);
    }

    #[Test]
    public function issuing_is_refused_while_critical_email_is_unavailable(): void
    {
        [$admin, $school] = $this->staffAdmin();
        config(['email.provider' => 'none']);

        $this->inviteStaff($admin, $school, 'a@example.test')->assertStatus(422)
            ->assertJsonPath('error.message', StaffAccountException::MESSAGES['email_unavailable']);
        $this->assertSame(0, app(TenantContext::class)->withSchool($school, fn () => StaffAccountInvitation::query()->count()));
    }

    #[Test]
    public function resend_supersedes_and_revoke_ends_the_invitation_and_its_unsent_email(): void
    {
        [$admin, $school] = $this->staffAdmin();
        $this->inviteStaff($admin, $school, 'a@example.test', ['principal'])->assertCreated();
        [$oldSelector] = $this->staffLink();
        $old = app(TenantContext::class)->withSchool($school, fn () => StaffAccountInvitation::query()->sole());

        $newId = $this->staffPost($admin, $school, "/invitations/{$old->id}/resend")->assertOk()->json('id');
        [$newSelector] = $this->staffLink();
        $this->assertNotSame($oldSelector, $newSelector);

        app(TenantContext::class)->withSchool($school, function () use ($old, $newId): void {
            $this->assertSame(['revoked', 'reissued'], [$old->fresh()->status, $old->fresh()->revocation_reason]);
            $new = StaffAccountInvitation::query()->findOrFail($newId);
            $this->assertSame('pending', $new->status);
            $this->assertSame(['principal'], $new->roles()->with('role')->get()->map(fn ($r) => $r->role->key)->all());
            $this->assertSame(1, StaffAccountInvitation::query()->where('status', 'pending')->count(), 'Never two usable invitations.');
        });

        $this->staffPost($admin, $school, "/invitations/{$old->id}/revoke")->assertStatus(422)
            ->assertJsonPath('error.message', StaffAccountException::MESSAGES['invitation_not_pending']);
        $this->staffPost($admin, $school, "/invitations/{$newId}/revoke")->assertOk();
        $this->assertSame(['revoked', 'revoked'], app(TenantContext::class)->withSchool($school, fn () => [
            StaffAccountInvitation::query()->findOrFail($newId)->status,
            StaffAccountInvitation::query()->findOrFail($newId)->revocation_reason,
        ]));

        $events = app(TenantContext::class)->withSchool($school, fn () => SchoolAuditEvent::query()
            ->whereIn('event_type', [StaffInvitationService::INVITED, StaffInvitationService::REVOKED])
            ->orderBy('occurred_at')->orderBy('id')->pluck('event_type')->all());
        $this->assertSame([StaffInvitationService::INVITED, StaffInvitationService::REVOKED, StaffInvitationService::INVITED, StaffInvitationService::REVOKED], $events);
    }

    #[Test]
    public function another_school_can_neither_see_nor_touch_an_invitation(): void
    {
        [$admin, $school] = $this->staffAdmin();
        [$otherAdmin, $other] = $this->staffAdmin();
        $this->inviteStaff($admin, $school, 'a@example.test')->assertCreated();
        $id = app(TenantContext::class)->withSchool($school, fn () => StaffAccountInvitation::query()->sole()->id);

        $this->staffPost($otherAdmin, $other, "/invitations/{$id}/revoke")->assertStatus(422)
            ->assertJsonPath('error.message', StaffAccountException::MESSAGES['not_found']);
        $this->staffPost($otherAdmin, $other, "/invitations/{$id}/resend")->assertStatus(422);

        $this->enterSchool($otherAdmin, $other);
        $this->get('http://localhost/app/settings/staff')->assertOk()->assertInertia(fn ($page) => $page
            ->component('App/StaffAccounts/Index')->has('invitations', 0));
        $this->assertSame(0, app(TenantContext::class)->withSchool($other, fn () => StaffAccountInvitation::query()->count()));
    }

    #[Test]
    public function the_send_limiter_bounds_invitations_per_administrator(): void
    {
        [$admin, $school] = $this->staffAdmin();
        $this->staffCodes[$admin->id] = $this->issueRecoveryCodes($admin, 20);

        for ($i = 0; $i < 10; $i++) {
            $this->inviteStaff($admin, $school, "p{$i}@example.test")->assertCreated();
        }
        $this->inviteStaff($admin, $school, 'p10@example.test')->assertStatus(422)
            ->assertJsonPath('error.message', StaffAccountException::MESSAGES['rate_limited']);
        $this->assertSame(10, app(TenantContext::class)->withSchool($school, fn () => EmailMessage::query()->where('purpose', 'staff_account_invitation')->count()));
    }

    #[Test]
    public function the_staff_page_is_read_only_for_viewers_and_shows_no_other_school(): void
    {
        [$admin, $school] = $this->staffAdmin();
        [$principal] = $this->staffMember($school, 'principal');

        $this->enterSchool($principal, $school);
        $this->get('http://localhost/app/settings/staff')->assertOk()->assertInertia(fn ($page) => $page
            ->component('App/StaffAccounts/Index')->where('canInvite', false)->where('canManageRoles', false)
            ->has('staff', 2));

        $viewerless = $this->createUser();
        $this->createMembership($viewerless, $school);
        $this->enterSchool($viewerless, $school);
        $this->get('http://localhost/app/settings/staff')->assertForbidden();
    }
}

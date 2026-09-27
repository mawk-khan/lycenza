<?php

namespace Tests\Feature\Identity;

use App\Domain\Communications\Application\AnnouncementService;
use App\Domain\Communications\Application\CommunicationThreadService;
use App\Domain\Communications\Domain\CommunicationAudienceType;
use App\Domain\Communications\Domain\CommunicationChannel;
use App\Domain\Communications\Domain\CommunicationPriority;
use App\Domain\Communications\Infrastructure\CommunicationDelivery;
use App\Domain\Communications\Infrastructure\CommunicationRecipient;
use App\Domain\Guardians\Infrastructure\ContactType;
use App\Domain\Identity\Application\AccountInvitationService;
use App\Domain\Identity\Infrastructure\GuardianAccountInvitation;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCommunicationFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Concerns\FakesEmail;
use Tests\TestCase;

/**
 * Phase 5D.3 -- HTTP-layer coverage for the public Guardian
 * invitation-acceptance routes: the new-account flow, the
 * existing-account flow (needs-login and confirm), invalid/expired/
 * revoked tokens, cross-School forgery, no enumeration, and that
 * activation immediately unlocks real IN_APP/conversation
 * reachability with no further setup (brief §46/§47).
 */
class InvitationAcceptanceHubTest extends TestCase
{
    use CreatesCommunicationFixtures, CreatesTenancyFixtures, FakesEmail;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeEmail();
    }

    private function activate(User $user, School $school): void
    {
        $this->actingAs($user)->post("/app/schools/{$school->id}/activate");
    }

    private function issueAndCaptureUrl(School $school, $guardian, User $admin, string $email): string
    {
        $this->createGuardianContact($guardian, ContactType::Email, $email);
        app(AccountInvitationService::class)->invite($school, $guardian, $admin);

        preg_match('#/invitations/[^\s<"]+#', $this->lastAcceptedEmail()->text, $matches);

        return $matches[0];
    }

    #[Test]
    public function a_new_person_can_create_a_password_and_activates_immediately(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $guardian = $this->createGuardian($school);
        $path = $this->issueAndCaptureUrl($school, $guardian, $admin, 'newguardian@example.com');

        $show = $this->get($path);
        $show->assertInertia(fn ($page) => $page
            ->where('valid', true)
            ->where('accountAlreadyExists', false)
            ->where('email', 'newguardian@example.com')
        );

        $store = $this->post($path, ['password' => 'a-strong-password-1', 'password_confirmation' => 'a-strong-password-1']);
        $store->assertRedirect('/app');
        $this->assertAuthenticated();
    }

    #[Test]
    public function an_existing_user_must_log_in_before_confirming(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $existingUser = $this->createUser(['email' => 'existing@example.com']);
        $this->createMembership($existingUser, $school);
        $guardian = $this->createGuardian($school);
        $path = $this->issueAndCaptureUrl($school, $guardian, $admin, 'existing@example.com');

        $this->get($path)->assertInertia(fn ($page) => $page
            ->where('accountAlreadyExists', true)
            ->where('authenticatedAsMatchingUser', false)
        );

        // Not authenticated at all -- confirming is denied.
        $this->post($path)->assertSessionHasErrors('invitation');
    }

    #[Test]
    public function an_existing_user_authenticated_as_that_email_can_confirm(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $existingUser = $this->createUser(['email' => 'existing@example.com']);
        $this->createMembership($existingUser, $school);
        $guardian = $this->createGuardian($school);
        $path = $this->issueAndCaptureUrl($school, $guardian, $admin, 'existing@example.com');

        $this->actingAs($existingUser)->get($path)->assertInertia(fn ($page) => $page
            ->where('authenticatedAsMatchingUser', true)
        );

        $this->actingAs($existingUser)->post($path)->assertRedirect('/app');
    }

    #[Test]
    public function an_expired_invitation_shows_the_generic_invalid_state(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $guardian = $this->createGuardian($school);
        $path = $this->issueAndCaptureUrl($school, $guardian, $admin, 'expired@example.com');
        app(TenantContext::class)->withSchool($school, function () use ($guardian) {
            GuardianAccountInvitation::query()
                ->where('guardian_id', $guardian->id)
                ->update(['expires_at' => now()->subDay()]);
        });

        $this->get($path)->assertInertia(fn ($page) => $page->where('valid', false));
        $this->post($path)->assertSessionHasErrors('invitation');
    }

    #[Test]
    public function a_revoked_invitation_shows_the_generic_invalid_state(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $guardian = $this->createGuardian($school);
        $path = $this->issueAndCaptureUrl($school, $guardian, $admin, 'revoked@example.com');
        app(AccountInvitationService::class)->revoke($school, $guardian, $admin);

        $this->get($path)->assertInertia(fn ($page) => $page->where('valid', false));
    }

    #[Test]
    public function a_wrong_token_for_a_real_school_returns_the_same_generic_invalid_state(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $guardian = $this->createGuardian($school);
        $this->issueAndCaptureUrl($school, $guardian, $admin, 'realtoken@example.com');

        $this->get("/invitations/{$school->id}/completely-made-up-token")
            ->assertInertia(fn ($page) => $page->where('valid', false));
    }

    #[Test]
    public function a_token_issued_for_one_school_does_not_resolve_under_a_different_schools_url(): void
    {
        [$admin, $schoolA] = $this->createSchoolAdmin('school_admin');
        $schoolB = $this->createSchool();
        $guardian = $this->createGuardian($schoolA);
        $path = $this->issueAndCaptureUrl($schoolA, $guardian, $admin, 'forged@example.com');
        $token = str($path)->afterLast('/')->toString();

        $this->get("/invitations/{$schoolB->id}/{$token}")
            ->assertInertia(fn ($page) => $page->where('valid', false));
    }

    #[Test]
    public function a_single_used_token_cannot_be_reused(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $guardian = $this->createGuardian($school);
        $path = $this->issueAndCaptureUrl($school, $guardian, $admin, 'onceonly@example.com');
        $this->post($path, ['password' => 'a-strong-password-1', 'password_confirmation' => 'a-strong-password-1']);

        $this->get($path)->assertInertia(fn ($page) => $page->where('valid', false));
    }

    #[Test]
    public function after_activation_the_guardian_is_reachable_in_app_and_eligible_for_private_conversations_with_no_further_setup(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $guardian = $this->createGuardian($school);
        $path = $this->issueAndCaptureUrl($school, $guardian, $admin, 'active@example.com');
        $this->post($path, ['password' => 'a-strong-password-1', 'password_confirmation' => 'a-strong-password-1']);

        // IN_APP reachability, reusing the real AnnouncementService --
        // no special "activate communications" flag anywhere (brief §46).
        $announcement = app(AnnouncementService::class)->createDraft(
            $school, $admin, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::Guardian,
            domainAudienceMemberIds: [$guardian->id], channels: [CommunicationChannel::InApp],
        );
        $published = app(AnnouncementService::class)->publish($announcement, $admin);
        $deliveries = app(TenantContext::class)->withSchool($school, fn () => CommunicationDelivery::query()
            ->whereIn('recipient_id', CommunicationRecipient::query()->where('message_id', $published->message_id)->pluck('id'))
            ->get());
        $this->assertCount(1, $deliveries);

        // Private-conversation eligibility (brief §47) via the real
        // ConversationParticipantAuthorizationService/CommunicationThreadService --
        // no additional setup beyond the activation that already happened.
        $thread = app(CommunicationThreadService::class)->createThread($school, $admin, 'direct', 'Subject', guardianIds: [$guardian->id]);
        $this->assertNotNull($thread);
    }
}

<?php

namespace Tests\Feature\App;

use App\Domain\Communications\Infrastructure\CommunicationPreference;
use App\Support\Tenancy\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCommunicationFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 5A.5 §49: self-service preference HTTP coverage --
 * authorization, forged input rejection, cross-school protection.
 */
class CommunicationPreferenceHubTest extends TestCase
{
    use CreatesCommunicationFixtures, CreatesTenancyFixtures;

    private function activate($user, $school): void
    {
        $this->actingAs($user)->post("/app/schools/{$school->id}/activate");
    }

    #[Test]
    public function a_guest_is_redirected_to_login(): void
    {
        $this->get('/app/communications/preferences')->assertRedirect('/login');
    }

    #[Test]
    public function a_member_can_view_and_update_their_own_preference(): void
    {
        $user = $this->createUser();
        $school = $this->createSchool();
        $this->createMembership($user, $school);
        $this->activate($user, $school);

        $this->get('/app/communications/preferences')->assertInertia(fn ($page) => $page
            ->where('emailPreference', null)
        );

        $this->put('/app/communications/preferences', ['channel' => 'email', 'enabled' => false])
            ->assertRedirect();

        $this->get('/app/communications/preferences')->assertInertia(fn ($page) => $page
            ->where('emailPreference', 'disabled')
        );
    }

    #[Test]
    public function an_unsupported_channel_is_rejected(): void
    {
        $user = $this->createUser();
        $school = $this->createSchool();
        $this->createMembership($user, $school);
        $this->activate($user, $school);

        $this->put('/app/communications/preferences', ['channel' => 'in_app', 'enabled' => false])
            ->assertSessionHasErrors('channel');

        $this->put('/app/communications/preferences', ['channel' => 'sms', 'enabled' => false])
            ->assertSessionHasErrors('channel');
    }

    #[Test]
    public function the_endpoint_never_accepts_a_client_supplied_membership_id(): void
    {
        // The update payload has no membership-id field at all -- the
        // controller always resolves it from the authenticated actor's
        // own active membership. This test proves that even attempting
        // to smuggle one has no effect: only ['channel','enabled'] are
        // read.
        $user = $this->createUser();
        $school = $this->createSchool();
        $membership = $this->createMembership($user, $school);
        $this->activate($user, $school);

        $otherUser = $this->createUser();
        $otherMembership = $this->createMembership($otherUser, $school);

        $this->put('/app/communications/preferences', [
            'channel' => 'email',
            'enabled' => false,
            'school_membership_id' => $otherMembership->id,
        ])->assertRedirect();

        $context = app(TenantContext::class);
        $ownPreference = $context->withSchool($school, fn () => CommunicationPreference::query()
            ->where('school_membership_id', $membership->id)->first());
        $otherPreference = $context->withSchool($school, fn () => CommunicationPreference::query()
            ->where('school_membership_id', $otherMembership->id)->first());

        $this->assertNotNull($ownPreference);
        $this->assertNull($otherPreference, "Another member's preference must never be affected.");
    }
}

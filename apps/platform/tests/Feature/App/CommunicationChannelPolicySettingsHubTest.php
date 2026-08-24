<?php

namespace Tests\Feature\App;

use App\Domain\Communications\Application\Policy\CommunicationChannelPolicyService;
use App\Domain\Communications\Domain\CommunicationChannel;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCommunicationFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 5A.5 §49: school channel-policy administration HTTP coverage.
 */
class CommunicationChannelPolicySettingsHubTest extends TestCase
{
    use CreatesCommunicationFixtures, CreatesTenancyFixtures;

    private function activate($user, $school): void
    {
        $this->actingAs($user)->post("/app/schools/{$school->id}/activate");
    }

    #[Test]
    public function a_guest_is_redirected_to_login(): void
    {
        $this->get('/app/communications/settings/channels')->assertRedirect('/login');
    }

    #[Test]
    public function a_school_admin_can_view_and_update_channel_policy(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $this->activate($admin, $school);

        $this->get('/app/communications/settings/channels')->assertInertia(fn ($page) => $page
            ->where('policies.1.channel', 'email')
            ->where('policies.1.optionalAllowed', true)
            ->where('policies.1.isOverride', false)
        );

        $this->put('/app/communications/settings/channels', [
            'channel' => 'email',
            'optional_allowed' => false,
            'required_allowed' => true,
            'recipient_can_opt_out' => true,
        ])->assertRedirect();

        $view = app(CommunicationChannelPolicyService::class)->policyFor($school, CommunicationChannel::Email);
        $this->assertFalse($view->optionalAllowed);
        $this->assertTrue($view->isOverride);
    }

    #[Test]
    public function a_principal_without_communications_manage_cannot_view_settings(): void
    {
        [$principal, $school] = $this->createSchoolAdmin('principal');
        $this->activate($principal, $school);

        $this->get('/app/communications/settings/channels')->assertForbidden();
        $this->put('/app/communications/settings/channels', [
            'channel' => 'email', 'optional_allowed' => false, 'required_allowed' => false, 'recipient_can_opt_out' => false,
        ])->assertForbidden();
    }

    #[Test]
    public function an_ordinary_member_cannot_update_channel_policy(): void
    {
        $user = $this->createUser();
        $school = $this->createSchool();
        $this->createMembership($user, $school);
        $this->activate($user, $school);

        $this->put('/app/communications/settings/channels', [
            'channel' => 'email', 'optional_allowed' => false, 'required_allowed' => false, 'recipient_can_opt_out' => false,
        ])->assertForbidden();
    }

    #[Test]
    public function in_app_cannot_be_written_through_this_endpoint(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $this->activate($admin, $school);

        $this->put('/app/communications/settings/channels', [
            'channel' => 'in_app', 'optional_allowed' => false, 'required_allowed' => false, 'recipient_can_opt_out' => true,
        ])->assertSessionHasErrors('channel');
    }

    #[Test]
    public function an_invalid_channel_is_rejected(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $this->activate($admin, $school);

        $this->put('/app/communications/settings/channels', [
            'channel' => 'sms', 'optional_allowed' => false, 'required_allowed' => false, 'recipient_can_opt_out' => true,
        ])->assertSessionHasErrors('channel');
    }

    #[Test]
    public function school_a_admin_cannot_affect_school_bs_policy(): void
    {
        [$adminA, $schoolA] = $this->createSchoolAdmin('school_admin');
        $schoolB = $this->createSchool();
        $this->activate($adminA, $schoolA);

        $this->put('/app/communications/settings/channels', [
            'channel' => 'email', 'optional_allowed' => false, 'required_allowed' => false, 'recipient_can_opt_out' => false,
        ])->assertRedirect();

        // Only School A's own policy is ever touched -- there is no
        // School-id input at all, so School B is structurally
        // unreachable from this request; confirm it still has the
        // system default.
        $viewB = app(CommunicationChannelPolicyService::class)->policyFor($schoolB, CommunicationChannel::Email);
        $this->assertTrue($viewB->optionalAllowed);
        $this->assertFalse($viewB->isOverride);
    }
}

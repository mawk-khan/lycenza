<?php

namespace Tests\Feature\App;

use App\Domain\Communications\Application\Policy\CommunicationConsentService;
use App\Domain\Communications\Application\Policy\CommunicationDomainPreferenceService;
use App\Domain\Communications\Domain\CommunicationChannel;
use App\Domain\Communications\Domain\CommunicationConsentStatus;
use App\Domain\Guardians\Infrastructure\ContactType;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCommunicationFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 5D.2 §29/§30/§31/§67 -- HTTP-layer coverage for the Guardian
 * domain communication preference/consent administrative surface:
 * authorization (both capabilities required), cross-School denial,
 * correct UI state, and no plaintext contact leakage into page props.
 */
class GuardianCommunicationPreferenceHubTest extends TestCase
{
    use CreatesCommunicationFixtures, CreatesTenancyFixtures;

    private function activate($user, $school): void
    {
        $this->actingAs($user)->post("/app/schools/{$school->id}/activate");
    }

    #[Test]
    public function an_authorized_actor_can_view_and_update_the_preference(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $guardian = $this->createGuardian($school);
        $this->activate($admin, $school);

        $this->get("/app/guardians/{$guardian->id}")->assertInertia(fn ($page) => $page
            ->where('canManageCommunicationPreferences', true)
            ->where('communicationPreferences.email.preferenceEnabled', null)
            ->where('communicationPreferences.email.consentStatus', null));

        $this->put("/app/guardians/{$guardian->id}/communication-preference", [
            'channel' => 'email',
            'enabled' => false,
        ])->assertRedirect();

        $view = app(CommunicationDomainPreferenceService::class)->currentPreferenceForGuardian($school, $guardian, CommunicationChannel::Email);
        $this->assertFalse($view->isEnabled());
    }

    #[Test]
    public function an_authorized_actor_can_record_consent_grant_and_withdrawal(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $guardian = $this->createGuardian($school);
        $this->activate($admin, $school);

        $this->post("/app/guardians/{$guardian->id}/communication-consent", [
            'channel' => 'email',
            'status' => 'granted',
        ])->assertRedirect();

        $status = app(CommunicationConsentService::class)->currentStatusForGuardian($school, $guardian, CommunicationChannel::Email);
        $this->assertSame(CommunicationConsentStatus::Granted, $status);

        $this->post("/app/guardians/{$guardian->id}/communication-consent", [
            'channel' => 'email',
            'status' => 'withdrawn',
        ])->assertRedirect();

        $status = app(CommunicationConsentService::class)->currentStatusForGuardian($school, $guardian, CommunicationChannel::Email);
        $this->assertSame(CommunicationConsentStatus::Withdrawn, $status);
    }

    #[Test]
    public function an_actor_with_only_communications_manage_cannot_update_the_preference(): void
    {
        [, $school] = $this->createSchoolAdmin('school_admin');
        $guardian = $this->createGuardian($school);
        $actor = $this->createUserWithCapabilities($school, ['communications.manage', 'guardians.view']);
        $this->activate($actor, $school);

        $this->put("/app/guardians/{$guardian->id}/communication-preference", [
            'channel' => 'email',
            'enabled' => false,
        ])->assertForbidden();
    }

    #[Test]
    public function an_actor_with_only_guardians_manage_cannot_update_the_preference(): void
    {
        [, $school] = $this->createSchoolAdmin('school_admin');
        $guardian = $this->createGuardian($school);
        $actor = $this->createUserWithCapabilities($school, ['guardians.manage', 'guardians.view']);
        $this->activate($actor, $school);

        $this->put("/app/guardians/{$guardian->id}/communication-preference", [
            'channel' => 'email',
            'enabled' => false,
        ])->assertForbidden();
    }

    #[Test]
    public function an_actor_with_both_capabilities_can_update_the_preference(): void
    {
        [, $school] = $this->createSchoolAdmin('school_admin');
        $guardian = $this->createGuardian($school);
        $actor = $this->createUserWithCapabilities($school, ['communications.manage', 'guardians.manage', 'guardians.view']);
        $this->activate($actor, $school);

        $this->put("/app/guardians/{$guardian->id}/communication-preference", [
            'channel' => 'email',
            'enabled' => false,
        ])->assertRedirect();
    }

    #[Test]
    public function a_guest_is_redirected_to_login(): void
    {
        [, $school] = $this->createSchoolAdmin('school_admin');
        $guardian = $this->createGuardian($school);

        $this->put("/app/guardians/{$guardian->id}/communication-preference", [
            'channel' => 'email',
            'enabled' => false,
        ])->assertRedirect('/login');
    }

    #[Test]
    public function a_cross_school_guardian_id_is_not_found(): void
    {
        [$admin, $schoolA] = $this->createSchoolAdmin('school_admin');
        $guardianB = $this->createGuardian($this->createSchool());
        $this->activate($admin, $schoolA);

        $this->put("/app/guardians/{$guardianB->id}/communication-preference", [
            'channel' => 'email',
            'enabled' => false,
        ])->assertNotFound();
    }

    #[Test]
    public function only_email_channel_is_accepted(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $guardian = $this->createGuardian($school);
        $this->activate($admin, $school);

        $this->put("/app/guardians/{$guardian->id}/communication-preference", [
            'channel' => 'in_app',
            'enabled' => false,
        ])->assertInvalid(['channel']);
    }

    #[Test]
    public function the_guardian_detail_page_never_exposes_a_plaintext_contact_value_via_the_preference_state(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $guardian = $this->createGuardian($school);
        $this->createGuardianContact($guardian, ContactType::Email, 'secret@example.com', ['is_primary' => true]);
        $this->activate($admin, $school);

        $response = $this->get("/app/guardians/{$guardian->id}");
        $response->assertOk();

        // The contacts prop legitimately shows the address elsewhere on
        // this same page (existing Phase 1A.3 behavior, unchanged) --
        // this asserts the NEW communicationPreferences prop specifically
        // carries only status booleans/enums, never the address itself.
        $props = $response->viewData('page')['props'];
        $this->assertSame(
            ['preferenceEnabled', 'consentStatus', 'endpointAvailable'],
            array_keys($props['communicationPreferences']['email']),
        );
        $this->assertNotContains('secret@example.com', $props['communicationPreferences']['email']);
    }

    #[Test]
    public function endpoint_availability_reflects_whether_an_eligible_contact_exists(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $guardianWithout = $this->createGuardian($school);
        $guardianWith = $this->createGuardian($school);
        $this->createGuardianContact($guardianWith, ContactType::Email, 'parent@example.com', ['is_primary' => true]);
        $this->activate($admin, $school);

        $this->get("/app/guardians/{$guardianWithout->id}")->assertInertia(fn ($page) => $page
            ->where('communicationPreferences.email.endpointAvailable', false));

        $this->get("/app/guardians/{$guardianWith->id}")->assertInertia(fn ($page) => $page
            ->where('communicationPreferences.email.endpointAvailable', true));
    }
}

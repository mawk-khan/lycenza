<?php

namespace Tests\Feature\App;

use App\Domain\Communications\Infrastructure\CommunicationDeliveryTimingPolicy;
use App\Support\Tenancy\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCommunicationFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 5A.9: school quiet-hours settings HTTP coverage, mirroring
 * CommunicationChannelPolicySettingsHubTest's shape exactly.
 */
class CommunicationDeliveryTimingPolicySettingsHubTest extends TestCase
{
    use CreatesCommunicationFixtures, CreatesTenancyFixtures;

    private function activate($user, $school): void
    {
        $this->actingAs($user)->post("/app/schools/{$school->id}/activate");
    }

    private function policyFor($school): ?CommunicationDeliveryTimingPolicy
    {
        return app(TenantContext::class)->withSchool(
            $school,
            fn () => CommunicationDeliveryTimingPolicy::query()->where('school_id', $school->id)->where('channel', 'email')->first(),
        );
    }

    #[Test]
    public function a_guest_is_redirected_to_login(): void
    {
        $this->put('/app/communications/settings/timing', [
            'channel' => 'email', 'enabled' => true, 'quiet_hours_start' => '20:00', 'quiet_hours_end' => '07:00',
        ])->assertRedirect('/login');
    }

    #[Test]
    public function the_settings_page_shows_default_disabled_timing_when_no_row_exists(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $this->activate($admin, $school);

        $this->get('/app/communications/settings/channels')->assertInertia(fn ($page) => $page
            ->where('timingPolicy.channel', 'email')
            ->where('timingPolicy.enabled', false)
            // Phase 5A.10 §37: the safe default -- deploying this
            // checkpoint must not silently enable bypass for anyone.
            ->where('timingPolicy.emergencyBypassAllowed', false)
            ->where('schoolTimezone', $school->timezone)
        );
    }

    #[Test]
    public function omitting_emergency_bypass_allowed_defaults_to_false(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $this->activate($admin, $school);

        $this->put('/app/communications/settings/timing', [
            'channel' => 'email', 'enabled' => true, 'quiet_hours_start' => '20:00', 'quiet_hours_end' => '07:00',
        ])->assertRedirect();

        $policy = $this->policyFor($school);
        $this->assertNotNull($policy);
        $this->assertFalse($policy->emergency_bypass_allowed);
    }

    #[Test]
    public function a_school_admin_can_enable_emergency_bypass(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $this->activate($admin, $school);

        $this->put('/app/communications/settings/timing', [
            'channel' => 'email', 'enabled' => true, 'quiet_hours_start' => '20:00', 'quiet_hours_end' => '07:00',
            'emergency_bypass_allowed' => true,
        ])->assertRedirect();

        $policy = $this->policyFor($school);
        $this->assertNotNull($policy);
        $this->assertTrue($policy->emergency_bypass_allowed);
    }

    #[Test]
    public function school_a_admin_cannot_enable_school_bs_emergency_bypass(): void
    {
        [$adminA, $schoolA] = $this->createSchoolAdmin('school_admin');
        $schoolB = $this->createSchool();
        $this->createDeliveryTimingPolicy($schoolB, ['enabled' => true, 'emergency_bypass_allowed' => false]);
        $this->activate($adminA, $schoolA);

        $this->put('/app/communications/settings/timing', [
            'channel' => 'email', 'enabled' => true, 'quiet_hours_start' => '20:00', 'quiet_hours_end' => '07:00',
            'emergency_bypass_allowed' => true,
        ])->assertRedirect();

        $this->assertFalse($this->policyFor($schoolB)->emergency_bypass_allowed);
    }

    #[Test]
    public function a_school_admin_can_enable_quiet_hours(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $this->activate($admin, $school);

        $this->put('/app/communications/settings/timing', [
            'channel' => 'email',
            'enabled' => true,
            'quiet_hours_start' => '20:00',
            'quiet_hours_end' => '07:00',
        ])->assertRedirect('/app/communications/settings/channels');

        $policy = $this->policyFor($school);
        $this->assertNotNull($policy);
        $this->assertTrue($policy->enabled);
        $this->assertSame('20:00:00', $policy->quiet_hours_start);
        $this->assertSame('07:00:00', $policy->quiet_hours_end);
    }

    #[Test]
    public function a_principal_without_communications_manage_cannot_update_timing(): void
    {
        [$principal, $school] = $this->createSchoolAdmin('principal');
        $this->activate($principal, $school);

        $this->put('/app/communications/settings/timing', [
            'channel' => 'email', 'enabled' => true, 'quiet_hours_start' => '20:00', 'quiet_hours_end' => '07:00',
        ])->assertForbidden();
    }

    #[Test]
    public function an_equal_start_and_end_is_rejected(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $this->activate($admin, $school);

        $this->put('/app/communications/settings/timing', [
            'channel' => 'email', 'enabled' => true, 'quiet_hours_start' => '09:00', 'quiet_hours_end' => '09:00',
        ])->assertSessionHasErrors('quiet_hours_end');
    }

    #[Test]
    public function an_invalid_time_format_is_rejected(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $this->activate($admin, $school);

        $this->put('/app/communications/settings/timing', [
            'channel' => 'email', 'enabled' => true, 'quiet_hours_start' => '8pm', 'quiet_hours_end' => '07:00',
        ])->assertSessionHasErrors('quiet_hours_start');
    }

    #[Test]
    public function disabling_quiet_hours_does_not_require_a_window(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $this->activate($admin, $school);

        $this->put('/app/communications/settings/timing', [
            'channel' => 'email', 'enabled' => false,
        ])->assertRedirect('/app/communications/settings/channels');

        $policy = $this->policyFor($school);
        $this->assertNotNull($policy);
        $this->assertFalse($policy->enabled);
    }

    #[Test]
    public function an_invalid_channel_is_rejected(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $this->activate($admin, $school);

        $this->put('/app/communications/settings/timing', [
            'channel' => 'sms', 'enabled' => true, 'quiet_hours_start' => '20:00', 'quiet_hours_end' => '07:00',
        ])->assertSessionHasErrors('channel');
    }

    #[Test]
    public function school_a_admin_cannot_affect_school_bs_timing_policy(): void
    {
        [$adminA, $schoolA] = $this->createSchoolAdmin('school_admin');
        $schoolB = $this->createSchool();
        $this->activate($adminA, $schoolA);

        $this->put('/app/communications/settings/timing', [
            'channel' => 'email', 'enabled' => true, 'quiet_hours_start' => '20:00', 'quiet_hours_end' => '07:00',
        ])->assertRedirect();

        $this->assertNull($this->policyFor($schoolB));
    }
}

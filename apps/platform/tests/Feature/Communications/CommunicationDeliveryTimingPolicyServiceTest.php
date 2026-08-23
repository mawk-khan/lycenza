<?php

namespace Tests\Feature\Communications;

use App\Domain\Communications\Application\Policy\CommunicationDeliveryTimingPolicyService;
use App\Domain\Communications\Domain\CommunicationChannel;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCommunicationFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 5A.9 §48/§49/§50/§18/§47 -- boundary, cross-midnight, same-day,
 * DST, and multi-timezone proofs for
 * App\Domain\Communications\Application\Policy\CommunicationDeliveryTimingPolicyService::evaluate(),
 * independent of the delivery pipeline it feeds.
 */
class CommunicationDeliveryTimingPolicyServiceTest extends TestCase
{
    use CreatesCommunicationFixtures, CreatesTenancyFixtures;

    private function evaluateAt(string $localDateTime, string $timezone, string $quietStart = '20:00:00', string $quietEnd = '07:00:00'): bool
    {
        $school = $this->createSchool(['timezone' => $timezone]);
        $this->createDeliveryTimingPolicy($school, [
            'enabled' => true,
            'quiet_hours_start' => $quietStart,
            'quiet_hours_end' => $quietEnd,
        ]);

        $at = Carbon::parse($localDateTime, $timezone)->utc();
        $decision = app(CommunicationDeliveryTimingPolicyService::class)->evaluate($school, CommunicationChannel::Email, $at);

        return $decision->shouldDefer;
    }

    // --- No policy / disabled policy: regression invariant (§51) -----

    #[Test]
    public function no_policy_row_sends_immediately_regardless_of_time(): void
    {
        $school = $this->createSchool(['timezone' => 'Asia/Kolkata']);

        $at = Carbon::parse('2026-08-23 22:00:00', 'Asia/Kolkata')->utc();
        $decision = app(CommunicationDeliveryTimingPolicyService::class)->evaluate($school, CommunicationChannel::Email, $at);

        $this->assertFalse($decision->shouldDefer);
        $this->assertNull($decision->availableAt);
        $this->assertSame('allowed_now', $decision->reason->value);
    }

    #[Test]
    public function a_disabled_policy_row_sends_immediately_regardless_of_time(): void
    {
        $school = $this->createSchool(['timezone' => 'Asia/Kolkata']);
        $this->createDeliveryTimingPolicy($school, [
            'enabled' => false,
            'quiet_hours_start' => '20:00:00',
            'quiet_hours_end' => '07:00:00',
        ]);

        $at = Carbon::parse('2026-08-23 22:00:00', 'Asia/Kolkata')->utc();
        $decision = app(CommunicationDeliveryTimingPolicyService::class)->evaluate($school, CommunicationChannel::Email, $at);

        $this->assertFalse($decision->shouldDefer);
    }

    #[Test]
    public function in_app_is_never_deferred_even_with_an_enabled_policy_row(): void
    {
        $school = $this->createSchool(['timezone' => 'Asia/Kolkata']);
        $this->createDeliveryTimingPolicy($school, [
            'enabled' => true,
            'quiet_hours_start' => '00:00:00',
            'quiet_hours_end' => '23:59:59',
        ]);

        $at = Carbon::parse('2026-08-23 12:00:00', 'Asia/Kolkata')->utc();
        $decision = app(CommunicationDeliveryTimingPolicyService::class)->evaluate($school, CommunicationChannel::InApp, $at);

        $this->assertFalse($decision->shouldDefer);
    }

    // --- Cross-midnight window: 20:00 -> 07:00 (§49) ------------------

    #[Test]
    public function cross_midnight_window_boundaries(): void
    {
        $this->assertFalse($this->evaluateAt('2026-08-23 19:59:00', 'Asia/Kolkata'), '19:59 is before quiet start');
        $this->assertTrue($this->evaluateAt('2026-08-23 20:00:00', 'Asia/Kolkata'), '20:00 is inclusive quiet start');
        $this->assertTrue($this->evaluateAt('2026-08-23 23:30:00', 'Asia/Kolkata'), '23:30 is inside the evening portion');
        $this->assertTrue($this->evaluateAt('2026-08-24 00:30:00', 'Asia/Kolkata'), '00:30 is inside the early-morning portion');
        $this->assertTrue($this->evaluateAt('2026-08-24 06:59:00', 'Asia/Kolkata'), '06:59 is just before quiet end');
        $this->assertFalse($this->evaluateAt('2026-08-24 07:00:00', 'Asia/Kolkata'), '07:00 is exclusive quiet end');
    }

    #[Test]
    public function cross_midnight_available_at_is_todays_end_when_deferred_in_the_early_morning_portion(): void
    {
        $school = $this->createSchool(['timezone' => 'Asia/Kolkata']);
        $this->createDeliveryTimingPolicy($school, ['enabled' => true, 'quiet_hours_start' => '20:00:00', 'quiet_hours_end' => '07:00:00']);

        $at = Carbon::parse('2026-08-24 00:30:00', 'Asia/Kolkata')->utc();
        $decision = app(CommunicationDeliveryTimingPolicyService::class)->evaluate($school, CommunicationChannel::Email, $at);

        $this->assertTrue($decision->shouldDefer);
        $this->assertTrue($decision->availableAt->equalTo(Carbon::parse('2026-08-24 07:00:00', 'Asia/Kolkata')));
    }

    #[Test]
    public function cross_midnight_available_at_is_tomorrows_end_when_deferred_in_the_evening_portion(): void
    {
        $school = $this->createSchool(['timezone' => 'Asia/Kolkata']);
        $this->createDeliveryTimingPolicy($school, ['enabled' => true, 'quiet_hours_start' => '20:00:00', 'quiet_hours_end' => '07:00:00']);

        $at = Carbon::parse('2026-08-23 21:00:00', 'Asia/Kolkata')->utc();
        $decision = app(CommunicationDeliveryTimingPolicyService::class)->evaluate($school, CommunicationChannel::Email, $at);

        $this->assertTrue($decision->shouldDefer);
        $this->assertTrue($decision->availableAt->equalTo(Carbon::parse('2026-08-24 07:00:00', 'Asia/Kolkata')));
    }

    // --- Same-day window: 13:00 -> 15:00 (§50) ------------------------

    #[Test]
    public function same_day_window_boundaries(): void
    {
        $this->assertFalse($this->evaluateAt('2026-08-23 12:59:00', 'Asia/Kolkata', '13:00:00', '15:00:00'), '12:59 is before quiet start');
        $this->assertTrue($this->evaluateAt('2026-08-23 13:00:00', 'Asia/Kolkata', '13:00:00', '15:00:00'), '13:00 is inclusive quiet start');
        $this->assertTrue($this->evaluateAt('2026-08-23 14:00:00', 'Asia/Kolkata', '13:00:00', '15:00:00'), '14:00 is inside the window');
        $this->assertTrue($this->evaluateAt('2026-08-23 14:59:00', 'Asia/Kolkata', '13:00:00', '15:00:00'), '14:59 is just before quiet end');
        $this->assertFalse($this->evaluateAt('2026-08-23 15:00:00', 'Asia/Kolkata', '13:00:00', '15:00:00'), '15:00 is exclusive quiet end');
    }

    #[Test]
    public function same_day_available_at_is_todays_end(): void
    {
        $school = $this->createSchool(['timezone' => 'Asia/Kolkata']);
        $this->createDeliveryTimingPolicy($school, ['enabled' => true, 'quiet_hours_start' => '13:00:00', 'quiet_hours_end' => '15:00:00']);

        $at = Carbon::parse('2026-08-23 14:00:00', 'Asia/Kolkata')->utc();
        $decision = app(CommunicationDeliveryTimingPolicyService::class)->evaluate($school, CommunicationChannel::Email, $at);

        $this->assertTrue($decision->shouldDefer);
        $this->assertTrue($decision->availableAt->equalTo(Carbon::parse('2026-08-23 15:00:00', 'Asia/Kolkata')));
    }

    // --- Equal start/end: defensive fallback, never quiet ------------

    #[Test]
    public function an_equal_start_and_end_never_defers(): void
    {
        $this->assertFalse($this->evaluateAt('2026-08-23 12:00:00', 'Asia/Kolkata', '09:00:00', '09:00:00'));
    }

    // --- DST safety (§18) ---------------------------------------------

    #[Test]
    public function cross_midnight_deferral_across_a_spring_forward_transition_uses_correct_wall_clock_time(): void
    {
        // America/New_York DST begins 2026-03-08 02:00 -> 03:00 local.
        $school = $this->createSchool(['timezone' => 'America/New_York']);
        $this->createDeliveryTimingPolicy($school, ['enabled' => true, 'quiet_hours_start' => '20:00:00', 'quiet_hours_end' => '07:00:00']);

        $at = Carbon::parse('2026-03-07 22:00:00', 'America/New_York')->utc();
        $decision = app(CommunicationDeliveryTimingPolicyService::class)->evaluate($school, CommunicationChannel::Email, $at);

        $this->assertTrue($decision->shouldDefer);
        // A naive "+24 hours" would land one hour off (06:00 local
        // instead of 07:00) since this calendar day is only 23 real
        // hours long -- proving timezone-aware arithmetic was used.
        $this->assertTrue($decision->availableAt->equalTo(Carbon::parse('2026-03-08 07:00:00', 'America/New_York')));
    }

    #[Test]
    public function cross_midnight_deferral_across_a_fall_back_transition_uses_correct_wall_clock_time(): void
    {
        // America/New_York DST ends 2026-11-01 02:00 -> 01:00 local.
        $school = $this->createSchool(['timezone' => 'America/New_York']);
        $this->createDeliveryTimingPolicy($school, ['enabled' => true, 'quiet_hours_start' => '20:00:00', 'quiet_hours_end' => '07:00:00']);

        $at = Carbon::parse('2026-10-31 22:00:00', 'America/New_York')->utc();
        $decision = app(CommunicationDeliveryTimingPolicyService::class)->evaluate($school, CommunicationChannel::Email, $at);

        $this->assertTrue($decision->shouldDefer);
        // A naive "+24 hours" would also land one hour off here (this
        // calendar day is 25 real hours long).
        $this->assertTrue($decision->availableAt->equalTo(Carbon::parse('2026-11-01 07:00:00', 'America/New_York')));
    }

    // --- Multi-timezone (§47) -----------------------------------------

    #[Test]
    public function two_schools_in_different_timezones_evaluate_the_same_utc_instant_differently(): void
    {
        $schoolKolkata = $this->createSchool(['timezone' => 'Asia/Kolkata']);
        $this->createDeliveryTimingPolicy($schoolKolkata, ['enabled' => true, 'quiet_hours_start' => '20:00:00', 'quiet_hours_end' => '07:00:00']);

        $schoolNewYork = $this->createSchool(['timezone' => 'America/New_York']);
        $this->createDeliveryTimingPolicy($schoolNewYork, ['enabled' => true, 'quiet_hours_start' => '20:00:00', 'quiet_hours_end' => '07:00:00']);

        // 2026-08-15 15:30 UTC = 21:00 Asia/Kolkata (quiet) = 11:30
        // America/New_York EDT (not quiet).
        $at = Carbon::parse('2026-08-15 15:30:00', 'UTC');

        $service = app(CommunicationDeliveryTimingPolicyService::class);
        $kolkataDecision = $service->evaluate($schoolKolkata, CommunicationChannel::Email, $at);
        $newYorkDecision = $service->evaluate($schoolNewYork, CommunicationChannel::Email, $at);

        $this->assertTrue($kolkataDecision->shouldDefer);
        $this->assertFalse($newYorkDecision->shouldDefer);
    }
}

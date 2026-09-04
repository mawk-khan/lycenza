<?php

namespace Tests\Feature\Auth;

use App\Support\Auth\AssuranceFreshness;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 0H.4D-P1 replay-security correction: AssuranceFreshness is the
 * shared fail-closed rule behind both MfaChallengeService::
 * hasValidAssurance() and PasswordConfirmationService::require(),
 * replacing the closure-flagged `abs(now()->diffInMinutes(...))`
 * pattern (which accepted a future stored timestamp within the
 * window). Every state below must fail closed except a genuine past
 * timestamp inside the window.
 */
class AssuranceFreshnessTest extends TestCase
{
    #[Test]
    public function a_missing_timestamp_is_not_fresh(): void
    {
        $this->assertFalse(AssuranceFreshness::isFresh(null, 60));
    }

    #[Test]
    public function an_empty_string_is_not_fresh(): void
    {
        $this->assertFalse(AssuranceFreshness::isFresh('', 60));
    }

    #[Test]
    public function a_malformed_timestamp_is_not_fresh_and_never_throws(): void
    {
        $this->assertFalse(AssuranceFreshness::isFresh('not-a-real-timestamp', 60));
        $this->assertFalse(AssuranceFreshness::isFresh('<script>alert(1)</script>', 60));
        $this->assertFalse(AssuranceFreshness::isFresh('99999-99-99', 60));
    }

    #[Test]
    public function a_future_timestamp_is_not_fresh_even_within_the_window(): void
    {
        // The exact abs() over-correction the closure audit flagged:
        // abs(now()->diffInMinutes($future)) can be small enough to
        // pass the window check even though the timestamp has not
        // happened yet.
        $fiveMinutesFromNow = now()->addMinutes(5)->toIso8601String();

        $this->assertFalse(AssuranceFreshness::isFresh($fiveMinutesFromNow, 60));
    }

    #[Test]
    public function a_far_future_timestamp_is_not_fresh(): void
    {
        $this->assertFalse(AssuranceFreshness::isFresh(now()->addYear()->toIso8601String(), 60));
    }

    #[Test]
    public function a_timestamp_just_inside_the_window_is_fresh(): void
    {
        $justInside = now()->subMinutes(59)->toIso8601String();

        $this->assertTrue(AssuranceFreshness::isFresh($justInside, 60));
    }

    #[Test]
    public function a_timestamp_just_outside_the_window_is_not_fresh(): void
    {
        $justOutside = now()->subMinutes(61)->toIso8601String();

        $this->assertFalse(AssuranceFreshness::isFresh($justOutside, 60));
    }

    #[Test]
    public function a_timestamp_exactly_at_the_window_boundary_is_fresh(): void
    {
        $exactlyAtBoundary = now()->subMinutes(60)->toIso8601String();

        $this->assertTrue(AssuranceFreshness::isFresh($exactlyAtBoundary, 60));
    }

    #[Test]
    public function a_recent_past_timestamp_is_fresh(): void
    {
        $this->assertTrue(AssuranceFreshness::isFresh(now()->subMinute()->toIso8601String(), 60));
    }

    #[Test]
    public function the_ten_minute_password_confirmation_window_boundary_behaves_identically(): void
    {
        $this->assertTrue(AssuranceFreshness::isFresh(now()->subMinutes(9)->toIso8601String(), 10));
        $this->assertTrue(AssuranceFreshness::isFresh(now()->subMinutes(10)->toIso8601String(), 10));
        $this->assertFalse(AssuranceFreshness::isFresh(now()->subMinutes(11)->toIso8601String(), 10));
        $this->assertFalse(AssuranceFreshness::isFresh(now()->addMinutes(5)->toIso8601String(), 10));
    }
}

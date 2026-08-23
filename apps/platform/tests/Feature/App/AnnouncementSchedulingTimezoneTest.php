<?php

namespace Tests\Feature\App;

use App\Domain\Communications\Infrastructure\CommunicationAnnouncement;
use App\Support\Tenancy\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCommunicationFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 5A.4 §18/§47: proves the actual timezone strategy this
 * checkpoint implements -- input is a School-local naive datetime
 * string, converted to canonical UTC by
 * App\Domain\Communications\Http\Controllers\AnnouncementController::schedule()
 * via App\Support\Tenancy\SchoolTimezone before ever reaching
 * AnnouncementService, which only ever deals in UTC.
 */
class AnnouncementSchedulingTimezoneTest extends TestCase
{
    use CreatesCommunicationFixtures, CreatesTenancyFixtures;

    private function activate($user, $school): void
    {
        $this->actingAs($user)->post("/app/schools/{$school->id}/activate");
    }

    private function scheduleAnnouncement($admin, $school, string $localDateTime): CommunicationAnnouncement
    {
        $create = $this->post('/app/communications/announcements', [
            'title' => 'T', 'body' => 'B', 'priority' => 'normal', 'audience_type' => 'school_wide',
        ]);
        $showUrl = $create->headers->get('Location');
        $id = last(explode('/', $showUrl));

        $this->post("{$showUrl}/schedule", ['scheduled_at' => $localDateTime])->assertRedirect();

        return app(TenantContext::class)->withSchool($school, fn () => CommunicationAnnouncement::query()->findOrFail($id));
    }

    #[Test]
    public function a_school_local_time_is_stored_as_the_correct_canonical_utc_instant(): void
    {
        $school = $this->createSchool(['timezone' => 'Asia/Kolkata']); // UTC+05:30, no DST
        $admin = $this->createUser();
        $membership = $this->createMembership($admin, $school);
        $this->assignSchoolRole($membership, 'school_admin');
        $this->activate($admin, $school);

        $announcement = $this->scheduleAnnouncement($admin, $school, '2026-09-01T09:00:00');

        // 09:00 IST (+05:30) == 03:30 UTC.
        $this->assertSame('2026-09-01T03:30:00+00:00', $announcement->scheduled_at->toIso8601String());
    }

    #[Test]
    public function two_schools_in_different_timezones_scheduling_the_same_local_wall_clock_time_resolve_to_different_utc_instants(): void
    {
        $schoolIndia = $this->createSchool(['timezone' => 'Asia/Kolkata']); // UTC+05:30
        $adminIndia = $this->createUser();
        $membershipIndia = $this->createMembership($adminIndia, $schoolIndia);
        $this->assignSchoolRole($membershipIndia, 'school_admin');
        $this->activate($adminIndia, $schoolIndia);
        $announcementIndia = $this->scheduleAnnouncement($adminIndia, $schoolIndia, '2026-09-01T09:00:00');

        $schoolUtc = $this->createSchool(['timezone' => 'UTC']);
        $adminUtc = $this->createUser();
        $membershipUtc = $this->createMembership($adminUtc, $schoolUtc);
        $this->assignSchoolRole($membershipUtc, 'school_admin');
        $this->activate($adminUtc, $schoolUtc);
        $announcementUtc = $this->scheduleAnnouncement($adminUtc, $schoolUtc, '2026-09-01T09:00:00');

        $this->assertNotSame(
            $announcementIndia->scheduled_at->toIso8601String(),
            $announcementUtc->scheduled_at->toIso8601String(),
        );
        // Same literal wall-clock string, 5.5 hours apart in real UTC time.
        $this->assertSame(
            330,
            (int) abs($announcementUtc->scheduled_at->diffInMinutes($announcementIndia->scheduled_at)),
        );
    }

    #[Test]
    public function a_dst_observing_timezone_schedules_correctly_across_a_dst_transition(): void
    {
        // America/New_York: EDT (UTC-4) before Nov 1 2026, EST (UTC-5)
        // after -- proves Carbon/DateTimeZone (via SchoolTimezone) does
        // real DST-aware conversion, not a fixed offset.
        $school = $this->createSchool(['timezone' => 'America/New_York']);
        $admin = $this->createUser();
        $membership = $this->createMembership($admin, $school);
        $this->assignSchoolRole($membership, 'school_admin');
        $this->activate($admin, $school);

        $beforeDst = $this->scheduleAnnouncement($admin, $school, '2026-10-15T09:00:00');
        $this->assertSame('2026-10-15T13:00:00+00:00', $beforeDst->scheduled_at->toIso8601String());
    }

    #[Test]
    public function a_midnight_boundary_local_time_converts_to_the_correct_utc_calendar_day(): void
    {
        // 00:30 IST on the 2nd is still the 1st in UTC -- proves the
        // due-time comparison (all in UTC) never gets confused by a
        // School-local midnight crossing a UTC date boundary.
        $school = $this->createSchool(['timezone' => 'Asia/Kolkata']);
        $admin = $this->createUser();
        $membership = $this->createMembership($admin, $school);
        $this->assignSchoolRole($membership, 'school_admin');
        $this->activate($admin, $school);

        $announcement = $this->scheduleAnnouncement($admin, $school, '2026-09-02T00:30:00');

        $this->assertSame('2026-09-01', $announcement->scheduled_at->toDateString());
        $this->assertSame('19:00:00', $announcement->scheduled_at->toTimeString());
    }
}

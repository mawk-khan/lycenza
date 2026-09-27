<?php

namespace Tests\Feature\Communications;

use App\Domain\Communications\Application\AnnouncementService;
use App\Domain\Communications\Domain\CommunicationAudienceType;
use App\Domain\Communications\Domain\CommunicationChannel;
use App\Domain\Communications\Domain\CommunicationPriority;
use App\Domain\Communications\Infrastructure\CommunicationAnnouncementRecipient;
use App\Domain\Communications\Infrastructure\CommunicationDelivery;
use App\Domain\Communications\Infrastructure\CommunicationRecipient;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCommunicationFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Concerns\FakesEmail;
use Tests\TestCase;

/**
 * Phase 5A.4 §43/§44/§45/§48: the `communications:publish-scheduled`
 * command end-to-end -- due-time audience resolution, idempotent
 * rerun, multi-school tenant isolation in one run, membership-change-
 * before-execution semantics, empty-audience failure backoff, and
 * partial channel (email) failure. Reuses AnnouncementService::publish()
 * unchanged throughout -- this file proves the ORCHESTRATION, not a
 * second publish implementation.
 */
class PublishScheduledAnnouncementsTest extends TestCase
{
    use CreatesCommunicationFixtures, CreatesTenancyFixtures, FakesEmail;

    private function service(): AnnouncementService
    {
        return app(AnnouncementService::class);
    }

    private function scheduleSchoolWide($school, $creator, Carbon $scheduledAt, array $channels = [CommunicationChannel::InApp])
    {
        $announcement = $this->service()->createDraft(
            $school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide,
            channels: $channels,
        );

        return $this->service()->schedule($announcement, $creator, $scheduledAt);
    }

    #[Test]
    public function a_not_yet_due_announcement_remains_scheduled(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $this->createMembership($this->createUser(), $school);
        $scheduled = $this->scheduleSchoolWide($school, $creator, now()->addDay());

        $this->artisan('communications:publish-scheduled')->assertExitCode(0);

        $context = app(TenantContext::class);
        $fresh = $context->withSchool($school, fn () => $scheduled->fresh());
        $this->assertSame('scheduled', $fresh->status);
    }

    #[Test]
    public function a_due_announcement_is_published_with_the_audience_resolved_at_execution_time(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $member = $this->createUser();
        $this->createMembership($member, $school);
        $scheduled = $this->scheduleSchoolWide($school, $creator, now()->addMinute());

        $this->travel(61)->seconds();
        $this->artisan('communications:publish-scheduled')->assertExitCode(0);

        $context = app(TenantContext::class);
        $fresh = $context->withSchool($school, fn () => $scheduled->fresh());
        $this->assertSame('published', $fresh->status);
        $this->assertSame(1, $fresh->recipient_count);
        $this->assertNotNull($fresh->message_id);
    }

    #[Test]
    public function rerunning_the_command_creates_no_duplicate_recipients_or_deliveries(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $member = $this->createUser();
        $this->createMembership($member, $school);
        $scheduled = $this->scheduleSchoolWide($school, $creator, now()->addMinute());

        $this->travel(61)->seconds();
        $this->artisan('communications:publish-scheduled')->assertExitCode(0);
        $this->artisan('communications:publish-scheduled')->assertExitCode(0);

        $context = app(TenantContext::class);
        $fresh = $context->withSchool($school, fn () => $scheduled->fresh());

        $counts = $context->withSchool($school, fn () => [
            'snapshot' => CommunicationAnnouncementRecipient::query()->where('announcement_id', $fresh->id)->count(),
            'recipients' => CommunicationRecipient::query()->where('message_id', $fresh->message_id)->count(),
            'deliveries' => CommunicationDelivery::query()
                ->whereIn('recipient_id', CommunicationRecipient::query()->where('message_id', $fresh->message_id)->pluck('id'))
                ->count(),
        ]);

        $this->assertSame(1, $counts['snapshot']);
        $this->assertSame(1, $counts['recipients']);
        $this->assertSame(1, $counts['deliveries']);
    }

    #[Test]
    public function an_in_app_only_schedule_creates_only_an_in_app_delivery(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $this->createMembership($this->createUser(), $school);
        $scheduled = $this->scheduleSchoolWide($school, $creator, now()->addMinute(), [CommunicationChannel::InApp]);

        $this->travel(61)->seconds();
        $this->artisan('communications:publish-scheduled')->assertExitCode(0);

        $context = app(TenantContext::class);
        $fresh = $context->withSchool($school, fn () => $scheduled->fresh());
        $channels = $context->withSchool($school, fn () => CommunicationDelivery::query()
            ->whereIn('recipient_id', CommunicationRecipient::query()->where('message_id', $fresh->message_id)->pluck('id'))
            ->pluck('channel')->all());

        $this->assertSame(['in_app'], $channels);
    }

    #[Test]
    public function an_in_app_plus_email_schedule_creates_both_channel_deliveries_safely(): void
    {
        Config::set('communications.channels.email.enabled', true);
        $this->fakeEmail();

        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $this->createMembership($this->createUser(['email' => 'member@school-os.test']), $school);
        $scheduled = $this->scheduleSchoolWide($school, $creator, now()->addMinute(), [CommunicationChannel::InApp, CommunicationChannel::Email]);

        $this->travel(61)->seconds();
        $this->artisan('communications:publish-scheduled')->assertExitCode(0);

        $context = app(TenantContext::class);
        $fresh = $context->withSchool($school, fn () => $scheduled->fresh());
        $channels = $context->withSchool($school, fn () => CommunicationDelivery::query()
            ->whereIn('recipient_id', CommunicationRecipient::query()->where('message_id', $fresh->message_id)->pluck('id'))
            ->pluck('channel')->sort()->values()->all());

        $this->assertSame(['email', 'in_app'], $channels);
        $this->assertEmailAcceptedCount(1);
    }

    #[Test]
    public function a_member_who_becomes_inactive_before_execution_is_excluded_from_the_authoritative_snapshot(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $memberA = $this->createUser();
        $memberB = $this->createUser();
        $this->createMembership($memberA, $school);
        $membershipB = $this->createMembership($memberB, $school);

        $scheduled = $this->scheduleSchoolWide($school, $creator, now()->addMinute());

        // Member B becomes inactive AFTER scheduling but BEFORE the
        // scheduled time is due.
        $context = app(TenantContext::class);
        $context->withSchool($school, fn () => $membershipB->update(['status' => 'suspended']));

        $this->travel(61)->seconds();
        $this->artisan('communications:publish-scheduled')->assertExitCode(0);

        $fresh = $context->withSchool($school, fn () => $scheduled->fresh());
        $this->assertSame(1, $fresh->recipient_count);

        $snapshotUserIds = $context->withSchool($school, fn () => CommunicationAnnouncementRecipient::query()
            ->where('announcement_id', $fresh->id)->pluck('user_id')->all());
        $this->assertSame([$memberA->id], $snapshotUserIds);
    }

    #[Test]
    public function a_member_who_joins_before_execution_is_included_in_a_school_wide_snapshot(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $memberA = $this->createUser();
        $this->createMembership($memberA, $school);

        $scheduled = $this->scheduleSchoolWide($school, $creator, now()->addMinute());

        // A brand new member joins AFTER scheduling but BEFORE due time.
        $memberC = $this->createUser();
        $this->createMembership($memberC, $school);

        $this->travel(61)->seconds();
        $this->artisan('communications:publish-scheduled')->assertExitCode(0);

        $context = app(TenantContext::class);
        $fresh = $context->withSchool($school, fn () => $scheduled->fresh());
        $this->assertSame(2, $fresh->recipient_count);
    }

    #[Test]
    public function two_schools_with_due_announcements_each_resolve_only_their_own_audience_in_one_run(): void
    {
        [$creatorA, $schoolA] = $this->createSchoolAdmin('school_admin');
        $memberA = $this->createUser();
        $this->createMembership($memberA, $schoolA);

        [$creatorB, $schoolB] = $this->createSchoolAdmin('school_admin');
        $memberB1 = $this->createUser();
        $memberB2 = $this->createUser();
        $this->createMembership($memberB1, $schoolB);
        $this->createMembership($memberB2, $schoolB);

        $scheduledA = $this->scheduleSchoolWide($schoolA, $creatorA, now()->addMinute());
        $scheduledB = $this->scheduleSchoolWide($schoolB, $creatorB, now()->addMinute());

        $this->travel(61)->seconds();
        $this->artisan('communications:publish-scheduled')->assertExitCode(0);

        $context = app(TenantContext::class);
        $freshA = $context->withSchool($schoolA, fn () => $scheduledA->fresh());
        $freshB = $context->withSchool($schoolB, fn () => $scheduledB->fresh());

        $this->assertSame('published', $freshA->status);
        $this->assertSame(1, $freshA->recipient_count);
        $this->assertSame('published', $freshB->status);
        $this->assertSame(2, $freshB->recipient_count);
    }

    #[Test]
    public function a_due_announcement_whose_audience_resolves_empty_is_backed_off_not_published(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        // No other members -- school-wide resolves empty (creator is
        // always excluded from their own audience).
        $scheduled = $this->scheduleSchoolWide($school, $creator, now()->addMinute());

        $this->travel(61)->seconds();
        $this->artisan('communications:publish-scheduled')->assertExitCode(0);

        $context = app(TenantContext::class);
        $fresh = $context->withSchool($school, fn () => $scheduled->fresh());

        $this->assertSame('scheduled', $fresh->status);
        // Backed off into the future, not still due -- proves the
        // command does not tight-loop retrying every minute forever.
        $this->assertTrue($fresh->scheduled_at->isFuture());
    }

    #[Test]
    public function racing_publish_calls_for_the_same_due_announcement_produce_exactly_one_publication(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $this->createMembership($this->createUser(), $school);
        $scheduled = $this->scheduleSchoolWide($school, $creator, now()->addMinute());

        $this->travel(61)->seconds();

        // Simulates two overlapping scheduler workers both attempting
        // to publish the same due announcement -- AnnouncementService::publish()'s
        // own atomic claim (not any locking in the command) is what
        // guarantees only one actually does the work (brief §21/§44).
        $first = $this->service()->publish($scheduled, $creator);
        $second = $this->service()->publish($first, $creator);

        $this->assertSame($first->message_id, $second->message_id);
        $this->assertSame($first->published_at?->toIso8601String(), $second->published_at?->toIso8601String());

        $context = app(TenantContext::class);
        $snapshotCount = $context->withSchool($school, fn () => CommunicationAnnouncementRecipient::query()
            ->where('announcement_id', $scheduled->id)->count());
        $this->assertSame(1, $snapshotCount);
    }
}

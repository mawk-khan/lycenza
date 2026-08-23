<?php

namespace Tests\Feature\Communications;

use App\Domain\Communications\Application\AnnouncementService;
use App\Domain\Communications\Application\CommunicationTemplateService;
use App\Domain\Communications\Application\Exceptions\InvalidAnnouncementTransitionException;
use App\Domain\Communications\Application\Exceptions\InvalidScheduledTimeException;
use App\Domain\Communications\Domain\CommunicationAudienceType;
use App\Domain\Communications\Domain\CommunicationPriority;
use App\Support\Tenancy\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCommunicationFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 5A.4 §42/§46: the Announcement scheduling state machine
 * (draft -> scheduled -> cancelled/published) and the template-
 * snapshot invariant, at the domain-service layer. Due-time
 * publication itself (the scheduler command) is covered separately in
 * PublishScheduledAnnouncementsTest.
 */
class AnnouncementSchedulingTest extends TestCase
{
    use CreatesCommunicationFixtures, CreatesTenancyFixtures;

    private function service(): AnnouncementService
    {
        return app(AnnouncementService::class);
    }

    private function templates(): CommunicationTemplateService
    {
        return app(CommunicationTemplateService::class);
    }

    private function draft($school, $creator, array $overrides = [])
    {
        return $this->service()->createDraft(
            $school, $creator,
            $overrides['title'] ?? 'T', $overrides['body'] ?? 'B',
            CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide,
        );
    }

    #[Test]
    public function a_draft_can_be_scheduled(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $announcement = $this->draft($school, $creator);

        $scheduledAt = now()->addDay();
        $scheduled = $this->service()->schedule($announcement, $creator, $scheduledAt);

        $this->assertSame('scheduled', $scheduled->status);
        $this->assertSame($scheduledAt->toIso8601String(), $scheduled->scheduled_at->toIso8601String());
        $this->assertSame($creator->id, $scheduled->scheduled_by_user_id);
    }

    #[Test]
    public function scheduling_a_past_timestamp_is_rejected(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $announcement = $this->draft($school, $creator);

        $this->expectException(InvalidScheduledTimeException::class);

        $this->service()->schedule($announcement, $creator, now()->subMinute());
    }

    #[Test]
    public function scheduling_an_already_published_announcement_is_rejected(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $this->createMembership($this->createUser(), $school);
        $announcement = $this->draft($school, $creator);
        $published = $this->service()->publish($announcement, $creator);

        $this->expectException(InvalidAnnouncementTransitionException::class);

        $this->service()->schedule($published, $creator, now()->addDay());
    }

    #[Test]
    public function a_scheduled_announcement_remains_editable(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $announcement = $this->draft($school, $creator);
        $scheduled = $this->service()->schedule($announcement, $creator, now()->addDay());

        $edited = $this->service()->updateDraft($scheduled, $creator, title: 'Edited While Scheduled');

        $this->assertSame('Edited While Scheduled', $edited->title);
        $this->assertSame('scheduled', $edited->status);
    }

    #[Test]
    public function rescheduling_changes_the_scheduled_time(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $announcement = $this->draft($school, $creator);
        $scheduled = $this->service()->schedule($announcement, $creator, now()->addDay());

        $newTime = now()->addDays(3);
        $rescheduled = $this->service()->reschedule($scheduled, $creator, $newTime);

        $this->assertSame($newTime->toIso8601String(), $rescheduled->scheduled_at->toIso8601String());
    }

    #[Test]
    public function rescheduling_a_non_scheduled_announcement_is_rejected(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $announcement = $this->draft($school, $creator);

        $this->expectException(InvalidAnnouncementTransitionException::class);

        $this->service()->reschedule($announcement, $creator, now()->addDay());
    }

    #[Test]
    public function rescheduling_to_a_past_timestamp_is_rejected(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $announcement = $this->draft($school, $creator);
        $scheduled = $this->service()->schedule($announcement, $creator, now()->addDay());

        $this->expectException(InvalidScheduledTimeException::class);

        $this->service()->reschedule($scheduled, $creator, now()->subHour());
    }

    #[Test]
    public function a_scheduled_announcement_can_be_cancelled(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $announcement = $this->draft($school, $creator);
        $scheduled = $this->service()->schedule($announcement, $creator, now()->addDay());

        $cancelled = $this->service()->cancel($scheduled, $creator);

        $this->assertSame('cancelled', $cancelled->status);
    }

    #[Test]
    public function a_cancelled_scheduled_announcement_is_never_published_by_the_scheduler(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $this->createMembership($this->createUser(), $school);
        $announcement = $this->draft($school, $creator);
        // Schedule in the near future, then cancel before it's due.
        $scheduled = $this->service()->schedule($announcement, $creator, now()->addSeconds(1));
        $this->service()->cancel($scheduled, $creator);

        $this->travel(2)->seconds();

        $this->artisan('communications:publish-scheduled')->assertExitCode(0);

        $context = app(TenantContext::class);
        $fresh = $context->withSchool($school, fn () => $scheduled->fresh());
        $this->assertSame('cancelled', $fresh->status);
    }

    #[Test]
    public function template_content_is_copied_into_the_announcement_and_later_template_edits_never_change_it(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');

        $template = $this->templates()->create($school, $creator, 'Closure Notice', 'School closes at 1 PM.', subject: 'Closure');

        $announcement = $this->service()->createDraft(
            $school, $creator, $template->subject, $template->body,
            CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide,
            sourceTemplateId: $template->id,
        );

        $this->assertSame('Closure', $announcement->title);
        $this->assertSame('School closes at 1 PM.', $announcement->body);
        $this->assertSame($template->id, $announcement->source_template_id);

        // Template is edited AFTER the announcement was created from it.
        $this->templates()->update($template, $creator, body: 'School closes at 2 PM.');

        $context = app(TenantContext::class);
        $stillOriginal = $context->withSchool($school, fn () => $announcement->fresh());

        $this->assertSame('School closes at 1 PM.', $stillOriginal->body);
    }
}

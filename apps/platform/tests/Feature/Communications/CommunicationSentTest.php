<?php

namespace Tests\Feature\Communications;

use App\Domain\Communications\Application\AnnouncementService;
use App\Domain\Communications\Application\CommunicationInboxItem;
use App\Domain\Communications\Application\CommunicationInboxReadModel;
use App\Domain\Communications\Application\CommunicationMessageService;
use App\Domain\Communications\Application\CommunicationThreadService;
use App\Domain\Communications\Domain\CommunicationAudienceType;
use App\Domain\Communications\Domain\CommunicationPriority;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCommunicationFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 5A.8 §16/§48 -- the Sent surface: communications authored by
 * the current membership only, never another member's.
 */
class CommunicationSentTest extends TestCase
{
    use CreatesCommunicationFixtures, CreatesTenancyFixtures;

    private function readModel(): CommunicationInboxReadModel
    {
        return app(CommunicationInboxReadModel::class);
    }

    #[Test]
    public function a_conversation_the_user_started_appears_in_sent(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $recipient = $this->createUser();
        $this->createMembership($recipient, $school);
        $thread = app(CommunicationThreadService::class)->createThread($school, $creator, 'direct', 'My thread', [$recipient->id]);
        app(CommunicationMessageService::class)->send($thread, $creator, 'Hi');

        $items = $this->readModel()->sent($school, $creator);

        $this->assertTrue($items->contains(fn (CommunicationInboxItem $i) => $i->id === $thread->id));
    }

    #[Test]
    public function a_conversation_someone_else_started_does_not_appear_in_the_users_sent(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $recipient = $this->createUser();
        $this->createMembership($recipient, $school);
        $thread = app(CommunicationThreadService::class)->createThread($school, $creator, 'direct', 'My thread', [$recipient->id]);
        app(CommunicationMessageService::class)->send($thread, $creator, 'Hi');

        $items = $this->readModel()->sent($school, $recipient);

        $this->assertFalse($items->contains(fn (CommunicationInboxItem $i) => $i->id === $thread->id));
    }

    #[Test]
    public function a_published_announcement_the_user_authored_appears_in_sent(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $this->createMembership($this->createUser(), $school);

        $announcement = app(AnnouncementService::class)->createDraft(
            $school, $creator, 'My Announcement', 'Body', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide,
        );
        $published = app(AnnouncementService::class)->publish($announcement, $creator);

        $items = $this->readModel()->sent($school, $creator);

        $this->assertTrue($items->contains(fn (CommunicationInboxItem $i) => $i->id === $published->id));
    }

    #[Test]
    public function another_members_announcement_does_not_appear_in_the_users_sent_regardless_of_capability(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $this->createMembership($this->createUser(), $school);

        $announcement = app(AnnouncementService::class)->createDraft(
            $school, $creator, 'Someone Elses Announcement', 'Body', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide,
        );
        $published = app(AnnouncementService::class)->publish($announcement, $creator);

        $manager = $this->createUser();
        $managerMembership = $this->createMembership($manager, $school);
        $this->assignSchoolRole($managerMembership, 'school_admin');

        $items = $this->readModel()->sent($school, $manager);

        $this->assertFalse($items->contains(fn (CommunicationInboxItem $i) => $i->id === $published->id));
    }

    #[Test]
    public function sent_items_are_ordered_by_latest_activity_and_bounded(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $recipient = $this->createUser();
        $this->createMembership($recipient, $school);

        for ($i = 0; $i < 7; $i++) {
            $thread = app(CommunicationThreadService::class)->createThread($school, $creator, 'direct', "Thread {$i}", [$recipient->id]);
            app(CommunicationMessageService::class)->send($thread, $creator, "Hi {$i}");
        }

        $items = $this->readModel()->sent($school, $creator, limit: 3);

        $this->assertCount(3, $items);
    }
}

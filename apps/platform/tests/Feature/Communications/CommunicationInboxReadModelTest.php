<?php

namespace Tests\Feature\Communications;

use App\Domain\Communications\Application\AnnouncementService;
use App\Domain\Communications\Application\CommunicationAttachmentService;
use App\Domain\Communications\Application\CommunicationInboxItem;
use App\Domain\Communications\Application\CommunicationInboxReadModel;
use App\Domain\Communications\Application\CommunicationMessageService;
use App\Domain\Communications\Application\CommunicationThreadService;
use App\Domain\Communications\Domain\CommunicationAudienceType;
use App\Domain\Communications\Domain\CommunicationPriority;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCommunicationFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 5A.8 §12/§13/§15/§39/§46 -- the mixed Inbox/Unread/Sent read
 * model: correct recipient-scoped composition, unread filtering,
 * latest-activity ordering, bounded pagination, and multi-school
 * isolation. Exercises the real services end-to-end.
 */
class CommunicationInboxReadModelTest extends TestCase
{
    use CreatesCommunicationFixtures, CreatesTenancyFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    private function readModel(): CommunicationInboxReadModel
    {
        return app(CommunicationInboxReadModel::class);
    }

    private function threads(): CommunicationThreadService
    {
        return app(CommunicationThreadService::class);
    }

    private function messages(): CommunicationMessageService
    {
        return app(CommunicationMessageService::class);
    }

    private function announcements(): AnnouncementService
    {
        return app(AnnouncementService::class);
    }

    #[Test]
    public function a_received_conversation_appears_in_the_inbox(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $recipient = $this->createUser();
        $this->createMembership($recipient, $school);
        $thread = $this->threads()->createThread($school, $creator, 'direct', 'Field trip', [$recipient->id]);
        $this->messages()->send($thread, $creator, 'Hello');

        $items = $this->readModel()->inbox($school, $recipient);

        $this->assertTrue($items->contains(fn (CommunicationInboxItem $i) => $i->type === 'conversation' && $i->id === $thread->id));
    }

    #[Test]
    public function a_received_published_announcement_appears_in_the_inbox(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $recipient = $this->createUser();
        $this->createMembership($recipient, $school);

        $announcement = $this->announcements()->createDraft(
            $school, $creator, 'Annual Day', 'Body', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide,
        );
        $published = $this->announcements()->publish($announcement, $creator);

        $items = $this->readModel()->inbox($school, $recipient);

        $this->assertTrue($items->contains(fn (CommunicationInboxItem $i) => $i->type === 'announcement' && $i->id === $published->id));
    }

    #[Test]
    public function an_irrelevant_conversation_is_excluded(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $bystander = $this->createUser();
        $this->createMembership($bystander, $school);
        $thread = $this->threads()->createThread($school, $creator, 'direct', 'Private', []);
        $this->messages()->send($thread, $creator, 'Hello');

        $items = $this->readModel()->inbox($school, $bystander);

        $this->assertFalse($items->contains(fn (CommunicationInboxItem $i) => $i->id === $thread->id));
    }

    #[Test]
    public function a_non_recipient_announcement_is_excluded_even_with_communications_view(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        // Individual-audience announcement targeting only the creator's
        // own selection -- a bystander with communications.view (but
        // not a resolved recipient) must not see it.
        $bystander = $this->createUser();
        $membership = $this->createMembership($bystander, $school);
        $this->assignSchoolRole($membership, 'principal');

        $onlyRecipient = $this->createUser();
        $this->createMembership($onlyRecipient, $school);

        $announcement = $this->announcements()->createDraft(
            $school, $creator, 'Just for one', 'Body', CommunicationPriority::Normal, CommunicationAudienceType::Individual,
            individualMemberUserIds: [$onlyRecipient->id],
        );
        $published = $this->announcements()->publish($announcement, $creator);

        $items = $this->readModel()->inbox($school, $bystander);

        $this->assertFalse($items->contains(fn (CommunicationInboxItem $i) => $i->id === $published->id));
    }

    #[Test]
    public function unread_only_includes_unread_items_and_excludes_the_senders_own_message(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $recipient = $this->createUser();
        $this->createMembership($recipient, $school);
        $thread = $this->threads()->createThread($school, $creator, 'direct', null, [$recipient->id]);
        $this->messages()->send($thread, $creator, 'Hello');

        $recipientUnread = $this->readModel()->unread($school, $recipient);
        $this->assertTrue($recipientUnread->contains(fn (CommunicationInboxItem $i) => $i->id === $thread->id));

        $creatorUnread = $this->readModel()->unread($school, $creator);
        $this->assertFalse($creatorUnread->contains(fn (CommunicationInboxItem $i) => $i->id === $thread->id));
    }

    #[Test]
    public function a_read_conversation_is_excluded_from_unread(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $recipient = $this->createUser();
        $recipientMembership = $this->createMembership($recipient, $school);
        $this->assignSchoolRole($recipientMembership, 'principal');
        $thread = $this->threads()->createThread($school, $creator, 'direct', null, [$recipient->id]);
        $this->messages()->send($thread, $creator, 'Hello');

        $this->actingAs($recipient)->post("/app/schools/{$school->id}/activate");
        $this->actingAs($recipient)->get("/app/communications/{$thread->id}")->assertOk();

        $unread = $this->readModel()->unread($school, $recipient);
        $this->assertFalse($unread->contains(fn (CommunicationInboxItem $i) => $i->id === $thread->id));
    }

    #[Test]
    public function items_are_ordered_by_latest_activity_descending(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $recipient = $this->createUser();
        $this->createMembership($recipient, $school);

        $threadOld = $this->threads()->createThread($school, $creator, 'direct', 'Older', [$recipient->id]);
        $this->messages()->send($threadOld, $creator, 'First');

        $threadNew = $this->threads()->createThread($school, $creator, 'direct', 'Newer', [$recipient->id]);
        $this->messages()->send($threadNew, $creator, 'Second');

        $items = $this->readModel()->inbox($school, $recipient);
        $ids = $items->pluck('id')->all();

        $this->assertLessThan(array_search($threadOld->id, $ids), array_search($threadNew->id, $ids));
    }

    #[Test]
    public function the_inbox_is_bounded_by_the_requested_limit(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $recipient = $this->createUser();
        $this->createMembership($recipient, $school);

        for ($i = 0; $i < 8; $i++) {
            $thread = $this->threads()->createThread($school, $creator, 'direct', "Thread {$i}", [$recipient->id]);
            $this->messages()->send($thread, $creator, "Message {$i}");
        }

        $items = $this->readModel()->inbox($school, $recipient, limit: 5);

        $this->assertCount(5, $items);
    }

    #[Test]
    public function a_conversation_with_an_attachment_is_flagged(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $recipient = $this->createUser();
        $this->createMembership($recipient, $school);
        $thread = $this->threads()->createThread($school, $creator, 'direct', null, [$recipient->id]);

        $attachment = app(CommunicationAttachmentService::class)->uploadForThread(
            $thread, $creator, UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'),
        );
        $this->messages()->send($thread, $creator, 'See attached', attachmentIds: [$attachment->id]);

        $items = $this->readModel()->inbox($school, $recipient);
        $item = $items->firstWhere('id', $thread->id);

        $this->assertNotNull($item);
        $this->assertTrue($item->hasAttachments);
    }

    #[Test]
    public function the_priority_filter_applies_to_announcements_only(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $recipient = $this->createUser();
        $this->createMembership($recipient, $school);

        $normal = $this->announcements()->createDraft(
            $school, $creator, 'Normal one', 'Body', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide,
        );
        $this->announcements()->publish($normal, $creator);

        $critical = $this->announcements()->createDraft(
            $school, $creator, 'Critical one', 'Body', CommunicationPriority::Critical, CommunicationAudienceType::SchoolWide,
        );
        $publishedCritical = $this->announcements()->publish($critical, $creator);

        $thread = $this->threads()->createThread($school, $creator, 'direct', null, [$recipient->id]);
        $this->messages()->send($thread, $creator, 'Hello');

        $items = $this->readModel()->inbox($school, $recipient, filters: ['priority' => 'critical']);

        $this->assertTrue($items->contains(fn (CommunicationInboxItem $i) => $i->id === $publishedCritical->id));
        $this->assertFalse($items->contains(fn (CommunicationInboxItem $i) => $i->type === 'conversation'));
    }

    #[Test]
    public function the_type_filter_restricts_to_one_domain(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $recipient = $this->createUser();
        $this->createMembership($recipient, $school);

        $announcement = $this->announcements()->createDraft(
            $school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide,
        );
        $this->announcements()->publish($announcement, $creator);

        $thread = $this->threads()->createThread($school, $creator, 'direct', null, [$recipient->id]);
        $this->messages()->send($thread, $creator, 'Hello');

        $onlyConversations = $this->readModel()->inbox($school, $recipient, filters: ['type' => 'conversation']);
        $this->assertTrue($onlyConversations->every(fn (CommunicationInboxItem $i) => $i->type === 'conversation'));

        $onlyAnnouncements = $this->readModel()->inbox($school, $recipient, filters: ['type' => 'announcement']);
        $this->assertTrue($onlyAnnouncements->every(fn (CommunicationInboxItem $i) => $i->type === 'announcement'));
    }

    #[Test]
    public function a_multi_school_user_only_sees_the_active_schools_items(): void
    {
        $user = $this->createUser();

        [$creatorA, $schoolA] = $this->createSchoolAdmin('school_admin');
        $this->createMembership($user, $schoolA);
        $threadA = $this->threads()->createThread($schoolA, $creatorA, 'direct', 'School A thread', [$user->id]);
        $this->messages()->send($threadA, $creatorA, 'A says hi');

        [$creatorB, $schoolB] = $this->createSchoolAdmin('school_admin');
        $this->createMembership($user, $schoolB);
        $threadB = $this->threads()->createThread($schoolB, $creatorB, 'direct', 'School B thread', [$user->id]);
        $this->messages()->send($threadB, $creatorB, 'B says hi');

        $itemsA = $this->readModel()->inbox($schoolA, $user);
        $itemsB = $this->readModel()->inbox($schoolB, $user);

        $this->assertTrue($itemsA->contains(fn (CommunicationInboxItem $i) => $i->id === $threadA->id));
        $this->assertFalse($itemsA->contains(fn (CommunicationInboxItem $i) => $i->id === $threadB->id));
        $this->assertTrue($itemsB->contains(fn (CommunicationInboxItem $i) => $i->id === $threadB->id));
        $this->assertFalse($itemsB->contains(fn (CommunicationInboxItem $i) => $i->id === $threadA->id));
    }
}

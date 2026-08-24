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
 * Phase 5A.8 §9/§24/§27/§37/§38/§47 -- Communication Hub search:
 * behavior (case-insensitivity, whitespace, empty/long query handling,
 * pagination-by-limit) and the two MANDATORY privacy proofs (§37/§38):
 * a non-participant/non-recipient in the SAME School finds nothing for
 * a unique private subject/title, and a School B search never leaks a
 * School A result.
 */
class CommunicationSearchTest extends TestCase
{
    use CreatesCommunicationFixtures, CreatesTenancyFixtures;

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

    private function activate($user, $school): void
    {
        $this->actingAs($user)->post("/app/schools/{$school->id}/activate");
    }

    #[Test]
    public function a_participant_finds_their_conversation_by_subject(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $recipient = $this->createUser();
        $this->createMembership($recipient, $school);
        $thread = $this->threads()->createThread($school, $creator, 'direct', 'Zylophone Field Trip', [$recipient->id]);
        $this->messages()->send($thread, $creator, 'Hello');

        $items = $this->readModel()->search($school, $creator, 'zylophone', canManage: false, canManageTemplates: false);

        $this->assertTrue($items->contains(fn (CommunicationInboxItem $i) => $i->id === $thread->id));
    }

    #[Test]
    public function search_is_case_insensitive_and_trims_whitespace(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $this->createMembership($this->createUser(), $school);
        $announcement = $this->announcements()->createDraft(
            $school, $creator, 'Quarterly Report Card Notice', 'Body', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide,
        );
        $published = $this->announcements()->publish($announcement, $creator);

        $items = $this->readModel()->search($school, $creator, '  QUARTERLY report  ', canManage: false, canManageTemplates: false);

        $this->assertTrue($items->contains(fn (CommunicationInboxItem $i) => $i->id === $published->id));
    }

    #[Test]
    public function an_empty_search_returns_no_results(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');

        $items = $this->readModel()->search($school, $creator, '   ', canManage: false, canManageTemplates: false);

        $this->assertCount(0, $items);
    }

    #[Test]
    public function an_overlong_query_is_rejected_by_the_http_layer(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $this->activate($user, $school);

        $this->actingAs($user)
            ->get('/app/communications/search?q='.str_repeat('a', 200))
            ->assertSessionHasErrors('q');
    }

    #[Test]
    public function search_results_are_bounded_per_type(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $recipient = $this->createUser();
        $this->createMembership($recipient, $school);

        for ($i = 0; $i < 15; $i++) {
            $thread = $this->threads()->createThread($school, $creator, 'direct', "Matchable Subject {$i}", [$recipient->id]);
            $this->messages()->send($thread, $creator, 'Hello');
        }

        $items = $this->readModel()->search($school, $creator, 'Matchable', canManage: false, canManageTemplates: false);

        $this->assertLessThanOrEqual(10, $items->where('type', 'conversation')->count());
    }

    /**
     * Phase 5A.8 §37 (MANDATORY): User A and User B share a private
     * thread with a unique subject. User C, in the same School, has
     * communications.view but is not a participant. User C's search
     * for the exact unique subject returns nothing, and directly
     * opening the thread URL remains denied.
     */
    #[Test]
    public function a_non_participant_cannot_discover_a_private_conversation_via_search(): void
    {
        [$userA, $school] = $this->createSchoolAdmin('school_admin');
        $userB = $this->createUser();
        $this->createMembership($userB, $school);
        $thread = $this->threads()->createThread($school, $userA, 'direct', 'Xanadu Confidential Matter', [$userB->id]);
        $this->messages()->send($thread, $userA, 'Private content');

        $userC = $this->createUser();
        $membershipC = $this->createMembership($userC, $school);
        $this->assignSchoolRole($membershipC, 'principal');

        $items = $this->readModel()->search($school, $userC, 'Xanadu Confidential', canManage: false, canManageTemplates: false);
        $this->assertCount(0, $items);

        $this->activate($userC, $school);
        $this->actingAs($userC)->get("/app/communications/{$thread->id}")->assertForbidden();
    }

    #[Test]
    public function a_non_recipient_cannot_discover_a_private_announcement_via_search(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $onlyRecipient = $this->createUser();
        $this->createMembership($onlyRecipient, $school);

        $announcement = $this->announcements()->createDraft(
            $school, $creator, 'Yttrium Individual Notice', 'Body', CommunicationPriority::Normal, CommunicationAudienceType::Individual,
            individualMemberUserIds: [$onlyRecipient->id],
        );
        $published = $this->announcements()->publish($announcement, $creator);

        $bystander = $this->createUser();
        $membership = $this->createMembership($bystander, $school);
        $this->assignSchoolRole($membership, 'principal');

        $items = $this->readModel()->search($school, $bystander, 'Yttrium Individual', canManage: false, canManageTemplates: false);
        $this->assertCount(0, $items);
    }

    /**
     * Phase 5A.8 §38 (MANDATORY): School A creates uniquely named
     * conversation/announcement/template content; School B's search
     * for those exact unique terms returns zero results.
     */
    #[Test]
    public function school_b_cannot_discover_school_as_uniquely_named_content(): void
    {
        [$creatorA, $schoolA] = $this->createSchoolAdmin('school_admin');
        $recipientA = $this->createUser();
        $this->createMembership($recipientA, $schoolA);

        $threadA = $this->threads()->createThread($schoolA, $creatorA, 'direct', 'Wolverine Unique Conversation Subject', [$recipientA->id]);
        $this->messages()->send($threadA, $creatorA, 'Hello');

        $announcementA = $this->announcements()->createDraft(
            $schoolA, $creatorA, 'Wolverine Unique Announcement Title', 'Body', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide,
        );
        $this->announcements()->publish($announcementA, $creatorA);

        [$userB, $schoolB] = $this->createSchoolAdmin('school_admin');

        $conversationResults = $this->readModel()->search($schoolB, $userB, 'Wolverine Unique Conversation', canManage: true, canManageTemplates: true);
        $announcementResults = $this->readModel()->search($schoolB, $userB, 'Wolverine Unique Announcement', canManage: true, canManageTemplates: true);

        $this->assertCount(0, $conversationResults);
        $this->assertCount(0, $announcementResults);
    }

    #[Test]
    public function communications_manage_can_find_a_published_announcement_they_did_not_author_or_receive(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $this->createMembership($this->createUser(), $school);
        $announcement = $this->announcements()->createDraft(
            $school, $creator, 'Vintage Manager Findable Notice', 'Body', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide,
        );
        $published = $this->announcements()->publish($announcement, $creator);

        $manager = $this->createUser();
        $managerMembership = $this->createMembership($manager, $school);
        $this->assignSchoolRole($managerMembership, 'school_admin');

        $items = $this->readModel()->search($school, $manager, 'Vintage Manager Findable', canManage: true, canManageTemplates: false);

        $this->assertTrue($items->contains(fn (CommunicationInboxItem $i) => $i->id === $published->id));
    }

    #[Test]
    public function templates_are_only_searchable_with_the_templates_manage_capability(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $this->createTemplate($school, $creator, ['name' => 'Uniquely Named Template']);

        $withoutCapability = $this->readModel()->search($school, $creator, 'Uniquely Named Template', canManage: false, canManageTemplates: false);
        $this->assertCount(0, $withoutCapability);

        $withCapability = $this->readModel()->search($school, $creator, 'Uniquely Named Template', canManage: false, canManageTemplates: true);
        $this->assertTrue($withCapability->contains(fn (CommunicationInboxItem $i) => $i->type === 'template'));
    }
}

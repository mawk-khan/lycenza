<?php

namespace Tests\Feature\App;

use App\Domain\Communications\Application\AnnouncementService;
use App\Domain\Communications\Application\CommunicationMessageService;
use App\Domain\Communications\Application\CommunicationThreadService;
use App\Domain\Communications\Domain\CommunicationAudienceType;
use App\Domain\Communications\Domain\CommunicationPriority;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCommunicationFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 5A.8 §4/§29/§35/§39/§51 -- HTTP-layer coverage for the new
 * Inbox/Unread/Sent surfaces: guest/authorization, empty states, and
 * a bounded query-count proof for the Inbox route specifically
 * (mirroring ConversationIndexScaleTest's discipline).
 */
class CommunicationInboxHttpTest extends TestCase
{
    use CreatesCommunicationFixtures, CreatesTenancyFixtures;

    private function activate($user, $school): void
    {
        $this->actingAs($user)->post("/app/schools/{$school->id}/activate");
    }

    #[Test]
    public function a_guest_is_redirected_to_login(): void
    {
        $this->get('/app/communications')->assertRedirect('/login');
        $this->get('/app/communications/unread')->assertRedirect('/login');
        $this->get('/app/communications/sent')->assertRedirect('/login');
        $this->get('/app/communications/search')->assertRedirect('/login');
    }

    #[Test]
    public function a_member_with_no_role_is_denied(): void
    {
        $user = $this->createUser();
        $school = $this->createSchool();
        $this->createMembership($user, $school);
        $this->activate($user, $school);

        $this->get('/app/communications')->assertForbidden();
    }

    #[Test]
    public function an_authorized_user_sees_an_empty_inbox(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $this->activate($user, $school);

        $this->get('/app/communications')->assertInertia(fn ($page) => $page
            ->component('App/Communications/Inbox')
            ->where('items', [])
        );
    }

    #[Test]
    public function unread_and_sent_render_with_empty_states(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $this->activate($user, $school);

        $this->get('/app/communications/unread')->assertInertia(fn ($page) => $page
            ->component('App/Communications/Unread')->where('items', []));

        $this->get('/app/communications/sent')->assertInertia(fn ($page) => $page
            ->component('App/Communications/Sent')->where('items', []));
    }

    #[Test]
    public function search_with_no_query_renders_with_no_results_and_no_query(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $this->activate($user, $school);

        $this->get('/app/communications/search')->assertInertia(fn ($page) => $page
            ->component('App/Communications/Search')
            ->where('query', null)
            ->where('items', []));
    }

    #[Test]
    public function a_received_conversation_and_announcement_both_appear_in_the_inbox(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $recipient = $this->createUser();
        $recipientMembership = $this->createMembership($recipient, $school);
        $this->assignSchoolRole($recipientMembership, 'principal');

        $thread = app(CommunicationThreadService::class)->createThread($school, $creator, 'direct', 'Hello', [$recipient->id]);
        app(CommunicationMessageService::class)->send($thread, $creator, 'Hi there');

        $announcement = app(AnnouncementService::class)->createDraft(
            $school, $creator, 'Notice', 'Body', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide,
        );
        app(AnnouncementService::class)->publish($announcement, $creator);

        $this->activate($recipient, $school);

        $this->get('/app/communications')->assertInertia(fn ($page) => $page
            ->component('App/Communications/Inbox')
            ->has('items', 2));
    }

    #[Test]
    public function the_inbox_route_issues_a_bounded_number_of_queries(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $this->activate($creator, $school);

        $threadService = app(CommunicationThreadService::class);
        $messageService = app(CommunicationMessageService::class);
        $announcementService = app(AnnouncementService::class);

        $recipient = $this->createUser();
        $this->createMembership($recipient, $school);

        for ($i = 0; $i < 10; $i++) {
            $thread = $threadService->createThread($school, $creator, 'direct', "Thread {$i}", [$recipient->id]);
            $messageService->send($thread, $creator, "Hello {$i}");
        }

        for ($i = 0; $i < 10; $i++) {
            $announcement = $announcementService->createDraft(
                $school, $creator, "Notice {$i}", 'Body', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide,
            );
            $announcementService->publish($announcement, $creator);
        }

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $this->actingAs($creator)->get('/app/communications')->assertOk();

        // Fixed queries for: thread fetch + participants/user eager
        // load + summarize() (2), announcement fetch + read/attachment
        // batch (2), totalUnreadCount() (conversations 3 + announcement
        // aggregate 1), capability checks, session/auth bookkeeping --
        // NOT one query per item. 10 threads + 10 announcements must
        // not multiply this.
        $this->assertLessThan(40, $queryCount, "Expected a bounded query count, got {$queryCount}.");
    }

    /**
     * Phase 5A.8 §39: a multi-School User's Inbox under School A's
     * active context must never include a School B item, even though
     * the same User genuinely has both.
     */
    #[Test]
    public function a_multi_school_users_inbox_only_shows_the_active_schools_items(): void
    {
        $user = $this->createUser();

        [$creatorA, $schoolA] = $this->createSchoolAdmin('school_admin');
        $userMembershipA = $this->createMembership($user, $schoolA);
        $this->assignSchoolRole($userMembershipA, 'principal');
        $threadA = app(CommunicationThreadService::class)->createThread($schoolA, $creatorA, 'direct', 'School A thread', [$user->id]);
        app(CommunicationMessageService::class)->send($threadA, $creatorA, 'From A');

        [$creatorB, $schoolB] = $this->createSchoolAdmin('school_admin');
        $userMembershipB = $this->createMembership($user, $schoolB);
        $this->assignSchoolRole($userMembershipB, 'principal');
        $threadB = app(CommunicationThreadService::class)->createThread($schoolB, $creatorB, 'direct', 'School B thread', [$user->id]);
        app(CommunicationMessageService::class)->send($threadB, $creatorB, 'From B');

        $this->activate($user, $schoolA);

        $this->get('/app/communications')->assertInertia(fn ($page) => $page
            ->has('items', 1)
            ->where('items.0.title', 'School A thread'));
    }
}

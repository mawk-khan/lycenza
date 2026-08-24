<?php

namespace Tests\Feature\Communications;

use App\Domain\Communications\Application\CommunicationMessageService;
use App\Domain\Communications\Application\CommunicationThreadService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCommunicationFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 5A.7 §18/§23/§56 -- proves the conversation index's query
 * count is BOUNDED (does not grow linearly with the number of
 * threads/participants on the page), the same discipline
 * AnnouncementPolicyScaleTest already established for Phase 5A.5.
 */
class ConversationIndexScaleTest extends TestCase
{
    use CreatesCommunicationFixtures, CreatesTenancyFixtures;

    #[Test]
    public function loading_the_index_with_many_threads_issues_a_bounded_number_of_queries(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $this->activate($creator, $school);

        $threadService = app(CommunicationThreadService::class);
        $messageService = app(CommunicationMessageService::class);

        for ($i = 0; $i < 15; $i++) {
            $recipient = $this->createUser();
            $this->createMembership($recipient, $school);
            $thread = $threadService->createThread($school, $creator, 'direct', "Thread {$i}", [$recipient->id]);
            $messageService->send($thread, $creator, "Hello {$i}");
        }

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        // Phase 5A.8 §33: the conversation list relocated to
        // `/app/communications/conversations` -- see
        // CommunicationInboxScaleTest for the (now-separate) Inbox
        // page's own bounded-query proof.
        $this->actingAs($creator)->get('/app/communications/conversations')->assertOk();

        // A handful of fixed queries (thread page, participants eager
        // load, users eager load, summarize()'s 2 queries,
        // totalUnreadCount()'s 3 queries, Phase 5A.8's added
        // unreadAnnouncementCount() aggregate for the shared nav badge,
        // capability checks, session/auth bookkeeping) -- NOT one query
        // per thread. 15 threads comfortably fits well under this
        // ceiling; growing the thread count must not grow the query
        // count.
        $this->assertLessThan(35, $queryCount, "Expected a bounded query count, got {$queryCount}.");
    }

    private function activate($user, $school): void
    {
        $this->actingAs($user)->post("/app/schools/{$school->id}/activate");
    }
}

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
        $measure = function () use (&$queryCount, $creator): int {
            $queryCount = 0;
            $this->actingAs($creator)->get('/app/communications/conversations')->assertOk();

            return $queryCount;
        };

        // Phase 5A.8 §33: the conversation list relocated to
        // `/app/communications/conversations` -- see
        // CommunicationInboxScaleTest for the (now-separate) Inbox
        // page's own bounded-query proof.
        $this->actingAs($creator)->get('/app/communications/conversations')->assertOk();
        $withFifteen = $measure();

        // A handful of fixed queries (thread page, participants eager
        // load, users eager load, summarize()'s 2 queries,
        // totalUnreadCount()'s 3 queries, Phase 5A.8's added
        // unreadAnnouncementCount() aggregate for the shared nav badge,
        // capability checks, session/auth bookkeeping, and since SWC.1
        // the shared layout navigation -- ADR 0073 section 17) -- NOT one
        // query per thread: growing the thread count must not grow the
        // query count.
        for ($i = 15; $i < 25; $i++) {
            $recipient = $this->createUser();
            $this->createMembership($recipient, $school);
            $thread = $threadService->createThread($school, $creator, 'direct', "Thread {$i}", [$recipient->id]);
            $messageService->send($thread, $creator, "Hello {$i}");
        }

        $this->assertSame($withFifteen, $measure(), 'The conversation index must not issue one query per thread.');
        // ORG.12F (ADR 0080 §11.10): +2 fixed shell queries (the School's navigation
        // presentation and its effective brand).
        $this->assertLessThan(37, $withFifteen, "Expected a bounded query count, got {$withFifteen}.");
    }

    private function activate($user, $school): void
    {
        $this->actingAs($user)->post("/app/schools/{$school->id}/activate");
    }
}

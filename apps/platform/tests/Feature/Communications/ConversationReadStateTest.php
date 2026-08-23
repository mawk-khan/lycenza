<?php

namespace Tests\Feature\Communications;

use App\Domain\Communications\Application\CommunicationMessageService;
use App\Domain\Communications\Application\CommunicationThreadService;
use App\Domain\Communications\Application\ConversationReadModel;
use App\Domain\Communications\Infrastructure\CommunicationThreadParticipant;
use App\Support\Tenancy\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCommunicationFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 5A.7 §21/§22/§23/§53 -- read/unread derivation
 * (App\Domain\Communications\Application\ConversationReadModel),
 * mark-read-on-open, and multi-school read-cursor isolation. Exercises
 * the real services end-to-end, not raw model writes.
 */
class ConversationReadStateTest extends TestCase
{
    use CreatesCommunicationFixtures, CreatesTenancyFixtures;

    private function threadService(): CommunicationThreadService
    {
        return app(CommunicationThreadService::class);
    }

    private function messageService(): CommunicationMessageService
    {
        return app(CommunicationMessageService::class);
    }

    private function readModel(): ConversationReadModel
    {
        return app(ConversationReadModel::class);
    }

    private function participantFor($thread, $school, string $userId): CommunicationThreadParticipant
    {
        return app(TenantContext::class)->withSchool(
            $school,
            fn () => $thread->participants()->where('user_id', $userId)->firstOrFail(),
        );
    }

    #[Test]
    public function a_thread_is_unread_for_a_recipient_who_has_never_opened_it(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $recipient = $this->createUser();
        $this->createMembership($recipient, $school);
        $thread = $this->threadService()->createThread($school, $creator, 'direct', null, [$recipient->id]);

        $this->messageService()->send($thread, $creator, 'Hello');

        $summary = $this->readModel()->summarize($school, [$thread->id], $recipient->id)->get($thread->id);
        $this->assertTrue($summary->unread);
    }

    #[Test]
    public function a_thread_is_not_unread_for_its_sender(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $recipient = $this->createUser();
        $this->createMembership($recipient, $school);
        $thread = $this->threadService()->createThread($school, $creator, 'direct', null, [$recipient->id]);

        $this->messageService()->send($thread, $creator, 'Hello');

        $summary = $this->readModel()->summarize($school, [$thread->id], $creator->id)->get($thread->id);
        $this->assertFalse($summary->unread);
    }

    #[Test]
    public function opening_the_thread_marks_it_read_for_the_current_participant_only(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $recipient = $this->createUser();
        $recipientMembership = $this->createMembership($recipient, $school);
        $this->assignSchoolRole($recipientMembership, 'principal');
        $thread = $this->threadService()->createThread($school, $creator, 'direct', null, [$recipient->id]);
        $this->messageService()->send($thread, $creator, 'Hello');

        $this->actingAs($recipient)->post("/app/schools/{$school->id}/activate");
        $this->actingAs($recipient)->get("/app/communications/{$thread->id}")->assertOk();

        $recipientSummary = $this->readModel()->summarize($school, [$thread->id], $recipient->id)->get($thread->id);
        $this->assertFalse($recipientSummary->unread);

        // The creator's own cursor is untouched by the recipient's visit.
        $this->messageService()->send($thread, $recipient, 'Reply');
        $creatorSummary = $this->readModel()->summarize($school, [$thread->id], $creator->id)->get($thread->id);
        $this->assertTrue($creatorSummary->unread);
    }

    #[Test]
    public function a_newer_reply_makes_a_previously_read_thread_unread_again(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $recipient = $this->createUser();
        $this->createMembership($recipient, $school);
        $thread = $this->threadService()->createThread($school, $creator, 'direct', null, [$recipient->id]);
        $this->messageService()->send($thread, $creator, 'Hello');

        $participant = $this->participantFor($thread, $school, $recipient->id);
        $this->threadService()->markRead($participant);

        $this->assertFalse($this->readModel()->summarize($school, [$thread->id], $recipient->id)->get($thread->id)->unread);

        $this->messageService()->send($thread, $creator, 'Second message');

        $this->assertTrue($this->readModel()->summarize($school, [$thread->id], $recipient->id)->get($thread->id)->unread);
    }

    #[Test]
    public function a_bystander_cannot_mark_someone_elses_thread_read_by_viewing_it(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $recipient = $this->createUser();
        $this->createMembership($recipient, $school);
        $thread = $this->threadService()->createThread($school, $creator, 'direct', null, [$recipient->id]);
        $this->messageService()->send($thread, $creator, 'Hello');

        $manager = $this->createUser();
        $membership = $this->createMembership($manager, $school);
        $this->assignSchoolRole($membership, 'school_admin');
        $this->actingAs($manager)->post("/app/schools/{$school->id}/activate");
        $this->actingAs($manager)->get("/app/communications/{$thread->id}")->assertOk();

        // The recipient's own cursor is untouched by the manager's visit.
        $summary = $this->readModel()->summarize($school, [$thread->id], $recipient->id)->get($thread->id);
        $this->assertTrue($summary->unread);
    }

    #[Test]
    public function a_multi_school_user_has_independent_read_state_per_school(): void
    {
        $user = $this->createUser();

        [$creatorA, $schoolA] = $this->createSchoolAdmin('school_admin');
        $this->createMembership($user, $schoolA);
        $threadA = $this->threadService()->createThread($schoolA, $creatorA, 'direct', null, [$user->id]);
        $this->messageService()->send($threadA, $creatorA, 'A says hi');

        [$creatorB, $schoolB] = $this->createSchoolAdmin('school_admin');
        $this->createMembership($user, $schoolB);
        $threadB = $this->threadService()->createThread($schoolB, $creatorB, 'direct', null, [$user->id]);
        $this->messageService()->send($threadB, $creatorB, 'B says hi');

        $participantA = $this->participantFor($threadA, $schoolA, $user->id);
        $this->threadService()->markRead($participantA);

        $this->assertFalse($this->readModel()->summarize($schoolA, [$threadA->id], $user->id)->get($threadA->id)->unread);
        // School B's thread remains unread -- reading School A's
        // thread must never cross-mark School B's.
        $this->assertTrue($this->readModel()->summarize($schoolB, [$threadB->id], $user->id)->get($threadB->id)->unread);
    }

    #[Test]
    public function the_hub_nav_reports_a_bounded_total_unread_count(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $recipient = $this->createUser();
        $this->createMembership($recipient, $school);

        $threadOne = $this->threadService()->createThread($school, $creator, 'direct', null, [$recipient->id]);
        $threadTwo = $this->threadService()->createThread($school, $creator, 'direct', null, [$recipient->id]);
        $this->messageService()->send($threadOne, $creator, 'One');
        $this->messageService()->send($threadTwo, $creator, 'Two');

        $this->assertSame(2, $this->readModel()->totalUnreadCount($school, $recipient->id));

        $participantOne = $this->participantFor($threadOne, $school, $recipient->id);
        $this->threadService()->markRead($participantOne);

        $this->assertSame(1, $this->readModel()->totalUnreadCount($school, $recipient->id));
    }
}

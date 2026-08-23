<?php

namespace Tests\Feature\App;

use App\Domain\Communications\Application\CommunicationAttachmentService;
use App\Domain\Communications\Application\CommunicationMessageService;
use App\Domain\Communications\Application\CommunicationThreadService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCommunicationFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 5A.7 §9/§10/§15/§29/§55 -- HTTP-layer coverage for the parts
 * of Conversation Messaging Completion that are new surface area, not
 * already covered by tests/Feature/App/CommunicationHubTest.php:
 * participant search, archive/unarchive, and the thread-scoped
 * attachment routes.
 */
class CommunicationConversationHttpTest extends TestCase
{
    use CreatesCommunicationFixtures, CreatesTenancyFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    private function activate($user, $school): void
    {
        $this->actingAs($user)->post("/app/schools/{$school->id}/activate");
    }

    #[Test]
    public function participant_search_returns_only_same_school_active_members_excluding_the_actor(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $teacher = $this->createUser(['name' => 'Alice Teacher']);
        $this->createMembership($teacher, $school);

        $otherSchoolUser = $this->createUser(['name' => 'Alice Outsider']);
        $this->createMembership($otherSchoolUser, $this->createSchool());

        $this->activate($admin, $school);

        $response = $this->actingAs($admin)->get('/app/communications/participants/search?q=Alice');
        $response->assertOk();
        $names = collect($response->json('participants'))->pluck('name')->all();

        $this->assertContains('Alice Teacher', $names);
        $this->assertNotContains('Alice Outsider', $names);
        $this->assertNotContains($admin->name, $names);
    }

    #[Test]
    public function participant_search_requires_the_send_capability(): void
    {
        $user = $this->createUser();
        $school = $this->createSchool();
        $this->createMembership($user, $school);
        $this->activate($user, $school);

        $this->actingAs($user)->get('/app/communications/participants/search')->assertForbidden();
    }

    #[Test]
    public function a_participant_can_archive_and_unarchive_a_thread_from_their_own_view_only(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $recipient = $this->createUser();
        $recipientMembership = $this->createMembership($recipient, $school);
        $this->assignSchoolRole($recipientMembership, 'principal');

        $thread = app(CommunicationThreadService::class)->createThread($school, $creator, 'direct', null, [$recipient->id]);

        $this->activate($creator, $school);
        $this->actingAs($creator)->post("/app/communications/{$thread->id}/archive")->assertRedirect('/app/communications');

        $context = app(TenantContext::class);
        $creatorParticipant = $context->withSchool($school, fn () => $thread->participants()->where('user_id', $creator->id)->first());
        $recipientParticipant = $context->withSchool($school, fn () => $thread->participants()->where('user_id', $recipient->id)->first());

        $this->assertTrue($creatorParticipant->archived);
        // Archiving is participant-specific -- never a thread-global flag.
        $this->assertFalse($recipientParticipant->archived);

        $this->actingAs($creator)->post("/app/communications/{$thread->id}/unarchive")->assertRedirect('/app/communications');
        $creatorParticipant = $context->withSchool($school, fn () => $thread->participants()->where('user_id', $creator->id)->first());
        $this->assertFalse($creatorParticipant->archived);
    }

    #[Test]
    public function a_non_participant_cannot_archive_a_thread(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $thread = app(CommunicationThreadService::class)->createThread($school, $creator, 'direct', null, []);

        $stranger = $this->createUser();
        $this->createMembership($stranger, $school);
        $this->activate($stranger, $school);

        $this->actingAs($stranger)->post("/app/communications/{$thread->id}/archive")->assertForbidden();
    }

    #[Test]
    public function archived_threads_are_excluded_from_the_default_index_and_included_under_the_archived_filter(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $thread = app(CommunicationThreadService::class)->createThread($school, $creator, 'direct', null, []);

        $this->activate($creator, $school);
        $this->actingAs($creator)->post("/app/communications/{$thread->id}/archive");

        $this->actingAs($creator)->get('/app/communications')->assertInertia(
            fn ($page) => $page->where('threads.data', []),
        );

        $this->actingAs($creator)->get('/app/communications?archived=1')->assertInertia(
            fn ($page) => $page->has('threads.data', 1),
        );
    }

    #[Test]
    public function a_participant_can_upload_and_a_non_participant_is_forbidden_via_http(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $thread = app(CommunicationThreadService::class)->createThread($school, $creator, 'direct', null, []);
        $this->activate($creator, $school);

        $this->actingAs($creator)
            ->post("/app/communications/{$thread->id}/attachments", [
                'file' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'),
            ])
            ->assertRedirect("/app/communications/{$thread->id}");

        $stranger = $this->createUser();
        $this->createMembership($stranger, $school);
        $this->activate($stranger, $school);

        $this->actingAs($stranger)
            ->post("/app/communications/{$thread->id}/attachments", [
                'file' => UploadedFile::fake()->create('b.pdf', 10, 'application/pdf'),
            ])
            ->assertForbidden();
    }

    #[Test]
    public function a_reply_with_an_attachment_id_shows_the_attachment_in_the_thread_view(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $thread = app(CommunicationThreadService::class)->createThread($school, $creator, 'direct', null, []);
        $this->activate($creator, $school);

        $attachment = app(CommunicationAttachmentService::class)->uploadForThread(
            $thread,
            $creator,
            UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'),
        );

        $this->actingAs($creator)->post("/app/communications/{$thread->id}/messages", [
            'body' => 'See attached',
            'attachment_ids' => [$attachment->id],
        ])->assertRedirect();

        $this->actingAs($creator)->get("/app/communications/{$thread->id}")->assertInertia(
            fn ($page) => $page->where('messages.0.attachments.0.displayName', $attachment->safe_display_name),
        );
    }

    #[Test]
    public function message_pagination_loads_newest_first_and_reports_older_pages(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $recipient = $this->createUser();
        $this->createMembership($recipient, $school);
        $thread = app(CommunicationThreadService::class)->createThread($school, $creator, 'direct', null, [$recipient->id]);

        $messages = app(CommunicationMessageService::class);
        for ($i = 1; $i <= 32; $i++) {
            $messages->send($thread, $creator, "Message {$i}");
        }

        $this->activate($creator, $school);
        $this->actingAs($creator)->get("/app/communications/{$thread->id}")->assertInertia(
            fn ($page) => $page
                ->has('messages', 30)
                ->where('messagesMeta.hasOlder', true)
                ->where('messages.29.body', 'Message 32'),
        );
    }
}

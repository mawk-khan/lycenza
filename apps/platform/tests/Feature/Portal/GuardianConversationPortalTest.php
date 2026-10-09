<?php

namespace Tests\Feature\Portal;

use App\Domain\Communications\Application\CommunicationAttachmentService;
use App\Domain\Communications\Application\CommunicationMessageService;
use App\Domain\Communications\Application\CommunicationThreadService;
use App\Domain\Communications\Application\ConversationReadModel;
use App\Domain\Communications\Application\Portal\GuardianConversationService;
use App\Domain\Communications\Infrastructure\CommunicationThread;
use App\Domain\Communications\Infrastructure\CommunicationThreadParticipant;
use App\Domain\Identity\Application\AccountLinkService;
use App\Domain\Identity\Application\Portal\ActingGuardian;
use App\Domain\Identity\Application\Portal\ActingGuardianResolver;
use App\Domain\Identity\Application\Portal\GuardianOffboardingService;
use App\Domain\Identity\Application\Portal\GuardianPortalAccessDeniedException;
use App\Http\Middleware\EnsurePortalDevelopmentOnly;
use App\Models\Role;
use App\Models\School;
use App\Models\User;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Portal\PortalUnavailableException;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCommunicationFixtures;
use Tests\Concerns\CreatesGuardianPortalFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Feature\Auth\Mfa\Concerns\CreatesMfaFixtures;
use Tests\TestCase;

/**
 * POR.4 (ADR 0070 §27): a Guardian's existing School conversations and text
 * replies -- PortalAvailability + `portal.communications.view` (+ `.reply`)
 * + current MFA + this User's live ActingGuardian + participation AS this
 * Guardian persona; one message per server-issued key; in-app only; the same
 * 404 for anything else.
 */
class GuardianConversationPortalTest extends TestCase
{
    use CreatesCommunicationFixtures, CreatesGuardianPortalFixtures, CreatesMfaFixtures, CreatesTenancyFixtures;

    /** @return array{school: School, g: array<string, mixed>, staff: User, thread: CommunicationThread} */
    private function world(?School $school = null): array
    {
        $school ??= $this->createSchool();
        $g = $this->portalGuardian($school);
        $staff = $this->staff($school);
        $thread = $this->guardianThread($school, $staff, [$g['guardian']->id], 'Homework help');
        app(CommunicationMessageService::class)->send($thread, $staff, 'Please read the new plan.');
        $this->enrollActiveMfaFactor($g['user']);

        return ['school' => $school, 'g' => $g, 'staff' => $staff, 'thread' => $thread];
    }

    private function staff(School $school): User
    {
        $staff = $this->createUser(['name' => 'Ms Teacher '.Str::random(4)]);
        $this->assignSchoolRole($this->createMembership($staff, $school), 'school_admin');

        return $staff;
    }

    /** @param list<string> $guardianIds */
    private function guardianThread(School $school, User $staff, array $guardianIds, string $subject, array $userIds = []): CommunicationThread
    {
        return app(CommunicationThreadService::class)->createThread($school, $staff, 'group', $subject, $userIds, guardianIds: $guardianIds);
    }

    private function asGuardian(User $user, School $school): static
    {
        $this->signInTo($user, $school);
        session(['mfa_verified_at' => now()->toIso8601String()]);

        return $this;
    }

    private function reply(CommunicationThread $thread, string $body, ?string $key = null)
    {
        return $this->post("/app/portal/conversations/{$thread->id}/replies", ['body' => $body, 'idempotency_key' => $key ?? (string) Str::uuid()]);
    }

    private function messages(School $school, CommunicationThread $thread): Collection
    {
        return app(TenantContext::class)->withSchool($school, fn () => DB::table('communication_messages')->where('thread_id', $thread->id)->orderBy('created_at')->get());
    }

    private function audits(School $school, string $type): Collection
    {
        return app(TenantContext::class)->withSchool($school, fn () => DB::table('school_audit_events')->where('event_type', $type)->get());
    }

    private function service(): GuardianConversationService
    {
        return app(GuardianConversationService::class);
    }

    private function acting(array $g, School $school): ActingGuardian
    {
        return app(ActingGuardianResolver::class)->require($g['user'], $school);
    }

    private function dropRoleCapability(string $capability): void
    {
        DB::table('role_capabilities')->where('role_id', Role::query()->where('key', Role::GUARDIAN)->value('id'))->where('capability_key', $capability)->delete();
    }

    #[Test]
    public function a_guardian_reads_their_conversation_and_replies_once_through_the_communications_writer(): void
    {
        $w = $this->world();
        $user = $w['g']['user'];

        $index = $this->asGuardian($user, $w['school'])->get('/app/portal/conversations')->assertOk();
        $index->assertInertia(fn ($page) => $page->component('App/Portal/Conversations/Index')
            ->has('conversations', 1)
            ->where('conversations.0.id', $w['thread']->id)
            ->where('conversations.0.subject', 'Homework help')
            ->where('conversations.0.participants', [$w['staff']->name])
            ->where('conversations.0.unread', true)
            ->where('conversations.0.preview', 'Please read the new plan.'));

        $show = $this->get("/app/portal/conversations/{$w['thread']->id}")->assertOk();
        $show->assertInertia(fn ($page) => $page->component('App/Portal/Conversations/Show')
            ->where('conversation.canReply', true)
            ->has('conversation.messages', 1)
            ->where('conversation.messages.0.sender', $w['staff']->name)
            ->where('conversation.messages.0.mine', false)
            ->where('replyKey', fn ($key) => Str::isUuid($key)));
        // Names only: no participant's User id, membership, link or Guardian id
        // (the signed-in User's own id is only in the shared `auth` prop).
        $conversationJson = json_encode($show->viewData('page')['props']['conversation']);
        foreach ([$w['staff']->id, $user->id, $w['g']['membership']->id, $w['g']['link']->id, $w['g']['guardian']->id] as $id) {
            $this->assertStringNotContainsString($id, $conversationJson);
            $this->assertStringNotContainsString($id === $user->id ? 'never' : $id, (string) $show->getContent());
        }
        // Opening it moved only this participant's own read cursor.
        $own = app(TenantContext::class)->withSchool($w['school'], fn () => CommunicationThreadParticipant::query()->where('thread_id', $w['thread']->id)->where('user_id', $user->id)->sole());
        $this->assertNotNull($own->last_read_at);
        $staffRow = app(TenantContext::class)->withSchool($w['school'], fn () => CommunicationThreadParticipant::query()->where('thread_id', $w['thread']->id)->where('user_id', $w['staff']->id)->sole());
        $this->assertNull($staffRow->last_read_at);

        $key = (string) Str::uuid();
        $this->reply($w['thread'], "  Thank you, we will.\nSee you Monday.  ", $key)->assertRedirect("/app/portal/conversations/{$w['thread']->id}");

        $messages = $this->messages($w['school'], $w['thread']);
        $this->assertCount(2, $messages);
        $reply = $messages->last();
        $this->assertSame([$user->id, "Thank you, we will.\nSee you Monday.", 'normal', 'sent', $key], [$reply->sender_user_id, $reply->body, $reply->priority, $reply->status, $reply->idempotency_key]);

        // Through the authoritative writer: a recipient + IN-APP delivery for the
        // staff participant only, nothing for the sender, no email anywhere.
        $deliveries = app(TenantContext::class)->withSchool($w['school'], fn () => DB::table('communication_recipients as r')
            ->join('communication_deliveries as d', 'd.recipient_id', '=', 'r.id')
            ->where('r.message_id', $reply->id)->get(['r.recipient_user_id', 'd.channel']));
        $this->assertSame([[$w['staff']->id, 'in_app']], $deliveries->map(fn ($d) => [$d->recipient_user_id, $d->channel])->all());
        $this->assertSame(0, DB::table('email_messages')->count());

        $this->assertSame(1, $this->audits($w['school'], 'communication.message.created')->where('actor_user_id', $user->id)->count());
        $audit = $this->audits($w['school'], 'communication.guardian.replied')->sole();
        $metadata = json_decode($audit->metadata, true);
        $this->assertSame(['accountLinkId', 'guardianId', 'messageId', 'surface', 'threadId'], collect($metadata)->keys()->sort()->values()->all());
        $this->assertSame([$w['g']['guardian']->id, $w['g']['link']->id, $reply->id, 'guardian_portal'], [$metadata['guardianId'], $metadata['accountLinkId'], $metadata['messageId'], $metadata['surface']]);
        $this->assertStringNotContainsString('Thank you', $audit->metadata);

        // The staff participant now has it unread; the Guardian does not.
        $this->get('/app/portal/conversations')->assertInertia(fn ($page) => $page
            ->where('conversations.0.unread', false)->where('conversations.0.latestFromMe', true));
        $this->assertTrue(app(ConversationReadModel::class)
            ->summarize($w['school'], [$w['thread']->id], $w['staff']->id)->get($w['thread']->id)->unread);
    }

    #[Test]
    public function a_retried_reply_is_one_message_and_a_reused_key_never_writes_again(): void
    {
        $w = $this->world();
        $second = $this->guardianThread($w['school'], $w['staff'], [$w['g']['guardian']->id], 'Second');
        $this->asGuardian($w['g']['user'], $w['school']);
        $key = (string) Str::uuid();

        $this->reply($w['thread'], 'Once only', $key)->assertRedirect();
        // The browser lost the response and resubmits the same form.
        $this->reply($w['thread'], 'Once only', $key)->assertRedirect("/app/portal/conversations/{$w['thread']->id}")->assertSessionHasNoErrors();
        // The service answers the original message, flagged as a replay.
        $replay = $this->service()->reply($w['school'], $this->acting($w['g'], $w['school']), $w['g']['user'], $w['thread']->id, 'Once only', $key);
        $this->assertTrue($replay->replayed);

        // Changed text, or another conversation, with the same key: refused.
        $this->reply($w['thread'], 'Different text', $key)->assertSessionHasErrors(['idempotency_key' => 'This reply form has expired. Reload the conversation and try again.']);
        $this->reply($second, 'Once only', $key)->assertSessionHasErrors('idempotency_key');

        $this->assertSame(['Please read the new plan.', 'Once only'], $this->messages($w['school'], $w['thread'])->pluck('body')->all());
        $this->assertSame($replay->messageId, $this->messages($w['school'], $w['thread'])->last()->id);
        $this->assertCount(0, $this->messages($w['school'], $second));
        $this->assertCount(1, $this->audits($w['school'], 'communication.guardian.replied'));
        $this->assertSame(1, app(TenantContext::class)->withSchool($w['school'], fn () => DB::table('communication_recipients')->where('message_id', $replay->messageId)->count()));
    }

    #[Test]
    public function the_key_is_scoped_to_its_sender_and_school_and_the_database_is_the_claim(): void
    {
        $w = $this->world();
        $key = (string) Str::uuid();

        // Another Guardian of the same School reusing the literal key: their own message.
        $other = $this->portalGuardian($w['school']);
        $this->enrollActiveMfaFactor($other['user']);
        $otherThread = $this->guardianThread($w['school'], $w['staff'], [$other['guardian']->id], 'Other family');

        // The same User as a Guardian in another School, same key: that School's own message.
        $schoolB = $this->createSchool();
        $gB = $this->portalGuardian($schoolB, user: $w['g']['user']);
        $threadB = $this->guardianThread($schoolB, $this->staff($schoolB), [$gB['guardian']->id], 'School B');

        $this->asGuardian($w['g']['user'], $w['school'])->reply($w['thread'], 'From A', $key)->assertRedirect()->assertSessionHasNoErrors();
        $this->asGuardian($other['user'], $w['school'])->reply($otherThread, 'From the other family', $key)->assertRedirect()->assertSessionHasNoErrors();
        $this->asGuardian($w['g']['user'], $schoolB)->reply($threadB, 'From B', $key)->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('From A', $this->messages($w['school'], $w['thread'])->last()->body);
        $this->assertSame('From the other family', $this->messages($w['school'], $otherThread)->sole()->body);
        $this->assertSame('From B', $this->messages($schoolB, $threadB)->sole()->body);

        // The unique index, not a pre-check, refuses a second (School, sender, key) row,
        // and a key never sits on an announcement message.
        $insert = fn (array $over) => app(TenantContext::class)->withSchool($w['school'], fn () => DB::transaction(fn () => DB::table('communication_messages')->insert(array_merge([
            'id' => (string) Str::uuid7(), 'school_id' => $w['school']->id, 'thread_id' => $w['thread']->id, 'sender_user_id' => $w['g']['user']->id,
            'message_type' => 'text', 'body' => 'raw', 'priority' => 'normal', 'status' => 'sent', 'idempotency_key' => $key,
            'created_at' => now(), 'updated_at' => now(),
        ], $over))));
        foreach ([[[], 'communication_messages_sender_idempotency_unique'], [['thread_id' => null, 'announcement_id' => $this->createAnnouncement($w['school'], $w['staff'])->id, 'idempotency_key' => (string) Str::uuid()], 'communication_messages_idempotency_thread_check']] as [$over, $constraint]) {
            try {
                $insert($over);
                $this->fail("{$constraint} should refuse the row.");
            } catch (QueryException $e) {
                $this->assertStringContainsString($constraint, $e->getMessage());
            }
        }
    }

    #[Test]
    public function only_this_guardians_own_guardian_participation_is_visible_and_everything_else_is_the_same_404(): void
    {
        $w = $this->world();
        $user = $w['g']['user'];
        $staff = $w['staff'];

        // Staff-only thread.
        $staffOnly = app(CommunicationThreadService::class)->createThread($w['school'], $staff, 'direct', 'Staff only', [$this->staff($w['school'])->id]);
        // The same User added in a plain member (staff) capacity -- not as the Guardian persona.
        $asMember = app(CommunicationThreadService::class)->createThread($w['school'], $staff, 'direct', 'As member', [$user->id]);
        // Another Guardian's own conversation.
        $other = $this->portalGuardian($w['school']);
        $othersThread = $this->guardianThread($w['school'], $staff, [$other['guardian']->id], 'Other family');
        // Shared with another Guardian persona: withheld pending POR-L1 Q11-Q12.
        $shared = $this->guardianThread($w['school'], $staff, [$w['g']['guardian']->id, $other['guardian']->id], 'Both families');
        // A Student participant outside this Guardian's scope.
        $withStudent = $this->guardianThread($w['school'], $staff, [$w['g']['guardian']->id], 'About another child');
        $this->createParticipant($withStudent, $this->createUser(), ['participant_kind' => 'student', 'student_id' => $this->createStudent($w['school'])->id]);
        // Another School.
        $foreign = $this->world();

        $this->asGuardian($user, $w['school'])->get('/app/portal/conversations')
            ->assertInertia(fn ($page) => $page->has('conversations', 1)->where('conversations.0.id', $w['thread']->id));

        $unknown = (string) Str::uuid();
        $bodies = [];
        foreach ([$staffOnly->id, $asMember->id, $othersThread->id, $shared->id, $withStudent->id, $foreign['thread']->id, $unknown] as $id) {
            $bodies[] = $this->get("/app/portal/conversations/{$id}")->assertNotFound()->getContent();
            $this->post("/app/portal/conversations/{$id}/replies", ['body' => 'probe', 'idempotency_key' => (string) Str::uuid()])->assertNotFound();
            $this->get("/app/portal/conversations/{$id}/attachments/{$unknown}/download")->assertNotFound();
        }
        $this->assertCount(1, array_unique($bodies), 'One indistinguishable 404 for every inaccessible conversation.');
        $this->assertSame(0, app(TenantContext::class)->withSchool($w['school'], fn () => DB::table('communication_messages')->where('body', 'probe')->count()));
        $this->assertCount(0, $this->audits($w['school'], 'communication.guardian.replied'));
        $this->get('/app/portal/conversations/not-a-uuid')->assertNotFound();
    }

    #[Test]
    public function a_student_participant_must_stay_in_the_guardians_live_scope(): void
    {
        $w = $this->world();
        $student = $w['g']['student'];
        // A second eligible child keeps the Guardian a Guardian after the first is withdrawn.
        $sibling = $this->createStudent($w['school']);
        $this->createStudentGuardianRelationship($sibling, $w['g']['guardian'], ['is_legal_guardian' => true]);
        $thread = $this->guardianThread($w['school'], $w['staff'], [$w['g']['guardian']->id], 'About my child');
        $this->createParticipant($thread, $this->createUser(), ['participant_kind' => 'student', 'student_id' => $student->id]);

        $this->asGuardian($w['g']['user'], $w['school'])->get("/app/portal/conversations/{$thread->id}")->assertOk();
        $this->reply($thread, 'In scope')->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame([$student->id], json_decode($this->audits($w['school'], 'communication.guardian.replied')->sole()->metadata, true)['studentIds']);

        app(TenantContext::class)->withSchool($w['school'], fn () => DB::table('students')->where('id', $student->id)->update(['status' => 'withdrawn']));

        $this->get("/app/portal/conversations/{$thread->id}")->assertNotFound();
        $this->reply($thread, 'Out of scope')->assertNotFound();
        $this->get("/app/portal/conversations/{$w['thread']->id}")->assertOk();
    }

    #[Test]
    public function a_reply_needs_both_portal_capabilities_and_staff_capabilities_never_substitute(): void
    {
        $w = $this->world();
        $this->asGuardian($w['g']['user'], $w['school']);

        $this->dropRoleCapability('portal.communications.reply');
        app(CapabilityResolver::class)->forgetCache($w['g']['user'], $w['school']);
        $this->get("/app/portal/conversations/{$w['thread']->id}")->assertOk()->assertInertia(fn ($page) => $page->where('conversation.canReply', false));
        $this->reply($w['thread'], 'No reply capability')->assertForbidden();

        // Reply alone, without view, opens nothing either.
        DB::table('role_capabilities')->insert(['role_id' => Role::query()->where('key', Role::GUARDIAN)->value('id'), 'capability_key' => 'portal.communications.reply']);
        $this->dropRoleCapability('portal.communications.view');
        app(CapabilityResolver::class)->forgetCache($w['g']['user'], $w['school']);
        $this->get("/app/portal/conversations/{$w['thread']->id}")->assertForbidden();
        $this->reply($w['thread'], 'No view capability')->assertForbidden();

        // Staff Communications authority on a non-Guardian User: never the portal.
        $staffUser = $w['staff'];
        $this->enrollActiveMfaFactor($staffUser);
        $this->asGuardian($staffUser, $w['school'])->get('/app/portal/conversations')->assertForbidden();
        $this->reply($w['thread'], 'Staff via portal')->assertForbidden();

        $this->assertCount(1, $this->messages($w['school'], $w['thread']));
    }

    #[Test]
    public function the_portal_capabilities_never_open_the_staff_hub_and_a_dual_persona_is_judged_as_the_guardian(): void
    {
        $w = $this->world();
        $this->asGuardian($w['g']['user'], $w['school']);
        $this->get('/app/communications/conversations')->assertForbidden();
        $this->get("/app/communications/{$w['thread']->id}")->assertForbidden();
        $this->post("/app/communications/{$w['thread']->id}/messages", ['body' => 'Via the staff Hub'])->assertForbidden();

        // A principal who is also a Guardian: staff threads stay in the staff Hub;
        // the portal shows only the thread they joined as the Guardian.
        $dual = $this->createUser();
        $membership = $this->createMembership($dual, $w['school']);
        $this->assignSchoolRole($membership, 'principal');
        $p = $this->portalGuardian($w['school'], user: $dual, membership: $membership);
        $this->enrollActiveMfaFactor($dual);
        $staffThread = app(CommunicationThreadService::class)->createThread($w['school'], $w['staff'], 'direct', 'Staff matter', [$dual->id]);
        $guardianThread = $this->guardianThread($w['school'], $w['staff'], [$p['guardian']->id], 'Your child');

        $this->asGuardian($dual, $w['school'])->get('/app/portal/conversations')
            ->assertInertia(fn ($page) => $page->has('conversations', 1)->where('conversations.0.id', $guardianThread->id));
        $this->reply($staffThread, 'Staff thread via the portal')->assertNotFound();
        $this->reply($guardianThread, 'As the parent')->assertRedirect()->assertSessionHasNoErrors();
        $this->assertCount(0, $this->messages($w['school'], $staffThread));
    }

    #[Test]
    public function lifecycle_changes_deny_the_next_reply(): void
    {
        $w = $this->world();
        $this->asGuardian($w['g']['user'], $w['school'])->reply($w['thread'], 'Before')->assertRedirect()->assertSessionHasNoErrors();

        // Thread no longer open: refused, and the page says so.
        app(TenantContext::class)->withSchool($w['school'], fn () => DB::table('communication_threads')->where('id', $w['thread']->id)->update(['status' => 'closed']));
        $this->reply($w['thread'], 'After closure')->assertSessionHasErrors(['body' => 'This conversation is closed to replies.']);
        $this->get("/app/portal/conversations/{$w['thread']->id}")->assertOk()->assertInertia(fn ($page) => $page->where('conversation.canReply', false)->where('conversation.open', false));
        app(TenantContext::class)->withSchool($w['school'], fn () => DB::table('communication_threads')->where('id', $w['thread']->id)->update(['status' => 'archived']));
        $this->reply($w['thread'], 'After archive')->assertSessionHasErrors('body');
        app(TenantContext::class)->withSchool($w['school'], fn () => DB::table('communication_threads')->where('id', $w['thread']->id)->update(['status' => 'open']));

        // Participant removed: the thread is no longer theirs.
        $participant = app(TenantContext::class)->withSchool($w['school'], fn () => CommunicationThreadParticipant::query()->where('thread_id', $w['thread']->id)->where('user_id', $w['g']['user']->id)->sole());
        app(CommunicationThreadService::class)->removeParticipant($participant);
        $this->reply($w['thread'], 'After removal')->assertNotFound();
        app(TenantContext::class)->withSchool($w['school'], fn () => $participant->update(['left_at' => null]));

        // Membership suspended, link revoked, off-boarded: the portal itself is refused.
        $admin = $this->portalAdmin($w['school']);
        DB::table('school_memberships')->where('id', $w['g']['membership']->id)->update(['status' => 'suspended']);
        // Refused by School context itself (409), before the portal is reached.
        $this->reply($w['thread'], 'While suspended')->assertStatus(409);
        DB::table('school_memberships')->where('id', $w['g']['membership']->id)->update(['status' => 'active']);
        $this->asGuardian($w['g']['user'], $w['school'])->reply($w['thread'], 'Reactivated')->assertRedirect()->assertSessionHasNoErrors();

        app(AccountLinkService::class)->unlinkGuardian($w['school'], $w['g']['guardian'], $admin);
        $this->reply($w['thread'], 'After unlink')->assertForbidden();

        $second = $this->world();
        app(GuardianOffboardingService::class)->offboard($second['school'], $this->portalAdmin($second['school']), $second['g']['guardian']);
        // Off-boarding a Guardian-only membership also suspends it: School context refuses first.
        $this->asGuardian($second['g']['user'], $second['school'])->reply($second['thread'], 'After off-boarding')->assertStatus(409);

        $this->assertSame(['Please read the new plan.', 'Before', 'Reactivated'], $this->messages($w['school'], $w['thread'])->pluck('body')->all());
        $this->assertCount(1, $this->messages($second['school'], $second['thread']));
    }

    #[Test]
    public function every_conversation_route_needs_current_mfa_assurance(): void
    {
        $w = $this->world();
        $this->signInTo($w['g']['user'], $w['school']);
        session()->forget('mfa_verified_at');
        $this->get('/app/portal/conversations')->assertStatus(401);
        $this->get("/app/portal/conversations/{$w['thread']->id}")->assertStatus(401);
        $this->reply($w['thread'], 'No assurance')->assertStatus(401);

        session(['mfa_verified_at' => now()->subMinutes(61)->toIso8601String()]);
        $this->reply($w['thread'], 'Stale assurance')->assertStatus(401);

        $unenrolled = $this->portalGuardian($w['school']);
        $this->signInTo($unenrolled['user'], $w['school']);
        session(['mfa_verified_at' => now()->toIso8601String()]);
        $this->get('/app/portal/conversations')->assertForbidden();

        $this->assertCount(1, $this->messages($w['school'], $w['thread']));
        $this->asGuardian($w['g']['user'], $w['school'])->reply($w['thread'], 'Assured')->assertRedirect()->assertSessionHasNoErrors();
    }

    #[Test]
    public function production_is_refused_by_the_route_and_by_every_service_method(): void
    {
        $w = $this->world();
        $acting = $this->acting($w['g'], $w['school']);
        $this->asGuardian($w['g']['user'], $w['school']);

        $this->app['env'] = 'production';
        try {
            $this->get('/app/portal/conversations')->assertForbidden()->assertSee(PortalUnavailableException::MESSAGE);
            $this->withoutMiddleware([PreventRequestForgery::class]);
            $this->reply($w['thread'], 'In production')->assertForbidden()->assertSee(PortalUnavailableException::MESSAGE);

            $this->withoutMiddleware([PreventRequestForgery::class, EnsurePortalDevelopmentOnly::class]);
            $this->reply($w['thread'], 'Middleware bypassed')->assertForbidden()->assertSee(PortalUnavailableException::MESSAGE);

            $calls = [
                fn () => $this->service()->conversations($w['school'], $acting, $w['g']['user']),
                fn () => $this->service()->thread($w['school'], $acting, $w['g']['user'], $w['thread']->id),
                fn () => $this->service()->reply($w['school'], $acting, $w['g']['user'], $w['thread']->id, 'Direct call', (string) Str::uuid()),
                fn () => $this->service()->attachmentForDownload($w['school'], $acting, $w['g']['user'], $w['thread']->id, (string) Str::uuid()),
            ];
            foreach ($calls as $call) {
                try {
                    $call();
                    $this->fail('The service must refuse outside local/testing.');
                } catch (PortalUnavailableException) {
                }
            }
        } finally {
            $this->app['env'] = 'testing';
        }

        $this->assertCount(1, $this->messages($w['school'], $w['thread']));
        $this->assertCount(0, $this->audits($w['school'], 'communication.guardian.replied'));
    }

    #[Test]
    public function the_write_service_rechecks_authority_without_the_controller(): void
    {
        $w = $this->world();
        $acting = $this->acting($w['g'], $w['school']);
        $intruder = $this->createUser();

        // Someone else's ActingGuardian is never accepted.
        $this->expectNotFound(fn () => $this->service()->reply($w['school'], $acting, $intruder, $w['thread']->id, 'Forged', (string) Str::uuid()));
        // A forged persona for a real User: the locked live resolution disagrees.
        $forged = new ActingGuardian($acting->schoolId, $acting->userId, $acting->membershipId, $acting->accountLinkId, $this->createGuardian($w['school'])->id);
        $this->expectDenied(fn () => $this->service()->reply($w['school'], $forged, $w['g']['user'], $w['thread']->id, 'Forged persona', (string) Str::uuid()));

        // A stale ActingGuardian after the link ended: refused under the lock.
        app(AccountLinkService::class)->unlinkGuardian($w['school'], $w['g']['guardian'], $this->portalAdmin($w['school']));
        $this->expectDenied(fn () => $this->service()->reply($w['school'], $acting, $w['g']['user'], $w['thread']->id, 'Stale', (string) Str::uuid()));

        // The capability, re-checked in the service itself.
        $v = $this->world();
        $this->dropRoleCapability('portal.communications.reply');
        $this->expectDenied(fn () => $this->service()->reply($v['school'], $this->acting($v['g'], $v['school']), $v['g']['user'], $v['thread']->id, 'No capability', (string) Str::uuid()));

        $this->assertCount(1, $this->messages($w['school'], $w['thread']));
        $this->assertCount(1, $this->messages($v['school'], $v['thread']));
    }

    private function expectNotFound(callable $call): void
    {
        try {
            $call();
            $this->fail('Expected the same not-found.');
        } catch (ModelNotFoundException) {
            $this->addToAssertionCount(1);
        }
    }

    private function expectDenied(callable $call): void
    {
        try {
            $call();
            $this->fail('Expected the portal refusal.');
        } catch (GuardianPortalAccessDeniedException) {
            $this->addToAssertionCount(1);
        }
    }

    #[Test]
    public function reply_content_is_plain_text_validated_without_echo_and_extra_fields_are_ignored(): void
    {
        $w = $this->world();
        $this->asGuardian($w['g']['user'], $w['school']);

        $this->reply($w['thread'], '')->assertSessionHasErrors('body');
        $this->reply($w['thread'], "  \n\t ")->assertSessionHasErrors('body');
        $long = str_repeat('a', GuardianConversationService::MAX_BODY_LENGTH + 1);
        $this->reply($w['thread'], $long)->assertSessionHasErrors('body');
        $this->assertStringNotContainsString($long, json_encode(session('errors')->getBag('default')->toArray()));
        $this->reply($w['thread'], "nul\0byte")->assertSessionHasErrors('body');
        $this->assertCount(1, $this->messages($w['school'], $w['thread']));

        // Markup is stored as the literal text it is (rendered as text, never HTML).
        $markup = '<script>alert(1)</script><b>bold</b>';
        $this->post("/app/portal/conversations/{$w['thread']->id}/replies", [
            'body' => $markup, 'idempotency_key' => (string) Str::uuid(),
            'priority' => 'critical', 'attachment_ids' => [(string) Str::uuid()], 'sender_user_id' => $w['staff']->id,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $stored = $this->messages($w['school'], $w['thread'])->last();
        $this->assertSame([$markup, 'normal', $w['g']['user']->id], [$stored->body, $stored->priority, $stored->sender_user_id]);
        $this->get("/app/portal/conversations/{$w['thread']->id}")->assertInertia(fn ($page) => $page->where('conversation.messages.1.body', $markup));
        $this->assertFalse(str_contains((string) file_get_contents(resource_path('js/Pages/App/Portal/Conversations/Show.vue')), 'v-html'));
    }

    #[Test]
    public function replies_are_throttled_per_user_whatever_the_key_or_school(): void
    {
        $w = $this->world();
        $schoolB = $this->createSchool();
        $gB = $this->portalGuardian($schoolB, user: $w['g']['user']);
        $threadB = $this->guardianThread($schoolB, $this->staff($schoolB), [$gB['guardian']->id], 'School B');
        RateLimiter::clear('guardian-portal-reply:'.$w['g']['user']->id);

        $this->asGuardian($w['g']['user'], $w['school']);
        for ($i = 1; $i <= 10; $i++) {
            $this->reply($w['thread'], "Reply {$i}")->assertRedirect();
        }
        $this->reply($w['thread'], 'Eleventh')->assertStatus(429);

        // Switching School gives no fresh bucket.
        $this->asGuardian($w['g']['user'], $schoolB)->reply($threadB, 'From B')->assertStatus(429);

        $this->assertCount(11, $this->messages($w['school'], $w['thread']));
        $this->assertCount(0, $this->messages($schoolB, $threadB));
        RateLimiter::clear('guardian-portal-reply:'.$w['g']['user']->id);
    }

    #[Test]
    public function sent_attachments_download_audited_and_pending_or_foreign_ones_are_the_same_404(): void
    {
        Storage::fake('local');
        $w = $this->world();
        $attachments = app(CommunicationAttachmentService::class);
        $sent = $attachments->uploadForThread($w['thread'], $w['staff'], UploadedFile::fake()->create('plan.pdf', 1, 'application/pdf'));
        app(CommunicationMessageService::class)->send($w['thread'], $w['staff'], 'Attached', attachmentIds: [$sent->id]);
        $pending = $attachments->uploadForThread($w['thread'], $w['staff'], UploadedFile::fake()->create('draft.pdf', 1, 'application/pdf'));
        $other = $this->portalGuardian($w['school']);
        $otherThread = $this->guardianThread($w['school'], $w['staff'], [$other['guardian']->id], 'Other family');
        $foreign = $attachments->uploadForThread($otherThread, $w['staff'], UploadedFile::fake()->create('other.pdf', 1, 'application/pdf'));
        app(CommunicationMessageService::class)->send($otherThread, $w['staff'], 'Theirs', attachmentIds: [$foreign->id]);

        $this->asGuardian($w['g']['user'], $w['school']);
        $this->get("/app/portal/conversations/{$w['thread']->id}")->assertInertia(fn ($page) => $page
            ->where('conversation.messages.1.attachments.0.name', 'plan.pdf'));
        $this->get("/app/portal/conversations/{$w['thread']->id}/attachments/{$sent->id}/download")->assertOk();
        $metadata = json_decode($this->audits($w['school'], 'communication_attachment.downloaded')->sole()->metadata, true);
        $this->assertSame([$w['thread']->id, 'guardian_portal', $w['g']['guardian']->id], [$metadata['threadId'], $metadata['surface'], $metadata['guardianId']]);

        foreach ([[$w['thread'], $pending->id], [$w['thread'], $foreign->id], [$otherThread, $foreign->id]] as [$thread, $attachmentId]) {
            $this->get("/app/portal/conversations/{$thread->id}/attachments/{$attachmentId}/download")->assertNotFound();
        }
        $this->assertCount(1, $this->audits($w['school'], 'communication_attachment.downloaded'));
        $this->assertStringNotContainsString('draft.pdf', (string) $this->get("/app/portal/conversations/{$w['thread']->id}")->getContent());
    }
}

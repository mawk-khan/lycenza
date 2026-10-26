<?php

namespace Tests\Feature\Communications;

use App\Domain\Communications\Application\AnnouncementService;
use App\Domain\Communications\Application\Approval\CommunicationApprovalService;
use App\Domain\Communications\Application\CommunicationAttachmentService;
use App\Domain\Communications\Domain\CommunicationAudienceType;
use App\Domain\Communications\Domain\CommunicationPriority;
use App\Domain\Communications\Infrastructure\CommunicationAnnouncement;
use App\Domain\Communications\Infrastructure\CommunicationAttachment;
use App\Models\Role;
use App\Models\School;
use App\Models\SchoolMembership;
use App\Models\User;
use App\Support\Authorization\CapabilityClasses;
use App\Support\Tenancy\TenantContext;
use App\Support\Testing\LocalCatalogueFixtures;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCommunicationFixtures;
use Tests\Concerns\CreatesGuardianPortalFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * ORG.9D-1R1 (ADR 0076 §77, OD-9.6): reading what one actually received is
 * CONSUMPTION on its own capability, `communications.inbox.view`, carried by
 * every staff persona role. It opens the Hub inbox, unread and search, a
 * received announcement (and its attachments) and one's own conversations --
 * each item still needs the actual published recipient record or thread
 * participation. It never opens Communications administration: the School
 * announcement list, Sent, drafts, scheduled items, counts, delivery
 * analytics, failures, approvals, templates, settings or the audit trail.
 * The immutable recipient snapshot is the historical fact: a recipient whose
 * employment later ends keeps the message while they keep School access.
 */
class CommunicationInboxConsumerAccessTest extends TestCase
{
    use CreatesCommunicationFixtures, CreatesGuardianPortalFixtures, CreatesTenancyFixtures;

    /** Every School system role that consumes its own Communications (all but the hr_sensitive_records add-on). */
    private const CONSUMER_ROLES = [
        'accountant', 'admissions_officer', 'canteen_operator', 'cashier', 'communications_coordinator',
        'front_office', 'hostel_warden', 'hr_officer', 'librarian', 'payroll_officer', 'principal',
        'school_admin', 'staff_self_service', 'stores_officer', 'teacher', 'transport_coordinator',
    ];

    private School $school;

    private User $creator;

    private User $teacher;

    private User $colleague;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->travelTo(CarbonImmutable::parse('2026-10-01T06:00:00Z'));
        $this->school = $this->createSchool(['timezone' => 'Asia/Kolkata']);
        $this->creator = $this->withRole('communications_coordinator');
        $this->teacher = $this->withRole('teacher');
        $this->colleague = $this->withRole('teacher');
    }

    /** A member of `$school` holding the real system role `$roleKey`, optionally employed (current or ended). */
    private function withRole(string $roleKey, ?string $employment = 'current', ?School $school = null): User
    {
        $school ??= $this->school;
        $user = $this->createUser();
        LocalCatalogueFixtures::grantRole($this->createMembership($user, $school), Role::query()->where('key', $roleKey)->firstOrFail());
        if ($employment !== null) {
            $record = $this->createEmployee($school, ['user_id' => $user->id, 'record_status' => 'active']);
            $this->createEmploymentRecord($record, $employment === 'current'
                ? ['status' => 'active', 'starts_on' => '2025-01-01']
                : ['status' => 'separated', 'starts_on' => '2025-01-01', 'ends_on' => '2026-09-30']);
        }

        return $user;
    }

    /** @param  list<string>  $individuals */
    private function draft(string $title, CommunicationAudienceType $type = CommunicationAudienceType::Staff, array $individuals = [], ?School $school = null, ?User $creator = null): CommunicationAnnouncement
    {
        return app(AnnouncementService::class)->createDraft($school ?? $this->school, $creator ?? $this->creator, $title, "Body of {$title}.", CommunicationPriority::Normal, $type, $individuals);
    }

    private function publish(CommunicationAnnouncement $announcement, ?User $creator = null): CommunicationAnnouncement
    {
        return app(AnnouncementService::class)->publish($announcement, $creator ?? $this->creator);
    }

    private function attach(CommunicationAnnouncement $announcement, ?User $creator = null): CommunicationAttachment
    {
        return app(CommunicationAttachmentService::class)->upload($announcement, $creator ?? $this->creator, UploadedFile::fake()->create('notice.pdf', 10, 'application/pdf'));
    }

    /** @return list<string> */
    private function titles(string $url): array
    {
        return collect($this->get($url)->assertOk()->viewData('page')['props']['items'])->pluck('title')->sort()->values()->all();
    }

    private function inSchool(callable $fn, ?School $school = null): mixed
    {
        return app(TenantContext::class)->withSchool($school ?? $this->school, $fn);
    }

    #[Test]
    public function a_teacher_reads_what_they_received_and_reaches_no_communications_administration(): void
    {
        $staff = $this->publish($this->draft('Staff meeting'));
        $wide = $this->publish($this->draft('Open day', CommunicationAudienceType::SchoolWide));
        $this->publish($this->draft('For a colleague only', CommunicationAudienceType::Individual, [(string) $this->colleague->id]));
        $draft = $this->draft('Draft notice');
        $template = $this->createTemplate($this->school, $this->creator, ['name' => 'Staff meeting template']);

        $this->signInTo($this->teacher, $this->school);
        $inbox = $this->get('/app/communications')->assertOk()->viewData('page')['props'];
        $this->assertSame(['Open day', 'Staff meeting'], collect($inbox['items'])->pluck('title')->sort()->values()->all(), 'Only what the teacher received: never another person\'s or a draft.');
        $this->assertFalse($inbox['canViewHub'], 'The School announcement list and Sent are not offered.');
        $this->assertSame(2, $inbox['totalUnreadCount'], 'The unread count is the teacher\'s own.');
        $this->assertContains('communications', self::navigationKeys($inbox['navigation']), 'The Hub entry is offered.');
        $this->assertSame(['Open day', 'Staff meeting'], $this->titles('/app/communications/unread'));

        $page = $this->get("/app/communications/announcements/{$staff->id}")->assertOk()->viewData('page')['props'];
        $this->assertSame('Body of Staff meeting.', $page['announcement']['body']);
        $this->assertSame('staff', $page['announcement']['audienceType'], 'The safe audience label stays.');
        $this->assertNull($page['announcement']['recipientCount'], 'Never how many it reached.');
        $this->assertNull($page['announcement']['sourceTemplateId']);
        $this->assertNull($page['channelDeliverySummary'], 'Never the School\'s delivery figures.');
        $this->assertNull($page['approvalRequirement']);
        $this->assertNull($page['latestApprovalRequest']);
        $this->assertNull($page['preview']);
        $this->assertFalse($page['canViewHub']);
        foreach (['canEdit', 'canSchedule', 'canCancel', 'canManageAttachments', 'canViewAudit', 'canViewAnalytics', 'canSubmitForApproval', 'canWithdrawApproval', 'canApprove', 'canAnnounce'] as $flag) {
            $this->assertFalse($page[$flag], $flag);
        }
        $this->assertSame(['Open day'], $this->titles('/app/communications/unread'), 'Opening it marks only the teacher\'s own copy read.');
        $this->assertSame(['Open day', 'Staff meeting'], $this->titles('/app/communications'));

        // Administration, management and other people's work all stay closed.
        foreach (['/app/communications/announcements', '/app/communications/announcements?status=draft', '/app/communications/announcements?status=scheduled',
            '/app/communications/announcements/create', '/app/communications/sent', '/app/communications/failed', '/app/communications/analytics',
            "/app/communications/announcements/{$staff->id}/analytics", "/app/communications/announcements/{$staff->id}/audit",
            '/app/communications/approvals', '/app/communications/templates', "/app/communications/templates/{$template->id}/edit",
            '/app/communications/settings/channels', '/app/communications/events'] as $url) {
            $this->get($url)->assertForbidden();
        }
        $this->post('/app/communications/announcements', ['title' => 'Mine', 'body' => 'x', 'audience_type' => 'school_wide'])->assertForbidden();
        $this->put("/app/communications/announcements/{$draft->id}", ['title' => 'Changed'])->assertForbidden();
        $this->post("/app/communications/announcements/{$draft->id}/submit-approval")->assertForbidden();
        $this->post("/app/communications/announcements/{$draft->id}/publish")->assertForbidden();
        $this->post("/app/communications/announcements/{$draft->id}/cancel")->assertForbidden();
        $this->post('/app/communications/conversations', ['participant_user_ids' => [(string) $this->colleague->id], 'body' => 'hi'])->assertForbidden();
        $this->assertSame('draft', $this->inSchool(fn () => $draft->fresh()->status), 'Nothing the teacher tried changed an announcement.');
        $this->assertSame('published', $this->inSchool(fn () => $wide->fresh()->status));
    }

    #[Test]
    public function every_item_needs_the_actual_published_recipient_record(): void
    {
        $other = $this->publish($this->draft('For a colleague only', CommunicationAudienceType::Individual, [(string) $this->colleague->id]));
        $draft = $this->draft('Draft notice');
        $scheduled = app(AnnouncementService::class)->schedule($this->draft('Scheduled notice'), $this->creator, Carbon::parse('2026-10-05T06:00:00Z'));
        $this->createApprovalPolicy($this->school, ['require_school_wide_approval' => true]);
        $pendingWide = $this->draft('Pending wide', CommunicationAudienceType::SchoolWide);
        app(CommunicationApprovalService::class)->submit($pendingWide, $this->creator);
        $cancelled = app(AnnouncementService::class)->cancel($this->draft('Cancelled notice'), $this->creator);

        $this->signInTo($this->teacher, $this->school);
        foreach ([$other, $draft, $scheduled, $this->inSchool(fn () => $pendingWide->fresh()), $cancelled] as $announcement) {
            $this->get("/app/communications/announcements/{$announcement->id}")->assertForbidden();
        }
        $this->get('/app/communications/announcements/'.Str::uuid())->assertNotFound();
        $this->get('/app/communications/announcements/not-a-uuid')->assertNotFound();
        $this->get('/app/communications/not-a-uuid')->assertNotFound();
        $this->get('/app/communications/attachments/not-a-uuid/download')->assertNotFound();
        $this->assertSame([], $this->titles('/app/communications'), 'Nothing the teacher did not receive is listed.');

        // The colleague, who received it, opens it on the same capability.
        $this->signInTo($this->colleague, $this->school)->get("/app/communications/announcements/{$other->id}")->assertOk();

        // The Hub's administrative paths are unchanged: creator and manager still open drafts.
        $this->signInTo($this->creator, $this->school)->get("/app/communications/announcements/{$draft->id}")->assertOk()->assertInertia(fn ($page) => $page->where('canViewHub', true)->where('canEdit', true));
        $this->get('/app/communications/announcements')->assertOk();
        $manager = $this->withRole('school_admin');
        $managerPage = $this->signInTo($manager, $this->school)->get("/app/communications/announcements/{$other->id}")->assertOk()->viewData('page')['props'];
        $this->assertNotNull($managerPage['announcement']['recipientCount'], 'A manager keeps the administrative view.');
        $this->assertNotNull($managerPage['channelDeliverySummary']);
    }

    #[Test]
    public function the_capability_alone_never_makes_anyone_a_recipient(): void
    {
        $published = $this->publish($this->draft('Staff meeting'));
        $roleOnly = $this->withRole('teacher', null);

        $this->signInTo($roleOnly, $this->school);
        $this->assertSame([], $this->titles('/app/communications'), 'Not staff at publish, so never snapshotted.');
        $this->get("/app/communications/announcements/{$published->id}")->assertForbidden();
        $this->assertSame([], $this->get('/app/communications/search?q=Staff')->assertOk()->viewData('page')['props']['items']);
    }

    #[Test]
    public function search_is_recipient_scoped_before_matching(): void
    {
        $this->publish($this->draft('Budget staff meeting'));
        $this->publish($this->draft('Budget for a colleague', CommunicationAudienceType::Individual, [(string) $this->colleague->id]));
        $this->draft('Budget draft');
        $this->createTemplate($this->school, $this->creator, ['name' => 'Budget template']);
        $elsewhere = $this->createSchool();
        $foreignCreator = $this->withRole('communications_coordinator', 'current', $elsewhere);
        $this->withRole('teacher', 'current', $elsewhere);
        $this->publish($this->draft('Budget elsewhere', CommunicationAudienceType::SchoolWide, [], $elsewhere, $foreignCreator), $foreignCreator);
        $thread = $this->createThread($this->school, $this->creator, ['subject' => 'Budget thread']);
        $this->createParticipant($thread, $this->creator);
        $this->createParticipant($thread, $this->teacher);
        $hidden = $this->createThread($this->school, $this->creator, ['subject' => 'Budget private thread']);
        $this->createParticipant($hidden, $this->creator);

        $this->signInTo($this->teacher, $this->school);
        $this->assertSame(['Budget staff meeting', 'Budget thread'], $this->titles('/app/communications/search?q=Budget'), 'Never another person\'s message, a draft, a template, another School\'s or a thread the teacher is not in.');

        // The communications coordinator (Hub reader) keeps its wider search.
        $this->signInTo($this->creator, $this->school);
        $this->assertContains('Budget draft', $this->titles('/app/communications/search?q=Budget'));
    }

    #[Test]
    public function attachments_follow_the_parent_announcement(): void
    {
        $received = $this->draft('Staff meeting');
        $mine = $this->attach($received);
        $this->publish($received);
        $notMine = $this->draft('For a colleague only', CommunicationAudienceType::Individual, [(string) $this->colleague->id]);
        $theirs = $this->attach($notMine);
        $this->publish($notMine);
        $draft = $this->draft('Draft notice');
        $draftFile = $this->attach($draft);
        $elsewhere = $this->createSchool();
        $foreignCreator = $this->withRole('communications_coordinator', 'current', $elsewhere);
        $this->withRole('teacher', 'current', $elsewhere);
        $foreign = $this->draft('Elsewhere', CommunicationAudienceType::SchoolWide, [], $elsewhere, $foreignCreator);
        $foreignFile = $this->attach($foreign, $foreignCreator);
        $this->publish($foreign, $foreignCreator);

        $this->signInTo($this->teacher, $this->school);
        $this->get("/app/communications/attachments/{$mine->id}/download")->assertOk();
        $this->get("/app/communications/attachments/{$theirs->id}/download")->assertForbidden();
        $this->get("/app/communications/attachments/{$draftFile->id}/download")->assertForbidden();
        $this->get("/app/communications/attachments/{$foreignFile->id}/download")->assertNotFound();
    }

    #[Test]
    public function conversations_open_only_to_their_participants(): void
    {
        $thread = $this->createThread($this->school, $this->creator, ['subject' => 'Timetable change']);
        $this->createParticipant($thread, $this->creator);
        $this->createParticipant($thread, $this->teacher);
        $this->createMessage($thread, $this->creator, ['body' => 'Period 3 moves.']);
        $other = $this->createThread($this->school, $this->creator, ['subject' => 'Private']);
        $this->createParticipant($other, $this->creator);
        $this->createParticipant($other, $this->colleague);

        $this->signInTo($this->teacher, $this->school);
        $list = $this->get('/app/communications/conversations')->assertOk()->viewData('page')['props'];
        $this->assertSame(['Timetable change'], collect($list['threads']['data'] ?? $list['threads'])->pluck('subject')->all());
        $this->assertFalse($list['canSend'], 'Reading is not starting conversations.');
        $this->get("/app/communications/{$thread->id}")->assertOk()->assertInertia(fn ($page) => $page->where('isParticipant', true)->where('canReply', false));
        $this->get("/app/communications/{$other->id}")->assertForbidden();
        $this->post("/app/communications/{$thread->id}/messages", ['body' => 'Noted'])->assertForbidden();
        $this->post("/app/communications/{$thread->id}/archive")->assertRedirect('/app/communications/conversations');
        $this->post("/app/communications/{$other->id}/archive")->assertForbidden();

        // A pending upload (not yet sent) is its uploader's own: never another participant's by id.
        $pendingUpload = app(CommunicationAttachmentService::class)->uploadForThread($thread, $this->creator, UploadedFile::fake()->create('draft.pdf', 10, 'application/pdf'));
        $this->get("/app/communications/attachments/{$pendingUpload->id}/download")->assertForbidden();
        $this->signInTo($this->creator, $this->school)->get("/app/communications/attachments/{$pendingUpload->id}/download")->assertOk();
    }

    #[Test]
    public function the_recipient_snapshot_is_the_historical_fact_while_school_access_lasts(): void
    {
        $before = $this->publish($this->draft('Before leaving'));
        $leaverRecord = $this->inSchool(fn () => DB::table('employment_records')->join('employees', 'employees.id', '=', 'employment_records.employee_id')
            ->where('employees.user_id', $this->teacher->id)->value('employment_records.id'));
        $this->inSchool(fn () => DB::table('employment_records')->where('id', $leaverRecord)->update(['status' => 'separated', 'ends_on' => '2026-09-30']));
        $after = $this->publish($this->draft('After leaving'));

        $this->signInTo($this->teacher, $this->school);
        $this->assertSame(['Before leaving'], $this->titles('/app/communications'), 'Delivered history stays; a later Staff announcement never reaches a leaver.');
        $this->get("/app/communications/announcements/{$before->id}")->assertOk();
        $this->get("/app/communications/announcements/{$after->id}")->assertForbidden();

        // Off-boarding (membership suspended) ends School access, and with it the Hub.
        $this->inSchool(fn () => DB::table('school_memberships')->where('user_id', $this->teacher->id)->where('school_id', $this->school->id)->update(['status' => 'suspended']));
        $this->assertNotSame(200, $this->get("/app/communications/announcements/{$before->id}")->getStatusCode());
        $this->assertNotSame(200, $this->get('/app/communications')->getStatusCode());
    }

    #[Test]
    public function every_consumer_role_reads_its_own_staff_announcement(): void
    {
        $users = [];
        foreach (self::CONSUMER_ROLES as $role) {
            $users[$role] = $this->withRole($role);
        }
        $published = $this->publish($this->draft('Staff meeting'));

        foreach ($users as $role => $user) {
            $this->signInTo($user, $this->school);
            $this->assertContains('Staff meeting', $this->titles('/app/communications'), $role);
            $this->get("/app/communications/announcements/{$published->id}")->assertOk();
            $hub = in_array($role, ['school_admin', 'principal', 'communications_coordinator'], true);
            $this->assertSame($hub ? 200 : 403, $this->get('/app/communications/announcements')->getStatusCode(), "{$role}: the School list stays with communications.view holders.");
        }
    }

    #[Test]
    public function the_capability_sits_on_exactly_the_staff_persona_roles_and_nowhere_else(): void
    {
        $holders = DB::table('role_capabilities as rc')->join('roles as r', 'r.id', '=', 'rc.role_id')
            ->where('rc.capability_key', 'communications.inbox.view')->orderBy('r.key')->pluck('r.key')->all();
        $this->assertSame(self::CONSUMER_ROLES, $holders, 'No Guardian, Group, platform or add-on role holds it.');
        $this->assertSame(['school'], DB::table('roles')->whereIn('key', $holders)->distinct()->pluck('scope')->all());
        $this->assertSame('school', DB::table('capabilities')->where('key', 'communications.inbox.view')->value('namespace'));
        $this->assertSame(['operational'], CapabilityClasses::CLASSES['communications.inbox.view'], 'Like the calendar: it reaches nothing beyond what the holder was sent.');

        $viewHolders = DB::table('role_capabilities as rc')->join('roles as r', 'r.id', '=', 'rc.role_id')
            ->where('rc.capability_key', 'communications.view')->orderBy('r.key')->pluck('r.key')->all();
        $this->assertSame(['communications_coordinator', 'principal', 'school_admin'], $viewHolders, 'communications.view was not widened.');
    }

    #[Test]
    public function the_events_and_inbox_capabilities_stay_separate_and_platform_or_guardian_accounts_get_neither_hub(): void
    {
        $inboxOnly = $this->createUserWithCapabilities($this->school, ['communications.inbox.view']);
        $this->signInTo($inboxOnly, $this->school)->get('/app/communications')->assertOk();
        $this->get('/app/communications/calendar')->assertForbidden();

        $eventsOnly = $this->createUserWithCapabilities($this->school, ['communications.events.view']);
        $this->signInTo($eventsOnly, $this->school)->get('/app/communications/calendar')->assertOk();
        $this->get('/app/communications')->assertForbidden();

        $guardian = $this->portalGuardian($this->school);
        $this->signInTo($guardian['user'], $this->school)->get('/app/communications')->assertForbidden();
        $this->get('/app/portal/communications')->assertOk();

        $platformOnly = $this->createUser();
        $this->assertNotSame(200, $this->actingAs($platformOnly)->get('/app/communications')->getStatusCode(), 'No School membership, no tenant inbox.');
        $root = $this->createPlatformRoot();
        $this->assertNotSame(200, $this->actingAs($root)->get('/app/communications')->getStatusCode(), 'Platform authority is never a School inbox.');
    }

    #[Test]
    public function a_staff_member_who_is_also_a_guardian_keeps_each_persona_on_its_own_surface(): void
    {
        $membership = SchoolMembership::query()->where('user_id', $this->teacher->id)->where('school_id', $this->school->id)->firstOrFail();
        $persona = $this->portalGuardian($this->school, $this->teacher, $membership);
        $this->publish($this->draft('Staff meeting'));
        $family = app(AnnouncementService::class)->createDraft($this->school, $this->creator, 'Family letter', 'For families.', CommunicationPriority::Normal, CommunicationAudienceType::Guardian, domainAudienceMemberIds: [(string) $persona['guardian']->id]);
        $this->publish($family);

        $this->signInTo($this->teacher, $this->school);
        $this->assertSame(['Family letter', 'Staff meeting'], $this->titles('/app/communications'), 'The Hub lists what was delivered in-app to this User (Phase 5B.2), unchanged.');
        $portal = collect($this->get('/app/portal/communications')->assertOk()->viewData('page')['props']['items'])->pluck('title')->all();
        $this->assertSame(['Family letter'], $portal, 'The portal is unchanged: never staff mail.');
    }

    #[Test]
    public function a_disabled_communications_module_closes_every_recipient_surface(): void
    {
        $received = $this->draft('Staff meeting');
        $file = $this->attach($received);
        $this->publish($received);
        $this->inSchool(fn () => DB::table('school_module_entitlements')->where('school_id', $this->school->id)->where('module_key', 'communications')->update(['enabled' => false]));

        $this->signInTo($this->teacher, $this->school);
        foreach (['/app/communications', '/app/communications/unread', '/app/communications/search?q=Staff', '/app/communications/conversations',
            "/app/communications/announcements/{$received->id}", "/app/communications/attachments/{$file->id}/download"] as $url) {
            $this->get($url)->assertForbidden();
        }
    }

    #[Test]
    public function authority_and_messages_are_per_school(): void
    {
        $here = $this->publish($this->draft('Here only', CommunicationAudienceType::SchoolWide));
        $elsewhere = $this->createSchool();

        // A teacher here who is a role-less member there: no Hub there.
        $this->createMembership($this->teacher, $elsewhere);
        $this->signInTo($this->teacher, $elsewhere)->get('/app/communications')->assertForbidden();

        // With the teacher role there too, School A's message never shows in School B.
        $shared = $this->withRole('teacher');
        $this->publish($this->draft('Here too', CommunicationAudienceType::SchoolWide));
        LocalCatalogueFixtures::grantRole($this->createMembership($shared, $elsewhere), Role::query()->where('key', 'teacher')->firstOrFail());
        $this->signInTo($shared, $elsewhere);
        $this->assertSame([], $this->titles('/app/communications'));
        $this->assertSame([], $this->get('/app/communications/search?q=Here')->assertOk()->viewData('page')['props']['items']);
        $this->get("/app/communications/announcements/{$here->id}")->assertNotFound();
    }
}

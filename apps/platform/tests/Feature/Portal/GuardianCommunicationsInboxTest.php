<?php

namespace Tests\Feature\Portal;

use App\Domain\Communications\Infrastructure\CommunicationAttachment;
use App\Domain\Communications\Infrastructure\CommunicationDelivery;
use App\Domain\Communications\Infrastructure\CommunicationRecipient;
use App\Domain\Identity\Application\AccountLinkService;
use App\Support\Portal\PortalUnavailableException;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCommunicationFixtures;
use Tests\Concerns\CreatesGuardianPortalFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * POR.1 (ADR 0070 §10.1, §18.2): the read-only Guardian inbox over HTTP --
 * own deliveries only, the identical 404 for anything else, recipient-owned
 * read state, recipient-scoped attachments, no staff capability needed, and
 * refused outside local/testing.
 */
class GuardianCommunicationsInboxTest extends TestCase
{
    use CreatesCommunicationFixtures, CreatesGuardianPortalFixtures, CreatesTenancyFixtures;

    private function readAt($school, $announcement, $user): ?string
    {
        return app(TenantContext::class)->withSchool($school, fn () => CommunicationDelivery::query()
            ->whereIn('recipient_id', CommunicationRecipient::query()->where('message_id', $announcement->message_id)->where('recipient_user_id', $user->id)->pluck('id'))
            ->where('channel', 'in_app')
            ->firstOrFail()->read_at?->toIso8601String());
    }

    private function attach($school, $announcement, string $name = 'notice.pdf'): CommunicationAttachment
    {
        $path = "schools/{$school->id}/communications/".Str::uuid7().'.pdf';
        Storage::disk('local')->put($path, 'PDF');

        return app(TenantContext::class)->withSchool($school, fn () => CommunicationAttachment::query()->forceCreate([
            'id' => (string) Str::uuid7(),
            'school_id' => $school->id,
            'communication_announcement_id' => $announcement->id,
            'storage_disk' => 'local',
            'storage_path' => $path,
            'original_filename' => $name,
            'safe_display_name' => $name,
            'mime_type' => 'application/pdf',
            'size_bytes' => 3,
            'checksum_sha256' => hash('sha256', 'PDF'),
            'created_by_user_id' => $announcement->created_by_user_id,
        ]));
    }

    #[Test]
    public function the_inbox_lists_only_this_guardians_own_announcements(): void
    {
        $school = $this->createSchool();
        $p = $this->portalGuardian($school);
        $other = $this->portalGuardian($school);
        $mine = $this->guardianAnnouncement($school, $p['guardian'], 'For me');
        $this->guardianAnnouncement($school, $other['guardian'], 'For someone else');

        $elsewhere = $this->createSchool();
        $this->guardianAnnouncement($elsewhere, $this->portalGuardian($elsewhere)['guardian'], 'Other School');

        $this->signInTo($p['user'], $school)->get('/app/portal/communications')->assertOk()
            ->assertInertia(fn ($page) => $page->component('App/Portal/Communications/Index')
                ->has('items', 1)->where('items.0.id', $mine->id)->where('items.0.unread', true));

        $this->get('/app/portal/communications?unread=1')->assertOk()->assertInertia(fn ($page) => $page->has('items', 1));
    }

    #[Test]
    public function a_dual_persona_sees_only_guardian_addressed_mail_in_the_portal(): void
    {
        $school = $this->createSchool();
        $user = $this->createUser();
        $membership = $this->createMembership($user, $school);
        $this->assignSchoolRole($membership, 'principal');
        $p = $this->portalGuardian($school, user: $user, membership: $membership);
        $mine = $this->guardianAnnouncement($school, $p['guardian'], 'Guardian notice');
        $staffMail = $this->createAnnouncement($school, $this->announcementCreator($school), ['status' => 'published', 'published_at' => now()]);
        $this->createAnnouncementRecipient($staffMail, $membership);

        $this->signInTo($user, $school)->get('/app/portal/communications')->assertOk()
            ->assertInertia(fn ($page) => $page->has('items', 1)->where('items.0.id', $mine->id));
    }

    #[Test]
    public function opening_marks_only_the_readers_own_delivery_and_never_changes_content(): void
    {
        $school = $this->createSchool();
        $p = $this->portalGuardian($school);
        $q = $this->portalGuardian($school);
        // One announcement to both Guardians.
        $announcement = $this->guardianAnnouncement($school, $p['guardian'], 'Shared');
        $this->createGuardianAnnouncementRecipient($announcement, $q['guardian']);
        $qDelivery = app(TenantContext::class)->withSchool($school, function () use ($announcement, $q) {
            $recipient = CommunicationRecipient::query()->create(['school_id' => $announcement->school_id, 'message_id' => $announcement->message_id, 'recipient_user_id' => $q['user']->id]);

            return CommunicationDelivery::query()->create(['school_id' => $announcement->school_id, 'recipient_id' => $recipient->id, 'channel' => 'in_app', 'status' => 'delivered']);
        });
        $snapshot = fn () => app(TenantContext::class)->withSchool($school, fn () => [...$announcement->fresh()->only(['title', 'body', 'status']), 'updatedAt' => $announcement->fresh()->updated_at?->toIso8601String()]);
        $before = $snapshot();

        $this->signInTo($p['user'], $school)->get("/app/portal/communications/announcements/{$announcement->id}")->assertOk()
            ->assertInertia(fn ($page) => $page->component('App/Portal/Communications/Show')->where('announcement.title', 'Shared'));

        $this->assertNotNull($this->readAt($school, $announcement, $p['user']));
        $this->assertNull(app(TenantContext::class)->withSchool($school, fn () => $qDelivery->fresh()->read_at), 'Another recipient is untouched.');
        $this->assertSame($before, $snapshot(), 'School content is never changed by reading it.');
    }

    #[Test]
    public function anything_not_this_guardians_is_the_same_404(): void
    {
        $school = $this->createSchool();
        $p = $this->portalGuardian($school);
        $otherGuardianNotice = $this->guardianAnnouncement($school, $this->portalGuardian($school)['guardian']);
        $elsewhere = $this->createSchool();
        $otherSchoolNotice = $this->guardianAnnouncement($elsewhere, $this->portalGuardian($elsewhere)['guardian']);
        $this->signInTo($p['user'], $school);

        $bodies = [];
        foreach ([$otherGuardianNotice->id, $otherSchoolNotice->id, (string) Str::uuid7()] as $id) {
            $bodies[] = $this->get("/app/portal/communications/announcements/{$id}")->assertNotFound()->getContent();
        }
        $this->assertCount(1, array_unique($bodies), 'Unknown, another Guardian\'s and another School\'s are indistinguishable.');
        $this->get('/app/portal/communications/announcements/not-a-uuid')->assertNotFound();
    }

    #[Test]
    public function attachments_are_recipient_scoped(): void
    {
        Storage::fake('local');
        $school = $this->createSchool();
        $p = $this->portalGuardian($school);
        $mine = $this->guardianAnnouncement($school, $p['guardian']);
        $theirs = $this->guardianAnnouncement($school, $this->portalGuardian($school)['guardian']);
        $myFile = $this->attach($school, $mine);
        $theirFile = $this->attach($school, $theirs);
        $this->signInTo($p['user'], $school);

        $this->get("/app/portal/communications/announcements/{$mine->id}/attachments/{$myFile->id}/download")->assertOk();
        // Another Guardian's file, by its own announcement or smuggled under mine: the same 404.
        $this->get("/app/portal/communications/announcements/{$theirs->id}/attachments/{$theirFile->id}/download")->assertNotFound();
        $this->get("/app/portal/communications/announcements/{$mine->id}/attachments/{$theirFile->id}/download")->assertNotFound();
        $this->assertTrue(app(TenantContext::class)->withSchool($school, fn () => \DB::table('school_audit_events')
            ->where('event_type', 'communication_attachment.downloaded')->where('subject_id', $myFile->id)->exists()));
    }

    #[Test]
    public function the_portal_needs_the_guardian_capability_not_a_staff_one(): void
    {
        $school = $this->createSchool();

        // Staff with communications.view but no portal capability: refused.
        $staff = $this->createUserWithCapabilities($school, ['communications.view']);
        $this->signInTo($staff, $school)->get('/app/portal/communications')->assertForbidden();

        // A linked Guardian whose grant was never made (manual link): refused.
        $guardian = $this->createGuardian($school);
        $this->createStudentGuardianRelationship($this->createStudent($school), $guardian, ['is_legal_guardian' => true]);
        $user = $this->createUser();
        app(AccountLinkService::class)->linkGuardian($school, $guardian, $this->createMembership($user, $school), $this->portalAdmin($school));
        $this->signInTo($user, $school)->get('/app/portal/communications')->assertForbidden();

        // The Guardian reaches the portal with no staff Communications capability at all.
        $p = $this->portalGuardian($school);
        $this->signInTo($p['user'], $school)->get('/app/portal/communications')->assertOk();
        $this->get('/app/communications')->assertForbidden();
    }

    #[Test]
    public function a_revoked_guardian_is_denied_on_the_next_request(): void
    {
        $school = $this->createSchool();
        $p = $this->portalGuardian($school);
        $notice = $this->guardianAnnouncement($school, $p['guardian']);
        $this->signInTo($p['user'], $school)->get("/app/portal/communications/announcements/{$notice->id}")->assertOk();

        app(AccountLinkService::class)->unlinkGuardian($school, $p['guardian'], $this->portalAdmin($school));

        $this->get("/app/portal/communications/announcements/{$notice->id}")->assertForbidden();
        $this->get('/app/portal/communications')->assertForbidden();
    }

    #[Test]
    public function production_and_staging_refuse_the_portal_first(): void
    {
        $school = $this->createSchool();
        $p = $this->portalGuardian($school);
        $notice = $this->guardianAnnouncement($school, $p['guardian']);
        $this->signInTo($p['user'], $school);
        $this->withoutMiddleware(PreventRequestForgery::class);

        foreach (['production', 'staging'] as $environment) {
            $this->app['env'] = $environment;
            $this->get('/app/portal/communications')->assertForbidden()->assertSee(PortalUnavailableException::MESSAGE);
            $this->getJson('/app/portal/communications')->assertForbidden()
                ->assertExactJson(['error' => ['code' => 'PORTAL_UNAVAILABLE', 'message' => PortalUnavailableException::MESSAGE, 'status' => 403]]);
            $this->get("/app/portal/communications/announcements/{$notice->id}")->assertForbidden();
            $this->get('/app')->assertOk()->assertInertia(fn ($page) => $page->where('nav.canViewGuardianPortal', false));
            $this->app['env'] = 'testing';
        }
        $this->assertNull($this->readAt($school, $notice, $p['user']), 'Nothing was marked read.');
    }
}

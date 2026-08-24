<?php

namespace Tests\Feature\Communications;

use App\Domain\Communications\Application\AnnouncementService;
use App\Domain\Communications\Application\Approval\CommunicationApprovalService;
use App\Domain\Communications\Application\CommunicationDeliveryFactory;
use App\Domain\Communications\Application\CommunicationInboxReadModel;
use App\Domain\Communications\Domain\CommunicationAudienceType;
use App\Domain\Communications\Domain\CommunicationChannel;
use App\Domain\Communications\Domain\CommunicationPriority;
use App\Domain\Communications\Domain\CommunicationRequirement;
use App\Domain\Communications\Infrastructure\CommunicationAnnouncementRecipient;
use App\Domain\Communications\Infrastructure\CommunicationDelivery;
use App\Domain\Communications\Infrastructure\CommunicationDeliveryPolicyDecision;
use App\Domain\Communications\Infrastructure\CommunicationRecipient;
use App\Domain\Identity\Application\AccountLinkService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCommunicationFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 5B.2 §39-§46 -- Student/Guardian IN_APP reachability through an
 * explicit account link, reusing the real SchoolMembership delivery
 * pipeline. Every scenario goes through the real
 * App\Domain\Communications\Application\AnnouncementService::publish()
 * and App\Domain\Identity\Application\AccountLinkService, matching
 * this suite's established convention.
 */
class StudentGuardianAccountLinkInAppTest extends TestCase
{
    use CreatesCommunicationFixtures, CreatesTenancyFixtures;

    private function announcements(): AnnouncementService
    {
        return app(AnnouncementService::class);
    }

    private function links(): AccountLinkService
    {
        return app(AccountLinkService::class);
    }

    private function deliveriesFor($school, ?string $messageId): Collection
    {
        return app(TenantContext::class)->withSchool($school, fn () => CommunicationDelivery::query()
            ->whereIn('recipient_id', CommunicationRecipient::query()->where('message_id', $messageId)->pluck('id'))
            ->get());
    }

    // --- §39: publish IN_APP for linked/unlinked parties -----------------

    #[Test]
    public function publishing_to_an_unlinked_guardian_creates_no_in_app_delivery(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $guardian = $this->createGuardian($school);

        $announcement = $this->announcements()->createDraft(
            $school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::Guardian,
            domainAudienceMemberIds: [$guardian->id], channels: [CommunicationChannel::InApp],
        );
        $published = $this->announcements()->publish($announcement, $creator);

        $this->assertCount(0, $this->deliveriesFor($school, $published->message_id));
    }

    #[Test]
    public function publishing_to_a_linked_active_guardian_creates_a_real_in_app_delivery_to_the_linked_membership(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $member = $this->createUser();
        $membership = $this->createMembership($member, $school);
        $guardian = $this->createGuardian($school);
        $this->links()->linkGuardian($school, $guardian, $membership, $creator);

        $announcement = $this->announcements()->createDraft(
            $school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::Guardian,
            domainAudienceMemberIds: [$guardian->id], channels: [CommunicationChannel::InApp],
        );
        $published = $this->announcements()->publish($announcement, $creator);

        $deliveries = $this->deliveriesFor($school, $published->message_id);
        $this->assertCount(1, $deliveries);
        $this->assertSame('delivered', $deliveries->first()->status);

        $recipient = app(TenantContext::class)->withSchool($school, fn () => CommunicationRecipient::query()
            ->where('id', $deliveries->first()->recipient_id)->first());
        $this->assertSame($member->id, $recipient->recipient_user_id);

        // §22: the AUDIENCE snapshot still says Guardian -- provenance preserved.
        $snapshot = app(TenantContext::class)->withSchool($school, fn () => CommunicationAnnouncementRecipient::query()
            ->where('announcement_id', $published->id)->first());
        $this->assertSame($guardian->id, $snapshot->guardian_id);
        $this->assertNull($snapshot->user_id);
    }

    #[Test]
    public function publishing_to_a_guardian_linked_to_an_inactive_membership_creates_no_in_app_delivery(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $member = $this->createUser();
        $membership = $this->createMembership($member, $school, 'suspended');
        $guardian = $this->createGuardian($school);
        $this->links()->linkGuardian($school, $guardian, $membership, $creator);

        $announcement = $this->announcements()->createDraft(
            $school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::Guardian,
            domainAudienceMemberIds: [$guardian->id], channels: [CommunicationChannel::InApp],
        );
        $published = $this->announcements()->publish($announcement, $creator);

        $this->assertCount(0, $this->deliveriesFor($school, $published->message_id));

        $reason = app(TenantContext::class)->withSchool($school, fn () => CommunicationDeliveryPolicyDecision::query()
            ->where('message_id', $published->message_id)->where('recipient_guardian_id', $guardian->id)
            ->where('channel', 'in_app')->value('reason'));
        $this->assertSame('recipient_ineligible', $reason);
    }

    #[Test]
    public function publishing_to_a_linked_active_student_creates_a_real_in_app_delivery(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $member = $this->createUser();
        $membership = $this->createMembership($member, $school);
        $student = $this->createStudent($school);
        $this->links()->linkStudent($school, $student, $membership, $creator);

        $announcement = $this->announcements()->createDraft(
            $school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::Student,
            domainAudienceMemberIds: [$student->id], channels: [CommunicationChannel::InApp],
        );
        $published = $this->announcements()->publish($announcement, $creator);

        $deliveries = $this->deliveriesFor($school, $published->message_id);
        $this->assertCount(1, $deliveries);
        $this->assertSame('delivered', $deliveries->first()->status);
    }

    #[Test]
    public function publishing_to_an_unlinked_student_creates_no_in_app_delivery(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $student = $this->createStudent($school);

        $announcement = $this->announcements()->createDraft(
            $school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::Student,
            domainAudienceMemberIds: [$student->id], channels: [CommunicationChannel::InApp],
        );
        $published = $this->announcements()->publish($announcement, $creator);

        $this->assertCount(0, $this->deliveriesFor($school, $published->message_id));

        $reason = app(TenantContext::class)->withSchool($school, fn () => CommunicationDeliveryPolicyDecision::query()
            ->where('message_id', $published->message_id)->where('recipient_student_id', $student->id)
            ->where('channel', 'in_app')->value('reason'));
        $this->assertSame('recipient_ineligible', $reason);
    }

    // --- §41: link change before publication ------------------------------

    #[Test]
    public function linking_after_drafting_but_before_publication_makes_in_app_reachable_at_publish_time(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $guardian = $this->createGuardian($school);

        $announcement = $this->announcements()->createDraft(
            $school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::Guardian,
            domainAudienceMemberIds: [$guardian->id], channels: [CommunicationChannel::InApp],
        );

        $member = $this->createUser();
        $membership = $this->createMembership($member, $school);
        $this->links()->linkGuardian($school, $guardian, $membership, $creator);

        $published = $this->announcements()->publish($announcement, $creator);

        $this->assertCount(1, $this->deliveriesFor($school, $published->message_id));
    }

    #[Test]
    public function unlinking_after_drafting_but_before_publication_makes_in_app_unreachable_at_publish_time(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $guardian = $this->createGuardian($school);
        $membership = $this->createMembership($this->createUser(), $school);
        $this->links()->linkGuardian($school, $guardian, $membership, $creator);

        $announcement = $this->announcements()->createDraft(
            $school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::Guardian,
            domainAudienceMemberIds: [$guardian->id], channels: [CommunicationChannel::InApp],
        );

        $this->links()->unlinkGuardian($school, $guardian, $creator);

        $published = $this->announcements()->publish($announcement, $creator);

        $this->assertCount(0, $this->deliveriesFor($school, $published->message_id));
    }

    // --- §42: link change after publication --------------------------------

    #[Test]
    public function unlinking_after_publication_does_not_remove_the_historical_delivery(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $member = $this->createUser();
        $membership = $this->createMembership($member, $school);
        $guardian = $this->createGuardian($school);
        $this->links()->linkGuardian($school, $guardian, $membership, $creator);

        $announcement = $this->announcements()->createDraft(
            $school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::Guardian,
            domainAudienceMemberIds: [$guardian->id], channels: [CommunicationChannel::InApp],
        );
        $published = $this->announcements()->publish($announcement, $creator);

        $this->links()->unlinkGuardian($school, $guardian, $creator);

        $deliveries = $this->deliveriesFor($school, $published->message_id);
        $this->assertCount(1, $deliveries, 'Unlinking must never delete a historical delivery.');
        $this->assertSame('delivered', $deliveries->first()->status);
    }

    // --- §43: read/unread ----------------------------------------------------

    #[Test]
    public function a_linked_guardians_own_login_can_view_and_mark_the_announcement_read(): void
    {
        // §11/§36: the realistic scenario today -- no Student/Guardian
        // portal role exists yet (Phase 5B.2 §12), so a linked
        // membership needs a role granting `communications.view` the
        // same way any other member would (a staff member who is also
        // a Guardian, e.g. a teacher-parent).
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $member = $this->createUser();
        $membership = $this->createMembership($member, $school);
        $this->assignSchoolRole($membership, 'principal');
        $guardian = $this->createGuardian($school);
        $this->links()->linkGuardian($school, $guardian, $membership, $creator);

        $announcement = $this->announcements()->createDraft(
            $school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::Guardian,
            domainAudienceMemberIds: [$guardian->id], channels: [CommunicationChannel::InApp],
        );
        $published = $this->announcements()->publish($announcement, $creator);

        $this->actingAs($member)->post("/app/schools/{$school->id}/activate");
        $this->actingAs($member)->get("/app/communications/announcements/{$published->id}")->assertOk();

        $delivery = app(TenantContext::class)->withSchool($school, fn () => $this->deliveriesFor($school, $published->message_id)->first()->fresh());
        $this->assertSame('read', $delivery->status);
        $this->assertNotNull($delivery->read_at);
    }

    #[Test]
    public function a_linked_guardians_own_inbox_shows_the_announcement(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $member = $this->createUser();
        $membership = $this->createMembership($member, $school);
        $guardian = $this->createGuardian($school);
        $this->links()->linkGuardian($school, $guardian, $membership, $creator);

        $announcement = $this->announcements()->createDraft(
            $school, $creator, 'Report Card Notice', 'B', CommunicationPriority::Normal, CommunicationAudienceType::Guardian,
            domainAudienceMemberIds: [$guardian->id], channels: [CommunicationChannel::InApp],
        );
        $published = $this->announcements()->publish($announcement, $creator);

        $items = app(CommunicationInboxReadModel::class)->unread($school, $member);

        $this->assertTrue($items->contains(fn ($i) => $i->id === $published->id));
    }

    // --- §47: approval fingerprint unaffected by link state ---------------

    #[Test]
    public function linking_or_unlinking_a_guardian_never_invalidates_an_existing_approval(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $approver = $this->createUser();
        $approverMembership = $this->createMembership($approver, $school);
        $this->assignSchoolRole($approverMembership, 'principal');
        $guardian = $this->createGuardian($school);
        $this->createApprovalPolicy($school, ['require_required_communication_approval' => true]);

        $announcement = $this->announcements()->createDraft(
            $school, $admin, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::Guardian,
            domainAudienceMemberIds: [$guardian->id], channels: [CommunicationChannel::InApp],
            requirement: CommunicationRequirement::Required,
        );
        $approvalService = app(CommunicationApprovalService::class);
        $request = $approvalService->submit($announcement, $admin);
        $approvalService->approve($request, $approver);

        $membership = $this->createMembership($this->createUser(), $school);
        $this->links()->linkGuardian($school, $guardian, $membership, $admin);

        $fresh = app(TenantContext::class)->withSchool($school, fn () => $announcement->fresh());
        $this->assertSame('approved', $fresh->status);
    }

    // --- §40: deduplication (defense-in-depth) ------------------------------

    #[Test]
    public function creating_the_same_user_recipient_twice_for_one_message_never_duplicates(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $member = $this->createUser();
        $this->createMembership($member, $school);
        $thread = $this->createThread($school, $creator);
        $message = $this->createMessage($thread, $creator);

        $factory = app(CommunicationDeliveryFactory::class);
        $first = app(TenantContext::class)->withSchool($school, fn () => $factory->createRecipient($school->id, $message->id, $member->id));
        $second = app(TenantContext::class)->withSchool($school, fn () => $factory->createRecipient($school->id, $message->id, $member->id));

        $this->assertSame($first->id, $second->id);
        $count = app(TenantContext::class)->withSchool($school, fn () => CommunicationRecipient::query()
            ->where('message_id', $message->id)->where('recipient_user_id', $member->id)->count());
        $this->assertSame(1, $count);
    }

    // --- §49: performance ----------------------------------------------------

    #[Test]
    public function publishing_in_app_to_many_linked_guardians_uses_a_bounded_query_count(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $guardianIds = [];
        for ($i = 0; $i < 20; $i++) {
            $guardian = $this->createGuardian($school);
            $membership = $this->createMembership($this->createUser(), $school);
            $this->links()->linkGuardian($school, $guardian, $membership, $creator);
            $guardianIds[] = $guardian->id;
        }

        $announcement = $this->announcements()->createDraft(
            $school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::Guardian,
            domainAudienceMemberIds: $guardianIds, channels: [CommunicationChannel::InApp],
        );

        DB::enableQueryLog();
        $published = $this->announcements()->publish($announcement, $creator);
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertCount(20, $this->deliveriesFor($school, $published->message_id));
        // Generous bound -- createRecipient()/createDelivery() each
        // wrap their write in its own DB::transaction() (a SAVEPOINT,
        // counted as extra queries), the same per-recipient shape the
        // pre-existing membership loop already has -- this is NOT an
        // N+1 on link resolution specifically (that part is ONE
        // batched query regardless of audience size, proven by
        // AccountLinkServiceTest::batch_link_lookup_for_many_guardians_uses_a_bounded_query_count).
        // This bound only guards against a REGRESSION back to one
        // query per Guardian for the link lookup itself (which would
        // add ~20 extra queries on top of this baseline).
        $this->assertLessThan(350, $queryCount);
    }
}

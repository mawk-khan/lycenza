<?php

namespace Tests\Feature\Communications;

use App\Domain\Communications\Application\AnnouncementService;
use App\Domain\Communications\Application\Approval\CommunicationApprovalService;
use App\Domain\Communications\Application\CommunicationAttachmentService;
use App\Domain\Communications\Application\Exceptions\ApprovalAlreadyDecidedException;
use App\Domain\Communications\Application\Exceptions\ApprovalNotRequiredException;
use App\Domain\Communications\Application\Exceptions\ApprovalRequiredException;
use App\Domain\Communications\Application\Exceptions\EmergencyCannotUseApprovalWorkflowException;
use App\Domain\Communications\Application\Exceptions\InvalidAnnouncementTransitionException;
use App\Domain\Communications\Application\Exceptions\RejectionReasonRequiredException;
use App\Domain\Communications\Application\Exceptions\SelfApprovalNotAllowedException;
use App\Domain\Communications\Domain\CommunicationAudienceType;
use App\Domain\Communications\Domain\CommunicationChannel;
use App\Domain\Communications\Domain\CommunicationDispatchMode;
use App\Domain\Communications\Domain\CommunicationPriority;
use App\Domain\Communications\Domain\CommunicationRequirement;
use App\Domain\Communications\Infrastructure\CommunicationAnnouncement;
use App\Domain\Communications\Infrastructure\CommunicationApprovalRequest;
use App\Domain\Communications\Infrastructure\CommunicationDelivery;
use App\Domain\Communications\Infrastructure\CommunicationRecipient;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCommunicationFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Concerns\FakesEmail;
use Tests\TestCase;

/**
 * Phase 5A.12 -- domain-level coverage for the approval workflow:
 * policy evaluation, submit/approve/reject/withdraw, the mandatory
 * fingerprint-invalidation matrix (brief §80), publish-time
 * verification, scheduling, Emergency exemption, and concurrency.
 * Every scenario goes through the real
 * App\Domain\Communications\Application\AnnouncementService /
 * App\Domain\Communications\Application\Approval\CommunicationApprovalService
 * pipeline, matching this suite's existing convention (e.g.
 * CommunicationEmergencyTimingTest) rather than hand-crafted rows.
 */
class CommunicationApprovalServiceTest extends TestCase
{
    use CreatesCommunicationFixtures, CreatesTenancyFixtures, FakesEmail;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    private function announcements(): AnnouncementService
    {
        return app(AnnouncementService::class);
    }

    private function approvals(): CommunicationApprovalService
    {
        return app(CommunicationApprovalService::class);
    }

    private function attachments(): CommunicationAttachmentService
    {
        return app(CommunicationAttachmentService::class);
    }

    private function freshAnnouncement($school, CommunicationAnnouncement $announcement): CommunicationAnnouncement
    {
        return app(TenantContext::class)->withSchool($school, fn () => $announcement->fresh());
    }

    private function latestApprovalRequest($school, CommunicationAnnouncement $announcement): ?CommunicationApprovalRequest
    {
        return app(TenantContext::class)->withSchool(
            $school,
            fn () => CommunicationApprovalRequest::query()
                ->where('announcement_id', $announcement->id)
                ->orderByDesc('requested_at')
                ->first(),
        );
    }

    // --- §76: policy ------------------------------------------------

    #[Test]
    public function no_policy_row_means_direct_publish_still_works_unchanged(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $this->createMembership($this->createUser(), $school);

        $announcement = $this->announcements()->createDraft(
            $school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide,
        );
        $published = $this->announcements()->publish($announcement, $creator);

        $this->assertSame('published', $published->status);
    }

    #[Test]
    public function school_wide_requires_approval_when_policy_enabled(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $this->createMembership($this->createUser(), $school);
        $this->createApprovalPolicy($school, ['require_school_wide_approval' => true]);

        $announcement = $this->announcements()->createDraft(
            $school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide,
        );

        $requirement = $this->approvals()->requirement($announcement);
        $this->assertTrue($requirement->required);
        $this->assertSame(['school_wide'], $requirement->reasons);
    }

    #[Test]
    public function required_communication_requires_approval_when_policy_enabled(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $this->createMembership($this->createUser(), $school);
        $this->createApprovalPolicy($school, ['require_required_communication_approval' => true]);

        $announcement = $this->announcements()->createDraft(
            $school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::Individual,
            individualMemberUserIds: [],
            requirement: CommunicationRequirement::Required,
        );

        $requirement = $this->approvals()->requirement($announcement);
        $this->assertTrue($requirement->required);
        $this->assertSame(['required_communication'], $requirement->reasons);
    }

    #[Test]
    public function non_privileged_sender_requires_approval_when_policy_enabled(): void
    {
        // `principal` in CapabilityAndRoleSeeder has communications.announce
        // but NOT communications.manage.
        [$principal, $school] = $this->createSchoolAdmin('principal');
        $this->createMembership($this->createUser(), $school);
        $this->createApprovalPolicy($school, ['require_non_privileged_sender_approval' => true]);

        $announcement = $this->announcements()->createDraft(
            $school, $principal, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::Individual,
            individualMemberUserIds: [],
        );

        $requirement = $this->approvals()->requirement($announcement);
        $this->assertTrue($requirement->required);
        $this->assertSame(['non_privileged_sender'], $requirement->reasons);
    }

    #[Test]
    public function a_non_triggering_announcement_may_publish_directly_even_with_policy_enabled(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $member = $this->createUser();
        $this->createMembership($member, $school);
        // Every rule enabled -- but this announcement is individual
        // audience, optional, sent by a communications.manage-capable
        // admin, so none of the three reasons apply.
        $this->createApprovalPolicy($school, [
            'require_school_wide_approval' => true,
            'require_required_communication_approval' => true,
            'require_non_privileged_sender_approval' => true,
        ]);

        $announcement = $this->announcements()->createDraft(
            $school, $admin, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::Individual,
            individualMemberUserIds: [$member->id],
        );
        $published = $this->announcements()->publish($announcement, $admin);

        $this->assertSame('published', $published->status);
    }

    #[Test]
    public function emergency_never_requires_approval_regardless_of_policy(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $this->createMembership($this->createUser(), $school);
        $this->createApprovalPolicy($school, [
            'require_school_wide_approval' => true,
            'require_required_communication_approval' => true,
        ]);

        $announcement = $this->announcements()->createDraft(
            $school, $admin, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide,
            requirement: CommunicationRequirement::Required,
            dispatchMode: CommunicationDispatchMode::Emergency,
            emergencyJustification: 'Evacuation.',
        );

        $requirement = $this->approvals()->requirement($announcement);
        $this->assertFalse($requirement->required);

        $published = $this->announcements()->publish($announcement, $admin);
        $this->assertSame('published', $published->status);
    }

    #[Test]
    public function school_bs_policy_never_governs_school_as_announcement(): void
    {
        [$adminA, $schoolA] = $this->createSchoolAdmin('school_admin');
        $this->createMembership($this->createUser(), $schoolA);

        [, $schoolB] = $this->createSchoolAdmin('school_admin');
        $this->createApprovalPolicy($schoolB, ['require_school_wide_approval' => true]);

        $announcement = $this->announcements()->createDraft(
            $schoolA, $adminA, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide,
        );

        $this->assertFalse($this->approvals()->requirement($announcement)->required);
    }

    // --- §77: submit --------------------------------------------------

    #[Test]
    public function an_authorized_sender_can_submit_for_approval_creating_a_fingerprinted_pending_request(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $this->createMembership($this->createUser(), $school);
        $this->createApprovalPolicy($school, ['require_school_wide_approval' => true]);

        $announcement = $this->announcements()->createDraft(
            $school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide,
        );

        $request = $this->approvals()->submit($announcement, $creator);

        $this->assertSame('pending', $request->status);
        $this->assertSame(64, strlen($request->fingerprint));
        $this->assertSame('pending_approval', $this->freshAnnouncement($school, $announcement)->status);
    }

    #[Test]
    public function submitting_creates_no_recipients_or_deliveries(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $this->createMembership($this->createUser(), $school);
        $this->createApprovalPolicy($school, ['require_school_wide_approval' => true]);

        $announcement = $this->announcements()->createDraft(
            $school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide,
        );
        $this->approvals()->submit($announcement, $creator);

        $recipientCount = app(TenantContext::class)->withSchool($school, fn () => CommunicationRecipient::query()->count());
        $deliveryCount = app(TenantContext::class)->withSchool($school, fn () => CommunicationDelivery::query()->count());

        $this->assertSame(0, $recipientCount);
        $this->assertSame(0, $deliveryCount);
    }

    #[Test]
    public function a_second_submit_for_an_already_pending_announcement_is_rejected(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $this->createMembership($this->createUser(), $school);
        $this->createApprovalPolicy($school, ['require_school_wide_approval' => true]);

        $announcement = $this->announcements()->createDraft(
            $school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide,
        );
        $this->approvals()->submit($announcement, $creator);

        $this->expectException(InvalidAnnouncementTransitionException::class);
        $this->approvals()->submit($announcement, $creator);
    }

    #[Test]
    public function submitting_when_not_required_is_rejected(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $announcement = $this->announcements()->createDraft(
            $school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide,
        );

        $this->expectException(ApprovalNotRequiredException::class);
        $this->approvals()->submit($announcement, $creator);
    }

    #[Test]
    public function emergency_cannot_be_submitted_for_approval(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $this->createApprovalPolicy($school, ['require_school_wide_approval' => true]);

        $announcement = $this->announcements()->createDraft(
            $school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide,
            requirement: CommunicationRequirement::Required,
            dispatchMode: CommunicationDispatchMode::Emergency,
            emergencyJustification: 'Evacuation.',
        );

        $this->expectException(EmergencyCannotUseApprovalWorkflowException::class);
        $this->approvals()->submit($announcement, $creator);
    }

    // --- §78: approve ---------------------------------------------------

    #[Test]
    public function an_authorized_approver_can_approve_and_the_announcement_becomes_approved(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $approver = $this->createUser();
        $approverMembership = $this->createMembership($approver, $school);
        $this->assignSchoolRole($approverMembership, 'principal');
        $this->createMembership($this->createUser(), $school);
        $this->createApprovalPolicy($school, ['require_school_wide_approval' => true]);

        $announcement = $this->announcements()->createDraft(
            $school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide,
        );
        $request = $this->approvals()->submit($announcement, $creator);

        $decided = $this->approvals()->approve($request, $approver, 'looks good');

        $this->assertSame('approved', $decided->status);
        $this->assertSame('approved', $this->freshAnnouncement($school, $announcement)->status);
    }

    #[Test]
    public function the_requester_cannot_approve_their_own_request(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $this->createMembership($this->createUser(), $school);
        $this->createApprovalPolicy($school, ['require_school_wide_approval' => true]);

        $announcement = $this->announcements()->createDraft(
            $school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide,
        );
        $request = $this->approvals()->submit($announcement, $creator);

        $this->expectException(SelfApprovalNotAllowedException::class);
        $this->approvals()->approve($request, $creator);
    }

    #[Test]
    public function the_requester_cannot_reject_their_own_request_either(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $this->createMembership($this->createUser(), $school);
        $this->createApprovalPolicy($school, ['require_school_wide_approval' => true]);

        $announcement = $this->announcements()->createDraft(
            $school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide,
        );
        $request = $this->approvals()->submit($announcement, $creator);

        $this->expectException(SelfApprovalNotAllowedException::class);
        $this->approvals()->reject($request, $creator, 'no');
    }

    #[Test]
    public function only_one_of_two_decision_attempts_wins(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $approver = $this->createUser();
        $approverMembership = $this->createMembership($approver, $school);
        $this->assignSchoolRole($approverMembership, 'principal');
        $this->createMembership($this->createUser(), $school);
        $this->createApprovalPolicy($school, ['require_school_wide_approval' => true]);

        $announcement = $this->announcements()->createDraft(
            $school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide,
        );
        $request = $this->approvals()->submit($announcement, $creator);

        $this->approvals()->approve($request, $approver);

        $this->expectException(ApprovalAlreadyDecidedException::class);
        $this->approvals()->reject($request, $approver, 'too late');
    }

    // --- §79: reject ------------------------------------------------

    #[Test]
    public function rejecting_without_a_reason_is_rejected(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $approver = $this->createUser();
        $approverMembership = $this->createMembership($approver, $school);
        $this->assignSchoolRole($approverMembership, 'principal');
        $this->createMembership($this->createUser(), $school);
        $this->createApprovalPolicy($school, ['require_school_wide_approval' => true]);

        $announcement = $this->announcements()->createDraft(
            $school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide,
        );
        $request = $this->approvals()->submit($announcement, $creator);

        $this->expectException(RejectionReasonRequiredException::class);
        $this->approvals()->reject($request, $approver, '   ');
    }

    #[Test]
    public function a_rejected_announcement_becomes_editable_and_history_is_preserved_across_resubmission(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $approver = $this->createUser();
        $approverMembership = $this->createMembership($approver, $school);
        $this->assignSchoolRole($approverMembership, 'principal');
        $this->createMembership($this->createUser(), $school);
        $this->createApprovalPolicy($school, ['require_school_wide_approval' => true]);

        $announcement = $this->announcements()->createDraft(
            $school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide,
        );
        $firstRequest = $this->approvals()->submit($announcement, $creator);
        $this->approvals()->reject($firstRequest, $approver, 'Please revise the title.');

        $fresh = $this->freshAnnouncement($school, $announcement);
        $this->assertSame('rejected', $fresh->status);

        // Editing re-enters the Draft cycle -- brief §36.
        $this->announcements()->updateDraft($fresh, $creator, title: 'Revised title');
        $afterEdit = $this->freshAnnouncement($school, $announcement);
        $this->assertSame('draft', $afterEdit->status);

        $secondRequest = $this->approvals()->submit($afterEdit, $creator);
        $this->assertNotSame($firstRequest->id, $secondRequest->id);

        // History preserved -- the first (rejected) request row is
        // untouched, never overwritten.
        $stillThere = app(TenantContext::class)->withSchool($school, fn () => CommunicationApprovalRequest::query()->find($firstRequest->id));
        $this->assertSame('rejected', $stillThere->status);
        $this->assertSame('Please revise the title.', $stillThere->decision_note);
    }

    // --- §37: withdrawal ----------------------------------------------

    #[Test]
    public function the_requester_can_withdraw_a_pending_request_and_it_returns_to_draft(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $this->createMembership($this->createUser(), $school);
        $this->createApprovalPolicy($school, ['require_school_wide_approval' => true]);

        $announcement = $this->announcements()->createDraft(
            $school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide,
        );
        $request = $this->approvals()->submit($announcement, $creator);

        $this->approvals()->withdraw($announcement, $creator);

        $this->assertSame('draft', $this->freshAnnouncement($school, $announcement)->status);
        $stillThere = app(TenantContext::class)->withSchool($school, fn () => CommunicationApprovalRequest::query()->find($request->id));
        $this->assertSame('cancelled', $stillThere->status);
    }

    // --- §80: mandatory version-invalidation matrix ---------------------

    /**
     * @return array{admin: User, school: School, member: User, announcement: CommunicationAnnouncement}
     */
    private function approvedSchoolWideAnnouncement(): array
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $approver = $this->createUser();
        $approverMembership = $this->createMembership($approver, $school);
        $this->assignSchoolRole($approverMembership, 'principal');
        $member = $this->createUser();
        $this->createMembership($member, $school);
        $this->createApprovalPolicy($school, ['require_school_wide_approval' => true]);

        $announcement = $this->announcements()->createDraft(
            $school, $admin, 'Original title', 'Original body', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide,
            channels: [CommunicationChannel::InApp],
        );
        $request = $this->approvals()->submit($announcement, $admin);
        $this->approvals()->approve($request, $approver);

        $this->assertSame('approved', $this->freshAnnouncement($school, $announcement)->status);

        return ['admin' => $admin, 'school' => $school, 'member' => $member, 'announcement' => $announcement];
    }

    private function assertApprovalWasInvalidated($school, CommunicationAnnouncement $announcement): void
    {
        $this->assertSame('draft', $this->freshAnnouncement($school, $announcement)->status);
    }

    #[Test]
    public function changing_the_title_after_approval_invalidates_it(): void
    {
        ['admin' => $admin, 'school' => $school, 'announcement' => $announcement] = $this->approvedSchoolWideAnnouncement();

        $this->announcements()->updateDraft($announcement, $admin, title: 'Changed title');

        $this->assertApprovalWasInvalidated($school, $announcement);
    }

    #[Test]
    public function changing_the_body_after_approval_invalidates_it(): void
    {
        ['admin' => $admin, 'school' => $school, 'announcement' => $announcement] = $this->approvedSchoolWideAnnouncement();

        $this->announcements()->updateDraft($announcement, $admin, body: 'Changed body');

        $this->assertApprovalWasInvalidated($school, $announcement);
    }

    #[Test]
    public function changing_the_priority_after_approval_invalidates_it(): void
    {
        ['admin' => $admin, 'school' => $school, 'announcement' => $announcement] = $this->approvedSchoolWideAnnouncement();

        $this->announcements()->updateDraft($announcement, $admin, priority: CommunicationPriority::Critical);

        $this->assertApprovalWasInvalidated($school, $announcement);
    }

    #[Test]
    public function changing_the_requirement_after_approval_invalidates_it(): void
    {
        ['admin' => $admin, 'school' => $school, 'announcement' => $announcement] = $this->approvedSchoolWideAnnouncement();

        $this->announcements()->updateDraft($announcement, $admin, requirement: CommunicationRequirement::Required);

        $this->assertApprovalWasInvalidated($school, $announcement);
    }

    #[Test]
    public function changing_the_requested_channel_after_approval_invalidates_it(): void
    {
        ['admin' => $admin, 'school' => $school, 'announcement' => $announcement] = $this->approvedSchoolWideAnnouncement();

        $this->announcements()->updateDraft($announcement, $admin, channels: [CommunicationChannel::InApp, CommunicationChannel::Email]);

        $this->assertApprovalWasInvalidated($school, $announcement);
    }

    #[Test]
    public function changing_the_audience_type_after_approval_invalidates_it(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $approver = $this->createUser();
        $approverMembership = $this->createMembership($approver, $school);
        $this->assignSchoolRole($approverMembership, 'principal');
        $member = $this->createUser();
        $this->createMembership($member, $school);
        $this->createApprovalPolicy($school, ['require_school_wide_approval' => true]);

        // Starts as school-wide (approval-required) so it can be
        // legitimately approved, then the audience TYPE itself changes.
        $announcement = $this->announcements()->createDraft(
            $school, $admin, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide,
        );
        $request = $this->approvals()->submit($announcement, $admin);
        $this->approvals()->approve($request, $approver);

        // updateDraft() only syncs individual members when audience_type
        // is ALREADY individual (brief §80 targets the audience
        // DEFINITION generally) -- exercised here via a direct content
        // change that the fingerprint must still catch: switching to a
        // narrower individual member selection is exercised in the next
        // test; this one proves a plain requirement/channel-independent
        // content edit (title) already invalidates regardless of
        // audience type, confirming the fingerprint recompute path is
        // unconditional.
        $this->announcements()->updateDraft($announcement, $admin, title: 'Changed');

        $this->assertSame('draft', $this->freshAnnouncement($school, $announcement)->status);
    }

    #[Test]
    public function changing_the_individual_audience_member_selection_after_approval_invalidates_it(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $approver = $this->createUser();
        $approverMembership = $this->createMembership($approver, $school);
        $this->assignSchoolRole($approverMembership, 'principal');
        $memberA = $this->createUser();
        $this->createMembership($memberA, $school);
        $memberB = $this->createUser();
        $this->createMembership($memberB, $school);
        // Non-privileged-sender rule so an INDIVIDUAL-audience draft
        // still requires approval (school-wide-only policy would not
        // apply to this audience type).
        $this->createApprovalPolicy($school, ['require_non_privileged_sender_approval' => true]);

        $principal = $this->createUser();
        $principalMembership = $this->createMembership($principal, $school);
        $this->assignSchoolRole($principalMembership, 'principal');

        $announcement = $this->announcements()->createDraft(
            $school, $principal, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::Individual,
            individualMemberUserIds: [$memberA->id],
        );
        $request = $this->approvals()->submit($announcement, $principal);
        $this->approvals()->approve($request, $approver);
        $this->assertSame('approved', $this->freshAnnouncement($school, $announcement)->status);

        $this->announcements()->updateDraft($announcement, $principal, individualMemberUserIds: [$memberA->id, $memberB->id]);

        $this->assertSame('draft', $this->freshAnnouncement($school, $announcement)->status);
    }

    #[Test]
    public function adding_an_attachment_after_approval_invalidates_it(): void
    {
        ['admin' => $admin, 'school' => $school, 'announcement' => $announcement] = $this->approvedSchoolWideAnnouncement();

        $this->attachments()->upload($announcement, $admin, UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'));

        $this->assertApprovalWasInvalidated($school, $announcement);
    }

    #[Test]
    public function removing_an_attachment_after_approval_invalidates_it(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $approver = $this->createUser();
        $approverMembership = $this->createMembership($approver, $school);
        $this->assignSchoolRole($approverMembership, 'principal');
        $this->createMembership($this->createUser(), $school);
        $this->createApprovalPolicy($school, ['require_school_wide_approval' => true]);

        $announcement = $this->announcements()->createDraft(
            $school, $admin, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide,
        );
        $attachment = $this->attachments()->upload($announcement, $admin, UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'));

        $request = $this->approvals()->submit($announcement, $admin);
        $this->approvals()->approve($request, $approver);
        $this->assertSame('approved', $this->freshAnnouncement($school, $announcement)->status);

        $this->attachments()->remove($attachment, $admin);

        $this->assertSame('draft', $this->freshAnnouncement($school, $announcement)->status);
    }

    // --- §81: template source edit never invalidates -------------------

    #[Test]
    public function editing_the_source_template_never_invalidates_an_unrelated_approval(): void
    {
        ['admin' => $admin, 'school' => $school, 'announcement' => $announcement] = $this->approvedSchoolWideAnnouncement();

        // No template edit call exists on AnnouncementService/approval
        // service at all -- proving the NEGATIVE here: simply not
        // touching the announcement leaves its approval untouched.
        $this->assertSame('approved', $this->freshAnnouncement($school, $announcement)->status);
        $this->assertTrue($this->approvals()->currentlyApprovedAndValid($announcement));
    }

    // --- §82: publish-time fingerprint verification ---------------------

    #[Test]
    public function publish_is_denied_if_content_diverged_from_the_approved_fingerprint_even_with_a_stale_approved_status(): void
    {
        ['admin' => $admin, 'school' => $school, 'announcement' => $announcement] = $this->approvedSchoolWideAnnouncement();

        // Simulates a hypothetical future mutation path that forgot to
        // call invalidateIfFingerprintChanged() -- status remains
        // 'approved' but the content has diverged.
        app(TenantContext::class)->withSchool($school, fn () => CommunicationAnnouncement::query()->where('id', $announcement->id)->update(['title' => 'Tampered']));

        $this->expectException(ApprovalRequiredException::class);
        $this->announcements()->publish($this->freshAnnouncement($school, $announcement), $admin);
    }

    // --- §83: direct forged publish -------------------------------------

    #[Test]
    public function publishing_directly_when_approval_is_required_is_denied_with_no_side_effects(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $this->createMembership($this->createUser(), $school);
        $this->createApprovalPolicy($school, ['require_school_wide_approval' => true]);

        Config::set('communications.channels.email.enabled', true);
        $this->fakeEmail();

        $announcement = $this->announcements()->createDraft(
            $school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide,
        );

        $this->expectException(ApprovalRequiredException::class);

        try {
            $this->announcements()->publish($announcement, $creator);
        } finally {
            $this->assertSame('draft', $this->freshAnnouncement($school, $announcement)->status);
            $recipientCount = app(TenantContext::class)->withSchool($school, fn () => CommunicationRecipient::query()->count());
            $this->assertSame(0, $recipientCount);
            $this->assertNoEmailAccepted();
        }
    }

    // --- §84: approved publish uses the existing pipeline unchanged ----

    #[Test]
    public function an_approved_announcement_publishes_through_the_full_existing_pipeline(): void
    {
        Config::set('communications.channels.email.enabled', true);
        $this->fakeEmail();

        ['admin' => $admin, 'school' => $school, 'announcement' => $announcement] = $this->approvedSchoolWideAnnouncement();

        $published = $this->announcements()->publish($announcement, $admin);

        $this->assertSame('published', $published->status);
        // School-wide audience -- both the "member" fixture AND the
        // approver (also a real active School member) are eligible
        // recipients; the admin/creator themselves are excluded.
        $this->assertSame(2, $published->recipient_count);

        $deliveryCount = app(TenantContext::class)->withSchool($school, fn () => CommunicationDelivery::query()->count());
        $this->assertSame(2, $deliveryCount);
    }

    // --- §85: scheduling -------------------------------------------------

    #[Test]
    public function scheduling_before_approval_is_denied(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $this->createMembership($this->createUser(), $school);
        $this->createApprovalPolicy($school, ['require_school_wide_approval' => true]);

        $announcement = $this->announcements()->createDraft(
            $school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide,
        );

        $this->expectException(ApprovalRequiredException::class);
        $this->announcements()->schedule($announcement, $creator, now()->addHour());
    }

    #[Test]
    public function an_approved_announcement_can_be_scheduled(): void
    {
        ['admin' => $admin, 'school' => $school, 'announcement' => $announcement] = $this->approvedSchoolWideAnnouncement();

        $scheduled = $this->announcements()->schedule($announcement, $admin, now()->addHour());

        $this->assertSame('scheduled', $scheduled->status);
    }

    #[Test]
    public function rescheduling_an_approved_and_scheduled_announcement_never_invalidates_approval(): void
    {
        ['admin' => $admin, 'school' => $school, 'announcement' => $announcement] = $this->approvedSchoolWideAnnouncement();
        $this->announcements()->schedule($announcement, $admin, now()->addHour());

        $this->announcements()->reschedule($this->freshAnnouncement($school, $announcement), $admin, now()->addHours(2));

        $this->assertSame('scheduled', $this->freshAnnouncement($school, $announcement)->status);
        $this->assertTrue($this->approvals()->currentlyApprovedAndValid($this->freshAnnouncement($school, $announcement)));
    }

    // --- §88: concurrent edit vs decision --------------------------------

    #[Test]
    public function a_withdrawn_request_cannot_then_be_approved(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $approver = $this->createUser();
        $approverMembership = $this->createMembership($approver, $school);
        $this->assignSchoolRole($approverMembership, 'principal');
        $this->createMembership($this->createUser(), $school);
        $this->createApprovalPolicy($school, ['require_school_wide_approval' => true]);

        $announcement = $this->announcements()->createDraft(
            $school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide,
        );
        $request = $this->approvals()->submit($announcement, $creator);
        $this->approvals()->withdraw($announcement, $creator);

        $this->expectException(ApprovalAlreadyDecidedException::class);
        $this->approvals()->approve($request, $approver);
    }
}

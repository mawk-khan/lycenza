<?php

namespace Tests\Feature\Communications;

use App\Domain\Communications\Application\AnnouncementService;
use App\Domain\Communications\Application\Approval\CommunicationApprovalService;
use App\Domain\Communications\Application\Policy\CommunicationConsentService;
use App\Domain\Communications\Application\Policy\CommunicationDomainPreferenceService;
use App\Domain\Communications\Application\Policy\SchoolChannelPolicyService;
use App\Domain\Communications\Domain\CommunicationAudienceType;
use App\Domain\Communications\Domain\CommunicationChannel;
use App\Domain\Communications\Domain\CommunicationDispatchMode;
use App\Domain\Communications\Domain\CommunicationPriority;
use App\Domain\Communications\Domain\CommunicationRequirement;
use App\Domain\Communications\Infrastructure\CommunicationAnnouncementRecipient;
use App\Domain\Communications\Infrastructure\CommunicationDelivery;
use App\Domain\Communications\Infrastructure\CommunicationDeliveryPolicyDecision;
use App\Domain\Communications\Infrastructure\CommunicationRecipient;
use App\Domain\Guardians\Infrastructure\ContactType;
use App\Domain\Guardians\Infrastructure\Guardian;
use App\Domain\Identity\Application\AccountLinkService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCommunicationFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Concerns\FakesEmail;
use Tests\TestCase;

/**
 * Phase 5D.2 §16-§25/§49-§62/§66 -- extends the real
 * AnnouncementService::publish() Guardian-EMAIL delivery path with
 * domain preference/consent, exercised end-to-end (never a mocked
 * decision engine). Mirrors
 * tests/Feature/Communications/StudentGuardianAccountLinkInAppTest.php's
 * established convention.
 */
class GuardianEmailPreferenceConsentDeliveryTest extends TestCase
{
    use CreatesCommunicationFixtures, CreatesTenancyFixtures, FakesEmail;

    protected function setUp(): void
    {
        parent::setUp();

        // Matches StudentGuardianAudienceServiceTest's established
        // convention for exercising the REAL email send path in tests.
        Config::set('communications.channels.email.enabled', true);
        $this->fakeEmail();
    }

    private function announcements(): AnnouncementService
    {
        return app(AnnouncementService::class);
    }

    private function preferences(): CommunicationDomainPreferenceService
    {
        return app(CommunicationDomainPreferenceService::class);
    }

    private function consents(): CommunicationConsentService
    {
        return app(CommunicationConsentService::class);
    }

    private function deliveriesFor($school, ?string $messageId)
    {
        return app(TenantContext::class)->withSchool($school, fn () => CommunicationDelivery::query()
            ->whereIn('recipient_id', CommunicationRecipient::query()->where('message_id', $messageId)->pluck('id'))
            ->get());
    }

    private function reasonFor($school, ?string $messageId, string $guardianId, string $channel): ?string
    {
        return app(TenantContext::class)->withSchool($school, fn () => CommunicationDeliveryPolicyDecision::query()
            ->where('message_id', $messageId)
            ->where('recipient_guardian_id', $guardianId)
            ->where('channel', $channel)
            ->value('reason'));
    }

    private function guardianWithEmail($school): Guardian
    {
        $guardian = $this->createGuardian($school);
        $this->createGuardianContact($guardian, ContactType::Email, 'parent@example.com', ['is_primary' => true]);

        return $guardian;
    }

    private function publishOptionalEmailAnnouncement($school, $creator, $guardian, CommunicationRequirement $requirement = CommunicationRequirement::Optional)
    {
        $announcement = $this->announcements()->createDraft(
            $school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::Guardian,
            domainAudienceMemberIds: [$guardian->id], channels: [CommunicationChannel::Email],
            requirement: $requirement,
        );

        return $this->announcements()->publish($announcement, $creator);
    }

    // --- §49: default backward compatibility --------------------------------

    #[Test]
    public function default_backward_compatibility_no_preference_or_consent_row_still_delivers(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $guardian = $this->guardianWithEmail($school);

        $published = $this->publishOptionalEmailAnnouncement($school, $creator, $guardian);

        $deliveries = $this->deliveriesFor($school, $published->message_id);
        $this->assertCount(1, $deliveries, 'Introducing 5D.2 must not suppress an existing Guardian with no explicit preference/consent row.');
    }

    // --- §50: explicit opt-out -----------------------------------------------

    #[Test]
    public function explicit_preference_opt_out_suppresses_optional_email_with_preference_reason(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $guardian = $this->guardianWithEmail($school);
        $this->preferences()->setPreferenceForGuardian($school, $guardian, $creator, CommunicationChannel::Email, false);

        $published = $this->publishOptionalEmailAnnouncement($school, $creator, $guardian);

        $this->assertCount(0, $this->deliveriesFor($school, $published->message_id));
        $this->assertSame('recipient_preference_disabled', $this->reasonFor($school, $published->message_id, $guardian->id, 'email'));

        // Logical recipient snapshot must remain (brief §25).
        $snapshotExists = app(TenantContext::class)->withSchool($school, fn () => CommunicationAnnouncementRecipient::query()
            ->where('announcement_id', $published->id)->where('guardian_id', $guardian->id)->exists());
        $this->assertTrue($snapshotExists);
    }

    // --- §51: explicit opt-in -------------------------------------------------

    #[Test]
    public function explicit_preference_opt_in_delivers_normally(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $guardian = $this->guardianWithEmail($school);
        $this->preferences()->setPreferenceForGuardian($school, $guardian, $creator, CommunicationChannel::Email, true);

        $published = $this->publishOptionalEmailAnnouncement($school, $creator, $guardian);

        $this->assertCount(1, $this->deliveriesFor($school, $published->message_id));
    }

    // --- §52: consent withdrawal ------------------------------------------------

    #[Test]
    public function consent_withdrawal_suppresses_optional_email_with_consent_reason(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $guardian = $this->guardianWithEmail($school);
        $this->consents()->recordWithdrawalForGuardian($school, $guardian, $creator, CommunicationChannel::Email);

        $published = $this->publishOptionalEmailAnnouncement($school, $creator, $guardian);

        $this->assertCount(0, $this->deliveriesFor($school, $published->message_id));
        $this->assertSame('consent_withdrawn', $this->reasonFor($school, $published->message_id, $guardian->id, 'email'));
    }

    #[Test]
    public function consent_withdrawal_is_never_classified_as_a_send_failure(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $guardian = $this->guardianWithEmail($school);
        $this->consents()->recordWithdrawalForGuardian($school, $guardian, $creator, CommunicationChannel::Email);

        $published = $this->publishOptionalEmailAnnouncement($school, $creator, $guardian);

        // No delivery/attempt row is ever created for a policy suppression --
        // it never reaches the delivery/attempt layer at all.
        $this->assertCount(0, $this->deliveriesFor($school, $published->message_id));
    }

    // --- §54: REQUIRED communication -----------------------------------------

    #[Test]
    public function required_communication_bypasses_a_preference_opt_out(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $guardian = $this->guardianWithEmail($school);
        $this->preferences()->setPreferenceForGuardian($school, $guardian, $creator, CommunicationChannel::Email, false);

        $published = $this->publishOptionalEmailAnnouncement($school, $creator, $guardian, CommunicationRequirement::Required);

        $this->assertCount(1, $this->deliveriesFor($school, $published->message_id), 'REQUIRED must bypass an ordinary preference opt-out, exactly like it already bypasses a SchoolMembership preference.');
    }

    #[Test]
    public function required_communication_bypasses_a_withdrawn_consent(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $guardian = $this->guardianWithEmail($school);
        $this->consents()->recordWithdrawalForGuardian($school, $guardian, $creator, CommunicationChannel::Email);

        $published = $this->publishOptionalEmailAnnouncement($school, $creator, $guardian, CommunicationRequirement::Required);

        $this->assertCount(1, $this->deliveriesFor($school, $published->message_id), 'This foundation applies the SAME REQUIRED-bypass rule to consent as to preference -- documented, not a legal claim.');
    }

    // --- §55: Emergency -------------------------------------------------------

    #[Test]
    public function emergency_communication_is_unaffected_by_guardian_preference(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $guardian = $this->guardianWithEmail($school);
        $this->preferences()->setPreferenceForGuardian($school, $guardian, $creator, CommunicationChannel::Email, false);

        $announcement = $this->announcements()->createDraft(
            $school, $creator, 'Fire drill', 'Evacuate now', CommunicationPriority::Critical, CommunicationAudienceType::Guardian,
            domainAudienceMemberIds: [$guardian->id], channels: [CommunicationChannel::Email],
            requirement: CommunicationRequirement::Required,
            dispatchMode: CommunicationDispatchMode::Emergency,
            emergencyJustification: 'Active fire drill',
        );
        $published = $this->announcements()->publish($announcement, $creator);

        $this->assertCount(1, $this->deliveriesFor($school, $published->message_id));
    }

    // --- §56: channel policy remains authoritative --------------------------

    #[Test]
    public function school_channel_policy_disabled_is_reported_over_preference(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        app(SchoolChannelPolicyService::class)->setPolicy($school, $creator, CommunicationChannel::Email, optionalAllowed: false, requiredAllowed: true, recipientCanOptOut: true);
        $guardian = $this->guardianWithEmail($school);
        $this->preferences()->setPreferenceForGuardian($school, $guardian, $creator, CommunicationChannel::Email, true);

        $published = $this->publishOptionalEmailAnnouncement($school, $creator, $guardian);

        $this->assertCount(0, $this->deliveriesFor($school, $published->message_id));
        $this->assertSame('school_optional_channel_disabled', $this->reasonFor($school, $published->message_id, $guardian->id, 'email'));
    }

    // --- §57: endpoint unavailable -------------------------------------------

    #[Test]
    public function opted_in_guardian_with_no_email_endpoint_is_reported_as_destination_unavailable(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $guardian = $this->createGuardian($school); // no contact at all
        $this->preferences()->setPreferenceForGuardian($school, $guardian, $creator, CommunicationChannel::Email, true);
        $this->consents()->recordGrantForGuardian($school, $guardian, $creator, CommunicationChannel::Email);

        $published = $this->publishOptionalEmailAnnouncement($school, $creator, $guardian);

        $this->assertCount(0, $this->deliveriesFor($school, $published->message_id));
        $this->assertSame('recipient_destination_unavailable', $this->reasonFor($school, $published->message_id, $guardian->id, 'email'));
    }

    // --- §58: IN_APP is not double-gated --------------------------------------

    #[Test]
    public function in_app_eligibility_follows_the_linked_membership_preference_unaffected_by_guardian_email_preference(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $member = $this->createUser();
        $membership = $this->createMembership($member, $school);
        $this->assignSchoolRole($membership, 'principal');
        $guardian = $this->guardianWithEmail($school);
        app(AccountLinkService::class)->linkGuardian($school, $guardian, $membership, $creator);

        // Guardian-domain EMAIL opted out -- must never affect IN_APP.
        $this->preferences()->setPreferenceForGuardian($school, $guardian, $creator, CommunicationChannel::Email, false);

        $announcement = $this->announcements()->createDraft(
            $school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::Guardian,
            domainAudienceMemberIds: [$guardian->id], channels: [CommunicationChannel::InApp],
        );
        $published = $this->announcements()->publish($announcement, $creator);

        $deliveries = $this->deliveriesFor($school, $published->message_id);
        $this->assertCount(1, $deliveries, 'IN_APP must follow the SchoolMembership preference, never the Guardian-domain EMAIL preference.');
    }

    // --- §59: scheduled announcements ------------------------------------------

    #[Test]
    public function a_preference_change_after_scheduling_but_before_publication_is_applied(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $guardian = $this->guardianWithEmail($school);

        $announcement = $this->announcements()->createDraft(
            $school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::Guardian,
            domainAudienceMemberIds: [$guardian->id], channels: [CommunicationChannel::Email],
        );

        // Day 2: Guardian withdraws preference before the scheduled due time.
        $this->preferences()->setPreferenceForGuardian($school, $guardian, $creator, CommunicationChannel::Email, false);

        // Day 3: publish() runs exactly as PublishScheduledAnnouncements would at due time.
        $published = $this->announcements()->publish($announcement, $creator);

        $this->assertCount(0, $this->deliveriesFor($school, $published->message_id), 'Publication-time state must be applied, never a draft-time snapshot.');
    }

    // --- §60: historical immutability -----------------------------------------

    #[Test]
    public function a_later_preference_change_never_rewrites_a_historical_delivery(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $guardian = $this->guardianWithEmail($school);

        $publishedA = $this->publishOptionalEmailAnnouncement($school, $creator, $guardian);
        $this->assertCount(1, $this->deliveriesFor($school, $publishedA->message_id));

        $this->preferences()->setPreferenceForGuardian($school, $guardian, $creator, CommunicationChannel::Email, false);

        $deliveriesAfter = $this->deliveriesFor($school, $publishedA->message_id);
        $this->assertCount(1, $deliveriesAfter, "Announcement A's historical delivery must remain untouched.");
        $this->assertSame('sent', $deliveriesAfter->first()->status);

        // A future Announcement B respects the new state.
        $publishedB = $this->publishOptionalEmailAnnouncement($school, $creator, $guardian);
        $this->assertCount(0, $this->deliveriesFor($school, $publishedB->message_id));
    }

    // --- §61: approval ------------------------------------------------------------

    #[Test]
    public function an_approved_announcement_remains_valid_after_a_preference_change(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $approver = $this->createUser();
        $approverMembership = $this->createMembership($approver, $school);
        $this->assignSchoolRole($approverMembership, 'principal');
        $this->createApprovalPolicy($school, ['require_required_communication_approval' => true]);
        $guardian = $this->guardianWithEmail($school);

        $announcement = $this->announcements()->createDraft(
            $school, $admin, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::Guardian,
            domainAudienceMemberIds: [$guardian->id], channels: [CommunicationChannel::Email],
            requirement: CommunicationRequirement::Required,
        );
        $approvalService = app(CommunicationApprovalService::class);
        $request = $approvalService->submit($announcement, $admin);
        $approvalService->approve($request, $approver);

        $this->preferences()->setPreferenceForGuardian($school, $guardian, $admin, CommunicationChannel::Email, false);
        $this->consents()->recordWithdrawalForGuardian($school, $guardian, $admin, CommunicationChannel::Email);

        $fresh = app(TenantContext::class)->withSchool($school, fn () => $announcement->fresh());
        $this->assertSame('approved', $fresh->status, 'Preference/consent state is not part of the approval fingerprint.');
    }

    // --- §66: performance --------------------------------------------------------

    #[Test]
    public function publishing_optional_email_to_many_guardians_uses_a_bounded_query_count(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $guardianIds = [];
        for ($i = 0; $i < 20; $i++) {
            $guardian = $this->guardianWithEmail($school);
            if ($i % 2 === 0) {
                $this->preferences()->setPreferenceForGuardian($school, $guardian, $creator, CommunicationChannel::Email, false);
            }
            if ($i % 3 === 0) {
                $this->consents()->recordWithdrawalForGuardian($school, $guardian, $creator, CommunicationChannel::Email);
            }
            $guardianIds[] = $guardian->id;
        }

        $announcement = $this->announcements()->createDraft(
            $school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::Guardian,
            domainAudienceMemberIds: $guardianIds, channels: [CommunicationChannel::Email],
        );

        DB::enableQueryLog();
        $published = $this->announcements()->publish($announcement, $creator);
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $deliveredCount = $this->deliveriesFor($school, $published->message_id)->count();
        $this->assertGreaterThan(0, $deliveredCount);
        // Generous bound -- createRecipient()/createDelivery() each wrap
        // their write in its own DB::transaction() (a SAVEPOINT, counted
        // as extra queries), the same per-recipient shape
        // StudentGuardianAccountLinkInAppTest's identical bound already
        // documents. This bound only guards against a REGRESSION back to
        // one query per Guardian for the preference/consent lookups
        // themselves (which would add ~40 extra queries on top of this
        // baseline).
        $this->assertLessThan(350, $queryCount);
    }
}

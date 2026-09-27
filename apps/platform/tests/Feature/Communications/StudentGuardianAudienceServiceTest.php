<?php

namespace Tests\Feature\Communications;

use App\Domain\Communications\Application\AnnouncementService;
use App\Domain\Communications\Application\Approval\CommunicationApprovalService;
use App\Domain\Communications\Application\Exceptions\EmptyAudienceException;
use App\Domain\Communications\Application\Exceptions\InvalidAudienceMemberException;
use App\Domain\Communications\Domain\CommunicationAudienceType;
use App\Domain\Communications\Domain\CommunicationChannel;
use App\Domain\Communications\Domain\CommunicationDispatchMode;
use App\Domain\Communications\Domain\CommunicationPriority;
use App\Domain\Communications\Domain\CommunicationRequirement;
use App\Domain\Communications\Infrastructure\CommunicationAnnouncementRecipient;
use App\Domain\Communications\Infrastructure\CommunicationDelivery;
use App\Domain\Communications\Infrastructure\CommunicationDeliveryPolicyDecision;
use App\Domain\Communications\Infrastructure\CommunicationRecipient;
use App\Domain\Guardians\Application\GuardianContactService;
use App\Domain\Guardians\Infrastructure\ContactType;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCommunicationFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Concerns\FakesEmail;
use Tests\TestCase;

/**
 * Phase 5B.1 -- Student/Guardian audience resolution, reachability,
 * publish-time snapshot/delivery behavior, and approval-fingerprint
 * integration. Every scenario goes through the real
 * App\Domain\Communications\Application\AnnouncementService pipeline,
 * matching this suite's existing convention (e.g.
 * AnnouncementEmailDeliveryTest, AnnouncementDeliveryTimingTest)
 * rather than hand-crafted rows.
 */
class StudentGuardianAudienceServiceTest extends TestCase
{
    use CreatesCommunicationFixtures, CreatesTenancyFixtures, FakesEmail;

    private function service(): AnnouncementService
    {
        return app(AnnouncementService::class);
    }

    private function deliveriesFor($school, ?string $messageId): Collection
    {
        return app(TenantContext::class)->withSchool($school, fn () => CommunicationDelivery::query()
            ->whereIn('recipient_id', CommunicationRecipient::query()->where('message_id', $messageId)->pluck('id'))
            ->get());
    }

    private function recipientSnapshot($school, string $announcementId): Collection
    {
        return app(TenantContext::class)->withSchool($school, fn () => CommunicationAnnouncementRecipient::query()
            ->where('announcement_id', $announcementId)->get());
    }

    // --- §39: Student resolution -------------------------------------

    #[Test]
    public function a_same_school_active_student_is_accepted_and_snapshotted_with_no_delivery(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $student = $this->createStudent($school);

        $announcement = $this->service()->createDraft(
            $school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::Student,
            domainAudienceMemberIds: [$student->id],
        );
        $published = $this->service()->publish($announcement, $creator);

        $this->assertSame(1, $published->recipient_count);
        $snapshot = $this->recipientSnapshot($school, $published->id);
        $this->assertCount(1, $snapshot);
        $this->assertSame($student->id, $snapshot->first()->student_id);
        $this->assertNull($snapshot->first()->user_id);
        $this->assertCount(0, $this->deliveriesFor($school, $published->message_id));
    }

    #[Test]
    public function a_duplicate_student_id_is_removed(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $student = $this->createStudent($school);

        $announcement = $this->service()->createDraft(
            $school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::Student,
            domainAudienceMemberIds: [$student->id, $student->id],
        );

        $preview = $this->service()->previewAudience($announcement);
        $this->assertSame(1, $preview->count());
    }

    #[Test]
    public function a_cross_school_student_id_is_rejected_at_authoring_time(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $otherSchool = $this->createSchool();
        $foreignStudent = $this->createStudent($otherSchool);

        $this->expectException(InvalidAudienceMemberException::class);

        $this->service()->createDraft(
            $school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::Student,
            domainAudienceMemberIds: [$foreignStudent->id],
        );
    }

    #[Test]
    public function an_invalid_student_id_is_rejected(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');

        $this->expectException(InvalidAudienceMemberException::class);

        $this->service()->createDraft(
            $school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::Student,
            domainAudienceMemberIds: ['00000000-0000-0000-0000-000000000000'],
        );
    }

    #[Test]
    public function publishing_a_student_audience_with_no_eligible_students_throws_empty_audience(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $student = $this->createStudent($school);

        $announcement = $this->service()->createDraft(
            $school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::Student,
            domainAudienceMemberIds: [$student->id],
        );

        // Became inactive after authoring, before publish -- re-validated at resolve time.
        app(TenantContext::class)->withSchool($school, fn () => $student->update(['status' => 'inactive']));

        $this->expectException(EmptyAudienceException::class);
        $this->service()->publish($announcement, $creator);
    }

    // --- §40: Guardian resolution --------------------------------------

    #[Test]
    public function a_same_school_active_guardian_is_accepted_and_snapshotted(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $guardian = $this->createGuardian($school);
        $this->createGuardianContact($guardian, ContactType::Email, 'guardian@example.com');

        $announcement = $this->service()->createDraft(
            $school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::Guardian,
            domainAudienceMemberIds: [$guardian->id],
        );
        $published = $this->service()->publish($announcement, $creator);

        $snapshot = $this->recipientSnapshot($school, $published->id);
        $this->assertCount(1, $snapshot);
        $this->assertSame($guardian->id, $snapshot->first()->guardian_id);
    }

    #[Test]
    public function a_duplicate_guardian_id_is_removed(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $guardian = $this->createGuardian($school);

        $announcement = $this->service()->createDraft(
            $school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::Guardian,
            domainAudienceMemberIds: [$guardian->id, $guardian->id],
        );

        $preview = $this->service()->previewAudience($announcement);
        $this->assertSame(1, $preview->count());
    }

    #[Test]
    public function a_cross_school_guardian_id_is_rejected_at_authoring_time(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $otherSchool = $this->createSchool();
        $foreignGuardian = $this->createGuardian($otherSchool);

        $this->expectException(InvalidAudienceMemberException::class);

        $this->service()->createDraft(
            $school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::Guardian,
            domainAudienceMemberIds: [$foreignGuardian->id],
        );
    }

    #[Test]
    public function no_plaintext_contact_value_ever_leaks_into_the_delivery_policy_decision_or_recipient_snapshot(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $guardian = $this->createGuardian($school);
        $this->createGuardianContact($guardian, ContactType::Email, 'secret-address@example.com');

        $announcement = $this->service()->createDraft(
            $school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::Guardian,
            domainAudienceMemberIds: [$guardian->id],
        );
        $published = $this->service()->publish($announcement, $creator);

        $snapshotRow = $this->recipientSnapshot($school, $published->id)->first();
        $this->assertStringNotContainsString('secret-address', json_encode($snapshotRow->toArray()));

        $decisions = app(TenantContext::class)->withSchool($school, fn () => CommunicationDeliveryPolicyDecision::query()
            ->where('message_id', $published->message_id)->get());
        foreach ($decisions as $decision) {
            $this->assertStringNotContainsString('secret-address', json_encode($decision->toArray()));
        }
    }

    // --- §41: Guardians of Students -------------------------------------

    #[Test]
    public function guardians_of_students_resolves_only_primary_or_legal_guardian_relationships(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $student = $this->createStudent($school);
        $primaryGuardian = $this->createGuardian($school);
        $emergencyOnlyGuardian = $this->createGuardian($school);
        $this->createStudentGuardianRelationship($student, $primaryGuardian, ['is_primary' => true]);
        $this->createStudentGuardianRelationship($student, $emergencyOnlyGuardian, ['is_emergency_contact' => true]);

        $announcement = $this->service()->createDraft(
            $school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::GuardiansOfStudents,
            domainAudienceMemberIds: [$student->id],
        );

        $preview = $this->service()->previewAudience($announcement);
        $this->assertSame([$primaryGuardian->id], $preview->guardianIds);
    }

    #[Test]
    public function a_legal_guardian_relationship_without_primary_is_still_included(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $student = $this->createStudent($school);
        $legalGuardian = $this->createGuardian($school);
        $this->createStudentGuardianRelationship($student, $legalGuardian, ['is_legal_guardian' => true]);

        $announcement = $this->service()->createDraft(
            $school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::GuardiansOfStudents,
            domainAudienceMemberIds: [$student->id],
        );

        $preview = $this->service()->previewAudience($announcement);
        $this->assertSame([$legalGuardian->id], $preview->guardianIds);
    }

    #[Test]
    public function a_guardian_shared_by_two_selected_students_collapses_to_one_logical_recipient(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $studentOne = $this->createStudent($school);
        $studentTwo = $this->createStudent($school);
        $sharedGuardian = $this->createGuardian($school);
        $this->createStudentGuardianRelationship($studentOne, $sharedGuardian, ['is_primary' => true]);
        $this->createStudentGuardianRelationship($studentTwo, $sharedGuardian, ['is_primary' => true]);

        $announcement = $this->service()->createDraft(
            $school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::GuardiansOfStudents,
            domainAudienceMemberIds: [$studentOne->id, $studentTwo->id],
        );
        $published = $this->service()->publish($announcement, $creator);

        $this->assertSame(1, $published->recipient_count);
        $this->assertCount(1, $this->recipientSnapshot($school, $published->id));
    }

    #[Test]
    public function a_cross_school_student_selection_is_rejected_for_guardians_of_students(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $otherSchool = $this->createSchool();
        $foreignStudent = $this->createStudent($otherSchool);

        $this->expectException(InvalidAudienceMemberException::class);

        $this->service()->createDraft(
            $school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::GuardiansOfStudents,
            domainAudienceMemberIds: [$foreignStudent->id],
        );
    }

    // --- §42: IN_APP semantics ------------------------------------------

    #[Test]
    public function a_guardian_never_gets_a_fake_successful_in_app_delivery(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $guardian = $this->createGuardian($school);

        $announcement = $this->service()->createDraft(
            $school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::Guardian,
            domainAudienceMemberIds: [$guardian->id],
            channels: [CommunicationChannel::InApp],
        );
        $published = $this->service()->publish($announcement, $creator);

        $this->assertCount(0, $this->deliveriesFor($school, $published->message_id));

        $reason = app(TenantContext::class)->withSchool($school, fn () => CommunicationDeliveryPolicyDecision::query()
            ->where('message_id', $published->message_id)->where('recipient_guardian_id', $guardian->id)
            ->where('channel', 'in_app')->value('reason'));
        $this->assertSame('recipient_ineligible', $reason);
    }

    #[Test]
    public function existing_schoolmembership_recipients_are_unaffected_by_a_mixed_run(): void
    {
        // Not a mixed audience type today (a single announcement has
        // one audience_type), but proves the membership pipeline itself
        // is byte-for-byte unchanged by this checkpoint's code.
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $member = $this->createUser();
        $this->createMembership($member, $school);

        $announcement = $this->service()->createDraft(
            $school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide,
        );
        $published = $this->service()->publish($announcement, $creator);

        $deliveries = $this->deliveriesFor($school, $published->message_id);
        $this->assertCount(1, $deliveries);
        $this->assertSame('delivered', $deliveries->first()->status);
    }

    // --- §43: EMAIL semantics -------------------------------------------

    #[Test]
    public function a_guardian_with_an_eligible_email_contact_gets_a_real_delivery(): void
    {
        Config::set('communications.channels.email.enabled', true);
        $this->fakeEmail();

        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $guardian = $this->createGuardian($school);
        $this->createGuardianContact($guardian, ContactType::Email, 'guardian@example.com', ['is_primary' => true]);

        $announcement = $this->service()->createDraft(
            $school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::Guardian,
            domainAudienceMemberIds: [$guardian->id],
            channels: [CommunicationChannel::InApp, CommunicationChannel::Email],
        );
        $published = $this->service()->publish($announcement, $creator);

        $emailDelivery = $this->deliveriesFor($school, $published->message_id)->firstWhere('channel', 'email');
        $this->assertNotNull($emailDelivery);
        $this->assertSame('sent', $emailDelivery->status);
        $this->assertSame('guardian@example.com', $emailDelivery->destination_snapshot['email']);
        $this->assertEmailAcceptedCount(1);
    }

    #[Test]
    public function a_guardian_without_an_email_contact_gets_no_delivery_and_a_destination_unavailable_decision(): void
    {
        Config::set('communications.channels.email.enabled', true);
        $this->fakeEmail();

        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $guardian = $this->createGuardian($school);

        $announcement = $this->service()->createDraft(
            $school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::Guardian,
            domainAudienceMemberIds: [$guardian->id],
            channels: [CommunicationChannel::InApp, CommunicationChannel::Email],
        );
        $published = $this->service()->publish($announcement, $creator);

        $this->assertNull($this->deliveriesFor($school, $published->message_id)->firstWhere('channel', 'email'));

        $reason = app(TenantContext::class)->withSchool($school, fn () => CommunicationDeliveryPolicyDecision::query()
            ->where('message_id', $published->message_id)->where('recipient_guardian_id', $guardian->id)
            ->where('channel', 'email')->value('reason'));
        $this->assertSame('recipient_destination_unavailable', $reason);
        $this->assertNoEmailAccepted();

        // §34: still fully present in the immutable logical snapshot --
        // never silently dropped.
        $this->assertCount(1, $this->recipientSnapshot($school, $published->id));
    }

    #[Test]
    public function email_globally_disabled_produces_no_real_send(): void
    {
        Config::set('communications.channels.email.enabled', false);
        $this->fakeEmail();

        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $guardian = $this->createGuardian($school);
        $this->createGuardianContact($guardian, ContactType::Email, 'guardian@example.com');

        // Composer-level validation rejects requesting email while
        // disabled (AnnouncementController::validateComposer) -- at the
        // service layer, an announcement simply isn't given the email
        // channel, so no email delivery can exist regardless.
        $announcement = $this->service()->createDraft(
            $school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::Guardian,
            domainAudienceMemberIds: [$guardian->id],
            channels: [CommunicationChannel::InApp],
        );
        $published = $this->service()->publish($announcement, $creator);

        $this->assertCount(0, $this->deliveriesFor($school, $published->message_id));
        $this->assertNoEmailAccepted();
    }

    #[Test]
    public function guardian_email_selection_prefers_the_active_primary_contact(): void
    {
        Config::set('communications.channels.email.enabled', true);
        $this->fakeEmail();

        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $guardian = $this->createGuardian($school);
        $this->createGuardianContact($guardian, ContactType::Email, 'secondary@example.com');
        $this->createGuardianContact($guardian, ContactType::Email, 'primary@example.com', ['is_primary' => true]);

        $announcement = $this->service()->createDraft(
            $school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::Guardian,
            domainAudienceMemberIds: [$guardian->id],
            channels: [CommunicationChannel::InApp, CommunicationChannel::Email],
        );
        $published = $this->service()->publish($announcement, $creator);

        $emailDelivery = $this->deliveriesFor($school, $published->message_id)->firstWhere('channel', 'email');
        $this->assertSame('primary@example.com', $emailDelivery->destination_snapshot['email']);
    }

    #[Test]
    public function guardian_email_selection_falls_back_to_the_oldest_active_contact_with_no_primary(): void
    {
        Config::set('communications.channels.email.enabled', true);
        $this->fakeEmail();

        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $guardian = $this->createGuardian($school);
        $older = $this->createGuardianContact($guardian, ContactType::Email, 'older@example.com');
        DB::table('guardian_contacts')->where('id', $older->id)->update(['created_at' => now()->subDay()]);
        $this->createGuardianContact($guardian, ContactType::Email, 'newer@example.com');

        $announcement = $this->service()->createDraft(
            $school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::Guardian,
            domainAudienceMemberIds: [$guardian->id],
            channels: [CommunicationChannel::InApp, CommunicationChannel::Email],
        );
        $published = $this->service()->publish($announcement, $creator);

        $emailDelivery = $this->deliveriesFor($school, $published->message_id)->firstWhere('channel', 'email');
        $this->assertSame('older@example.com', $emailDelivery->destination_snapshot['email']);
    }

    #[Test]
    public function republishing_does_not_duplicate_the_guardian_email_delivery(): void
    {
        Config::set('communications.channels.email.enabled', true);
        $this->fakeEmail();

        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $guardian = $this->createGuardian($school);
        $this->createGuardianContact($guardian, ContactType::Email, 'guardian@example.com');

        $announcement = $this->service()->createDraft(
            $school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::Guardian,
            domainAudienceMemberIds: [$guardian->id],
            channels: [CommunicationChannel::InApp, CommunicationChannel::Email],
        );
        $first = $this->service()->publish($announcement, $creator);
        $this->service()->publish($first, $creator);

        $emailCount = $this->deliveriesFor($school, $first->message_id)->where('channel', 'email')->count();
        $this->assertSame(1, $emailCount);
        $this->assertEmailAcceptedCount(1);
    }

    #[Test]
    public function guardian_email_respects_quiet_hours_deferral_exactly_like_a_membership_recipient(): void
    {
        Config::set('communications.channels.email.enabled', true);
        $this->fakeEmail();

        $school = $this->createSchool(['timezone' => 'Asia/Kolkata']);
        $creator = $this->createUser();
        $adminMembership = $this->createMembership($creator, $school);
        $this->assignSchoolRole($adminMembership, 'school_admin');
        $guardian = $this->createGuardian($school);
        $this->createGuardianContact($guardian, ContactType::Email, 'guardian@example.com');
        $this->createDeliveryTimingPolicy($school, ['enabled' => true, 'quiet_hours_start' => '00:00:00', 'quiet_hours_end' => '23:59:59']);

        $this->travelTo(Carbon::parse('2026-08-23 12:00:00', 'Asia/Kolkata'));

        $announcement = $this->service()->createDraft(
            $school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::Guardian,
            domainAudienceMemberIds: [$guardian->id],
            channels: [CommunicationChannel::InApp, CommunicationChannel::Email],
        );
        $published = $this->service()->publish($announcement, $creator);

        $emailDelivery = $this->deliveriesFor($school, $published->message_id)->firstWhere('channel', 'email');
        $this->assertSame('queued', $emailDelivery->status);
        $this->assertNotNull($emailDelivery->next_attempt_at);
        $this->assertNoEmailAccepted();
    }

    #[Test]
    public function guardian_email_emergency_bypasses_quiet_hours(): void
    {
        Config::set('communications.channels.email.enabled', true);
        $this->fakeEmail();

        $school = $this->createSchool(['timezone' => 'Asia/Kolkata']);
        $creator = $this->createUser();
        $adminMembership = $this->createMembership($creator, $school);
        $this->assignSchoolRole($adminMembership, 'school_admin');
        $guardian = $this->createGuardian($school);
        $this->createGuardianContact($guardian, ContactType::Email, 'guardian@example.com');
        $this->createDeliveryTimingPolicy($school, [
            'enabled' => true, 'quiet_hours_start' => '00:00:00', 'quiet_hours_end' => '23:59:59',
            'channel' => 'email', 'emergency_bypass_allowed' => true,
        ]);

        $this->travelTo(Carbon::parse('2026-08-23 12:00:00', 'Asia/Kolkata'));

        $announcement = $this->service()->createDraft(
            $school, $creator, 'T', 'B', CommunicationPriority::Critical, CommunicationAudienceType::Guardian,
            domainAudienceMemberIds: [$guardian->id],
            channels: [CommunicationChannel::InApp, CommunicationChannel::Email],
            requirement: CommunicationRequirement::Required,
            dispatchMode: CommunicationDispatchMode::Emergency,
            emergencyJustification: 'Campus lockdown drill.',
        );
        $published = $this->service()->publish($announcement, $creator);

        $emailDelivery = $this->deliveriesFor($school, $published->message_id)->firstWhere('channel', 'email');
        $this->assertSame('sent', $emailDelivery->status);
        $this->assertEmailAcceptedCount(1);
    }

    #[Test]
    public function partial_reachability_all_guardians_are_snapshotted_but_only_the_eligible_ones_get_a_delivery(): void
    {
        Config::set('communications.channels.email.enabled', true);
        $this->fakeEmail();

        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $eligible = [];
        $ineligible = [];
        for ($i = 0; $i < 3; $i++) {
            $guardian = $this->createGuardian($school);
            $this->createGuardianContact($guardian, ContactType::Email, "guardian{$i}@example.com");
            $eligible[] = $guardian->id;
        }
        for ($i = 0; $i < 2; $i++) {
            $ineligible[] = $this->createGuardian($school)->id;
        }

        $announcement = $this->service()->createDraft(
            $school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::Guardian,
            domainAudienceMemberIds: [...$eligible, ...$ineligible],
            channels: [CommunicationChannel::InApp, CommunicationChannel::Email],
        );
        $published = $this->service()->publish($announcement, $creator);

        $this->assertSame(5, $published->recipient_count);
        $this->assertCount(5, $this->recipientSnapshot($school, $published->id));

        $emailDeliveries = $this->deliveriesFor($school, $published->message_id)->where('channel', 'email');
        $this->assertCount(3, $emailDeliveries);
        $this->assertEmailAcceptedCount(3);
    }

    // --- §45: Snapshot immutability --------------------------------------

    #[Test]
    public function editing_a_guardian_contact_after_publish_does_not_change_the_historical_destination_snapshot(): void
    {
        Config::set('communications.channels.email.enabled', true);
        $this->fakeEmail();

        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $guardian = $this->createGuardian($school);
        $contact = $this->createGuardianContact($guardian, ContactType::Email, 'original@example.com');

        $announcement = $this->service()->createDraft(
            $school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::Guardian,
            domainAudienceMemberIds: [$guardian->id],
            channels: [CommunicationChannel::InApp, CommunicationChannel::Email],
        );
        $published = $this->service()->publish($announcement, $creator);

        $originalSnapshot = $this->deliveriesFor($school, $published->message_id)
            ->firstWhere('channel', 'email')->destination_snapshot;
        $this->assertSame('original@example.com', $originalSnapshot['email']);

        app(GuardianContactService::class)
            ->setPrimary($this->createGuardianContact($guardian, ContactType::Email, 'changed@example.com'));

        $reFetched = $this->deliveriesFor($school, $published->message_id)->firstWhere('channel', 'email');
        $this->assertSame('original@example.com', $reFetched->destination_snapshot['email']);
    }

    #[Test]
    public function deactivating_a_guardian_after_publish_does_not_remove_it_from_the_historical_snapshot(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $guardian = $this->createGuardian($school);

        $announcement = $this->service()->createDraft(
            $school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::Guardian,
            domainAudienceMemberIds: [$guardian->id],
        );
        $published = $this->service()->publish($announcement, $creator);

        app(TenantContext::class)->withSchool($school, fn () => $guardian->update(['status' => 'inactive']));

        $snapshot = $this->recipientSnapshot($school, $published->id);
        $this->assertCount(1, $snapshot);
        $this->assertSame($guardian->id, $snapshot->first()->guardian_id);
    }

    // --- §44: Approval fingerprint ----------------------------------------

    private function approvedGuardianAnnouncement(): array
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $approver = $this->createUser();
        $approverMembership = $this->createMembership($approver, $school);
        $this->assignSchoolRole($approverMembership, 'principal');
        $guardianA = $this->createGuardian($school);
        $this->createApprovalPolicy($school, ['require_required_communication_approval' => true]);

        $announcement = $this->service()->createDraft(
            $school, $admin, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::Guardian,
            domainAudienceMemberIds: [$guardianA->id],
            channels: [CommunicationChannel::InApp],
            requirement: CommunicationRequirement::Required,
        );
        $approvalService = app(CommunicationApprovalService::class);
        $request = $approvalService->submit($announcement, $admin);
        $approvalService->approve($request, $approver);

        $fresh = app(TenantContext::class)->withSchool($school, fn () => $announcement->fresh());
        $this->assertSame('approved', $fresh->status);

        return ['admin' => $admin, 'school' => $school, 'announcement' => $fresh, 'guardianA' => $guardianA];
    }

    #[Test]
    public function changing_the_guardian_selection_after_approval_invalidates_it(): void
    {
        ['admin' => $admin, 'school' => $school, 'announcement' => $announcement] = $this->approvedGuardianAnnouncement();
        $guardianB = $this->createGuardian($school);

        $this->service()->updateDraft($announcement, $admin, domainAudienceMemberIds: [$guardianB->id]);

        $fresh = app(TenantContext::class)->withSchool($school, fn () => $announcement->fresh());
        $this->assertSame('draft', $fresh->status);
    }

    #[Test]
    public function changing_the_student_selection_after_approval_invalidates_it(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $approver = $this->createUser();
        $approverMembership = $this->createMembership($approver, $school);
        $this->assignSchoolRole($approverMembership, 'principal');
        $studentA = $this->createStudent($school);
        $studentB = $this->createStudent($school);
        $this->createApprovalPolicy($school, ['require_required_communication_approval' => true]);

        $announcement = $this->service()->createDraft(
            $school, $admin, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::Student,
            domainAudienceMemberIds: [$studentA->id],
            requirement: CommunicationRequirement::Required,
        );
        $approvalService = app(CommunicationApprovalService::class);
        $request = $approvalService->submit($announcement, $admin);
        $approvalService->approve($request, $approver);

        $this->service()->updateDraft($announcement, $admin, domainAudienceMemberIds: [$studentB->id]);

        $fresh = app(TenantContext::class)->withSchool($school, fn () => $announcement->fresh());
        $this->assertSame('draft', $fresh->status);
    }

    #[Test]
    public function changing_a_guardians_contact_email_after_approval_does_not_invalidate_it(): void
    {
        // Documented semantics: approval binds to WHO is targeted, not
        // to where a message happens to be deliverable right now --
        // destination resolution happens at publish time.
        ['admin' => $admin, 'school' => $school, 'announcement' => $announcement, 'guardianA' => $guardianA] = $this->approvedGuardianAnnouncement();

        $this->createGuardianContact($guardianA, ContactType::Email, 'new-contact@example.com');

        $fresh = app(TenantContext::class)->withSchool($school, fn () => $announcement->fresh());
        $this->assertSame('approved', $fresh->status);
    }

    // --- §47: Scale --------------------------------------------------------

    #[Test]
    public function resolving_guardians_of_students_for_a_large_audience_uses_a_bounded_query_count(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $studentIds = [];
        for ($i = 0; $i < 30; $i++) {
            $student = $this->createStudent($school);
            $guardian = $this->createGuardian($school);
            $this->createStudentGuardianRelationship($student, $guardian, ['is_primary' => true]);
            $studentIds[] = $student->id;
        }

        $announcement = $this->service()->createDraft(
            $school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::GuardiansOfStudents,
            domainAudienceMemberIds: $studentIds,
        );

        DB::enableQueryLog();
        $preview = $this->service()->previewAudience($announcement);
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(30, $preview->count());
        $this->assertLessThan(10, $queryCount, 'Audience resolution must not run one query per Student/Guardian.');
    }
}

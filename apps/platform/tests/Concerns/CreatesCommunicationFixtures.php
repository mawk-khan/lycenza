<?php

namespace Tests\Concerns;

use App\Domain\Communications\Infrastructure\CommunicationAnnouncement;
use App\Domain\Communications\Infrastructure\CommunicationAnnouncementAudienceMember;
use App\Domain\Communications\Infrastructure\CommunicationAnnouncementChannel;
use App\Domain\Communications\Infrastructure\CommunicationAnnouncementDomainAudienceMember;
use App\Domain\Communications\Infrastructure\CommunicationAnnouncementRecipient;
use App\Domain\Communications\Infrastructure\CommunicationApprovalPolicy;
use App\Domain\Communications\Infrastructure\CommunicationApprovalRequest;
use App\Domain\Communications\Infrastructure\CommunicationChannelPolicy;
use App\Domain\Communications\Infrastructure\CommunicationConversationPolicy;
use App\Domain\Communications\Infrastructure\CommunicationDelivery;
use App\Domain\Communications\Infrastructure\CommunicationDeliveryAttempt;
use App\Domain\Communications\Infrastructure\CommunicationDeliveryPolicyDecision;
use App\Domain\Communications\Infrastructure\CommunicationDeliveryTimingPolicy;
use App\Domain\Communications\Infrastructure\CommunicationDomainConsentEvent;
use App\Domain\Communications\Infrastructure\CommunicationDomainPreference;
use App\Domain\Communications\Infrastructure\CommunicationMessage;
use App\Domain\Communications\Infrastructure\CommunicationPreference;
use App\Domain\Communications\Infrastructure\CommunicationRecipient;
use App\Domain\Communications\Infrastructure\CommunicationTemplate;
use App\Domain\Communications\Infrastructure\CommunicationThread;
use App\Domain\Communications\Infrastructure\CommunicationThreadParticipant;
use App\Domain\Guardians\Infrastructure\Guardian;
use App\Domain\Students\Infrastructure\Student;
use App\Models\School;
use App\Models\SchoolMembership;
use App\Models\User;
use App\Support\Tenancy\TenantContext;

/**
 * Phase 5A.1: raw fixture creation for tests that don't want to go
 * through CommunicationThreadService/CommunicationMessageService --
 * same "go through TenantContext::withSchool() exactly like production
 * code would" discipline as Tests\Concerns\CreatesTenancyFixtures's
 * Phase 0D helpers (there is no test-only bypass).
 */
trait CreatesCommunicationFixtures
{
    protected function createThread(School $school, User $creator, array $attributes = []): CommunicationThread
    {
        return app(TenantContext::class)->withSchool(
            $school,
            fn () => CommunicationThread::factory()->create(array_merge([
                'school_id' => $school->id,
                'created_by_user_id' => $creator->id,
            ], $attributes)),
        );
    }

    protected function createParticipant(CommunicationThread $thread, User $user, array $attributes = []): CommunicationThreadParticipant
    {
        return app(TenantContext::class)->withSchool(
            $thread->school,
            fn () => CommunicationThreadParticipant::factory()->create(array_merge([
                'school_id' => $thread->school_id,
                'thread_id' => $thread->id,
                'user_id' => $user->id,
            ], $attributes)),
        );
    }

    protected function createMessage(CommunicationThread $thread, User $sender, array $attributes = []): CommunicationMessage
    {
        return app(TenantContext::class)->withSchool(
            $thread->school,
            fn () => CommunicationMessage::factory()->create(array_merge([
                'school_id' => $thread->school_id,
                'thread_id' => $thread->id,
                'sender_user_id' => $sender->id,
            ], $attributes)),
        );
    }

    protected function createRecipient(CommunicationMessage $message, User $recipient): CommunicationRecipient
    {
        return app(TenantContext::class)->withSchool(
            $message->school,
            fn () => CommunicationRecipient::factory()->create([
                'school_id' => $message->school_id,
                'message_id' => $message->id,
                'recipient_user_id' => $recipient->id,
            ]),
        );
    }

    protected function createDelivery(CommunicationRecipient $recipient, array $attributes = []): CommunicationDelivery
    {
        return app(TenantContext::class)->withSchool(
            $recipient->school,
            fn () => CommunicationDelivery::factory()->create(array_merge([
                'school_id' => $recipient->school_id,
                'recipient_id' => $recipient->id,
            ], $attributes)),
        );
    }

    protected function createDeliveryAttempt(CommunicationDelivery $delivery, array $attributes = []): CommunicationDeliveryAttempt
    {
        return app(TenantContext::class)->withSchool(
            $delivery->school,
            fn () => CommunicationDeliveryAttempt::factory()->create(array_merge([
                'school_id' => $delivery->school_id,
                'communication_delivery_id' => $delivery->id,
            ], $attributes)),
        );
    }

    protected function createAnnouncement(School $school, User $creator, array $attributes = []): CommunicationAnnouncement
    {
        return app(TenantContext::class)->withSchool(
            $school,
            fn () => CommunicationAnnouncement::factory()->create(array_merge([
                'school_id' => $school->id,
                'created_by_user_id' => $creator->id,
            ], $attributes)),
        );
    }

    protected function createAnnouncementAudienceMember(CommunicationAnnouncement $announcement, SchoolMembership $membership): CommunicationAnnouncementAudienceMember
    {
        return app(TenantContext::class)->withSchool(
            $announcement->school,
            fn () => CommunicationAnnouncementAudienceMember::factory()->create([
                'school_id' => $announcement->school_id,
                'announcement_id' => $announcement->id,
                'school_membership_id' => $membership->id,
            ]),
        );
    }

    protected function createAnnouncementRecipient(CommunicationAnnouncement $announcement, SchoolMembership $membership): CommunicationAnnouncementRecipient
    {
        return app(TenantContext::class)->withSchool(
            $announcement->school,
            fn () => CommunicationAnnouncementRecipient::factory()->create([
                'school_id' => $announcement->school_id,
                'announcement_id' => $announcement->id,
                'school_membership_id' => $membership->id,
                'user_id' => $membership->user_id,
            ]),
        );
    }

    protected function createAnnouncementChannel(CommunicationAnnouncement $announcement, string $channel = 'in_app'): CommunicationAnnouncementChannel
    {
        return app(TenantContext::class)->withSchool(
            $announcement->school,
            fn () => CommunicationAnnouncementChannel::factory()->create([
                'school_id' => $announcement->school_id,
                'announcement_id' => $announcement->id,
                'channel' => $channel,
            ]),
        );
    }

    protected function createTemplate(School $school, User $creator, array $attributes = []): CommunicationTemplate
    {
        return app(TenantContext::class)->withSchool(
            $school,
            fn () => CommunicationTemplate::factory()->create(array_merge([
                'school_id' => $school->id,
                'created_by_user_id' => $creator->id,
            ], $attributes)),
        );
    }

    protected function createChannelPolicy(School $school, array $attributes = []): CommunicationChannelPolicy
    {
        return app(TenantContext::class)->withSchool(
            $school,
            fn () => CommunicationChannelPolicy::factory()->create(array_merge([
                'school_id' => $school->id,
            ], $attributes)),
        );
    }

    protected function createConversationPolicy(School $school, array $attributes = []): CommunicationConversationPolicy
    {
        return app(TenantContext::class)->withSchool(
            $school,
            fn () => CommunicationConversationPolicy::factory()->create(array_merge([
                'school_id' => $school->id,
            ], $attributes)),
        );
    }

    protected function createDomainPreference(School $school, array $attributes = []): CommunicationDomainPreference
    {
        return app(TenantContext::class)->withSchool(
            $school,
            fn () => CommunicationDomainPreference::factory()->create(array_merge([
                'school_id' => $school->id,
            ], $attributes)),
        );
    }

    protected function createDomainConsentEvent(School $school, User $recordedBy, array $attributes = []): CommunicationDomainConsentEvent
    {
        return app(TenantContext::class)->withSchool(
            $school,
            fn () => CommunicationDomainConsentEvent::factory()->create(array_merge([
                'school_id' => $school->id,
                'recorded_by_user_id' => $recordedBy->id,
            ], $attributes)),
        );
    }

    protected function createDeliveryTimingPolicy(School $school, array $attributes = []): CommunicationDeliveryTimingPolicy
    {
        return app(TenantContext::class)->withSchool(
            $school,
            fn () => CommunicationDeliveryTimingPolicy::factory()->create(array_merge([
                'school_id' => $school->id,
            ], $attributes)),
        );
    }

    protected function createPreference(SchoolMembership $membership, array $attributes = []): CommunicationPreference
    {
        return app(TenantContext::class)->withSchool(
            $membership->school,
            fn () => CommunicationPreference::factory()->create(array_merge([
                'school_id' => $membership->school_id,
                'school_membership_id' => $membership->id,
            ], $attributes)),
        );
    }

    protected function createPolicyDecision(School $school, string $messageId, User $recipient, array $attributes = []): CommunicationDeliveryPolicyDecision
    {
        return app(TenantContext::class)->withSchool(
            $school,
            fn () => CommunicationDeliveryPolicyDecision::factory()->create(array_merge([
                'school_id' => $school->id,
                'message_id' => $messageId,
                'recipient_user_id' => $recipient->id,
            ], $attributes)),
        );
    }

    protected function createApprovalPolicy(School $school, array $attributes = []): CommunicationApprovalPolicy
    {
        return app(TenantContext::class)->withSchool(
            $school,
            fn () => CommunicationApprovalPolicy::factory()->create(array_merge([
                'school_id' => $school->id,
            ], $attributes)),
        );
    }

    protected function createApprovalRequest(CommunicationAnnouncement $announcement, User $requester, array $attributes = []): CommunicationApprovalRequest
    {
        return app(TenantContext::class)->withSchool(
            $announcement->school,
            fn () => CommunicationApprovalRequest::factory()->create(array_merge([
                'school_id' => $announcement->school_id,
                'announcement_id' => $announcement->id,
                'requested_by_user_id' => $requester->id,
            ], $attributes)),
        );
    }

    /**
     * Phase 5B.1 -- the authored domain-audience selection. Pass
     * exactly one of $student/$guardian (matches the database's
     * num_nonnulls check).
     */
    protected function createDomainAudienceMember(CommunicationAnnouncement $announcement, ?Student $student = null, ?Guardian $guardian = null): CommunicationAnnouncementDomainAudienceMember
    {
        return app(TenantContext::class)->withSchool(
            $announcement->school,
            fn () => CommunicationAnnouncementDomainAudienceMember::query()->create([
                'school_id' => $announcement->school_id,
                'announcement_id' => $announcement->id,
                'student_id' => $student?->id,
                'guardian_id' => $guardian?->id,
            ]),
        );
    }

    protected function createStudentAnnouncementRecipient(CommunicationAnnouncement $announcement, Student $student): CommunicationAnnouncementRecipient
    {
        return app(TenantContext::class)->withSchool(
            $announcement->school,
            fn () => CommunicationAnnouncementRecipient::factory()->create([
                'school_id' => $announcement->school_id,
                'announcement_id' => $announcement->id,
                'student_id' => $student->id,
            ]),
        );
    }

    protected function createGuardianAnnouncementRecipient(CommunicationAnnouncement $announcement, Guardian $guardian): CommunicationAnnouncementRecipient
    {
        return app(TenantContext::class)->withSchool(
            $announcement->school,
            fn () => CommunicationAnnouncementRecipient::factory()->create([
                'school_id' => $announcement->school_id,
                'announcement_id' => $announcement->id,
                'guardian_id' => $guardian->id,
            ]),
        );
    }

    protected function createGuardianRecipient(CommunicationMessage $message, Guardian $guardian): CommunicationRecipient
    {
        return app(TenantContext::class)->withSchool(
            $message->school,
            fn () => CommunicationRecipient::factory()->create([
                'school_id' => $message->school_id,
                'message_id' => $message->id,
                'recipient_guardian_id' => $guardian->id,
            ]),
        );
    }

    protected function createGuardianPolicyDecision(School $school, string $messageId, Guardian $guardian, array $attributes = []): CommunicationDeliveryPolicyDecision
    {
        return app(TenantContext::class)->withSchool(
            $school,
            fn () => CommunicationDeliveryPolicyDecision::factory()->create(array_merge([
                'school_id' => $school->id,
                'message_id' => $messageId,
                'recipient_guardian_id' => $guardian->id,
            ], $attributes)),
        );
    }
}

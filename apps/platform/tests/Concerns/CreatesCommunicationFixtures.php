<?php

namespace Tests\Concerns;

use App\Domain\Communications\Infrastructure\CommunicationAnnouncement;
use App\Domain\Communications\Infrastructure\CommunicationAnnouncementAudienceMember;
use App\Domain\Communications\Infrastructure\CommunicationAnnouncementRecipient;
use App\Domain\Communications\Infrastructure\CommunicationDelivery;
use App\Domain\Communications\Infrastructure\CommunicationDeliveryAttempt;
use App\Domain\Communications\Infrastructure\CommunicationMessage;
use App\Domain\Communications\Infrastructure\CommunicationRecipient;
use App\Domain\Communications\Infrastructure\CommunicationThread;
use App\Domain\Communications\Infrastructure\CommunicationThreadParticipant;
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
}

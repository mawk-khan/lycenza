<?php

namespace App\Domain\Communications\Application;

use App\Domain\Communications\Application\Exceptions\InvalidParticipantException;
use App\Domain\Communications\Events\CommunicationParticipantAdded;
use App\Domain\Communications\Events\CommunicationThreadCreated;
use App\Domain\Communications\Infrastructure\CommunicationThread;
use App\Domain\Communications\Infrastructure\CommunicationThreadParticipant;
use App\Models\Campus;
use App\Models\School;
use App\Models\SchoolMembership;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * The only sanctioned write path for Thread lifecycle (Phase 5A.1 §2.2/
 * §2.3) -- never write CommunicationThread/CommunicationThreadParticipant
 * directly from a controller. Every method: validate -> write state ->
 * audit -> emit domain event, inside one transaction (ADR 0025),
 * following the same pattern App\Domain\AcademicStructure\Application\
 * AcademicYearService established.
 */
class CommunicationThreadService
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
    ) {}

    /**
     * @param  array<int, string>  $participantUserIds  additional participants beyond the creator
     */
    public function createThread(
        School $school,
        User $creator,
        string $threadType,
        ?string $subject,
        array $participantUserIds = [],
        ?Campus $campus = null,
    ): CommunicationThread {
        return $this->context->withSchool($school, fn () => DB::transaction(function () use ($school, $creator, $threadType, $subject, $participantUserIds, $campus) {
            $thread = CommunicationThread::query()->create([
                'school_id' => $school->id,
                'campus_id' => $campus?->id,
                'thread_type' => $threadType,
                'subject' => $subject,
                'status' => 'open',
                'created_by_user_id' => $creator->id,
                'last_activity_at' => now(),
            ]);

            $this->addParticipant($thread, $creator, actor: $creator, audited: false);

            foreach (array_unique($participantUserIds) as $userId) {
                if ($userId === $creator->id) {
                    continue;
                }

                $user = User::query()->find($userId);
                if ($user !== null) {
                    $this->addParticipant($thread, $user, actor: $creator, audited: false);
                }
            }

            $this->audit->school($school, 'communication.thread.created', actor: $creator, subject: $thread, metadata: [
                'threadType' => $thread->thread_type,
                'participantCount' => $thread->participants()->count(),
            ]);

            event(new CommunicationThreadCreated($school->id, $thread->id, $thread->thread_type, $creator->id));

            return $thread->fresh();
        }));
    }

    /**
     * Adds $user to $thread. Rejects a user with no active
     * SchoolMembership in the thread's School (root CLAUDE.md rule 19:
     * an id alone is never authorization) and reactivates (rather than
     * duplicates) a participant row for someone who previously left.
     */
    public function addParticipant(CommunicationThread $thread, User $user, ?User $actor = null, bool $audited = true): CommunicationThreadParticipant
    {
        $isMember = SchoolMembership::query()
            ->where('user_id', $user->id)
            ->where('school_id', $thread->school_id)
            ->where('status', 'active')
            ->exists();

        if (! $isMember) {
            throw new InvalidParticipantException;
        }

        return $this->context->withSchool($thread->school, function () use ($thread, $user, $actor, $audited) {
            $participant = CommunicationThreadParticipant::query()->updateOrCreate(
                ['thread_id' => $thread->id, 'user_id' => $user->id],
                ['school_id' => $thread->school_id, 'joined_at' => now(), 'left_at' => null],
            );

            if ($audited) {
                $this->audit->school($thread->school, 'communication.participant.added', actor: $actor, subject: $participant, metadata: [
                    'threadId' => $thread->id,
                    'userId' => $user->id,
                ]);

                event(new CommunicationParticipantAdded($thread->school_id, $thread->id, $user->id));
            }

            return $participant;
        });
    }

    public function removeParticipant(CommunicationThreadParticipant $participant, ?User $actor = null): CommunicationThreadParticipant
    {
        return $this->context->withSchool($participant->school, function () use ($participant, $actor) {
            $participant->update(['left_at' => now()]);

            $this->audit->school($participant->school, 'communication.participant.removed', actor: $actor, subject: $participant, metadata: [
                'threadId' => $participant->thread_id,
                'userId' => $participant->user_id,
            ]);

            return $participant->refresh();
        });
    }
}

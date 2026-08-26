<?php

namespace App\Domain\Communications\Application\Policy;

use App\Domain\Communications\Domain\CommunicationChannel;
use App\Domain\Communications\Domain\CommunicationConsentStatus;
use App\Domain\Communications\Infrastructure\CommunicationDomainConsentEvent;
use App\Domain\Guardians\Infrastructure\Guardian;
use App\Domain\Students\Infrastructure\Student;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Phase 5D.2 §13/§14/§30 -- the sole write path for recording an
 * explicit consent/withdrawal decision for a Guardian/Student domain
 * identity's channel, and the sole read path for deriving CURRENT
 * consent status from that append-only ledger. Centralizes the
 * "recording a consent decision is more sensitive than viewing a
 * setting" authorization concern (brief §30) at the caller (this
 * class never checks capabilities itself -- the HTTP controller does,
 * via AuthorizesCapability, exactly like every other write path in
 * this module; centralization here means "one service, not scattered
 * ad hoc writes," not "authorization moves into the service").
 *
 * A recorded event is evidence that a consent/withdrawal decision was
 * made -- it is not, by itself, a claim that School OS satisfies any
 * particular legal/regulatory regime (brief §30 doc requirement).
 */
class CommunicationConsentService
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
    ) {}

    public function recordGrantForGuardian(School $school, Guardian $guardian, User $actor, CommunicationChannel $channel, ?string $source = null, ?string $note = null): CommunicationDomainConsentEvent
    {
        return $this->record($school, $actor, $channel, CommunicationConsentStatus::Granted, $source, $note, guardian: $guardian, student: null);
    }

    public function recordWithdrawalForGuardian(School $school, Guardian $guardian, User $actor, CommunicationChannel $channel, ?string $source = null, ?string $note = null): CommunicationDomainConsentEvent
    {
        return $this->record($school, $actor, $channel, CommunicationConsentStatus::Withdrawn, $source, $note, guardian: $guardian, student: null);
    }

    public function recordGrantForStudent(School $school, Student $student, User $actor, CommunicationChannel $channel, ?string $source = null, ?string $note = null): CommunicationDomainConsentEvent
    {
        return $this->record($school, $actor, $channel, CommunicationConsentStatus::Granted, $source, $note, guardian: null, student: $student);
    }

    public function recordWithdrawalForStudent(School $school, Student $student, User $actor, CommunicationChannel $channel, ?string $source = null, ?string $note = null): CommunicationDomainConsentEvent
    {
        return $this->record($school, $actor, $channel, CommunicationConsentStatus::Withdrawn, $source, $note, guardian: null, student: $student);
    }

    /**
     * Null means "unknown -- no consent event was ever recorded,"
     * deliberately distinct from either explicit status (brief §15).
     */
    public function currentStatusForGuardian(School $school, Guardian $guardian, CommunicationChannel $channel): ?CommunicationConsentStatus
    {
        return $this->context->withSchool($school, function () use ($school, $guardian, $channel) {
            $event = CommunicationDomainConsentEvent::query()
                ->where('school_id', $school->id)
                ->where('guardian_id', $guardian->id)
                ->where('channel', $channel->value)
                ->orderByDesc('recorded_at')
                ->orderByDesc('id')
                ->first();

            return $event?->status;
        });
    }

    public function currentStatusForStudent(School $school, Student $student, CommunicationChannel $channel): ?CommunicationConsentStatus
    {
        return $this->context->withSchool($school, function () use ($school, $student, $channel) {
            $event = CommunicationDomainConsentEvent::query()
                ->where('school_id', $school->id)
                ->where('student_id', $student->id)
                ->where('channel', $channel->value)
                ->orderByDesc('recorded_at')
                ->orderByDesc('id')
                ->first();

            return $event?->status;
        });
    }

    /**
     * Phase 5D.2 §66 -- ONE batched DISTINCT ON query deriving the
     * current status for an entire Guardian chunk, never one query per
     * Guardian.
     *
     * @param  array<int, string>  $guardianIds
     * @return Collection<string, CommunicationConsentStatus> guardianId => current status
     */
    public function currentStatusesForGuardians(School $school, array $guardianIds, CommunicationChannel $channel): Collection
    {
        if ($guardianIds === []) {
            return collect();
        }

        return $this->context->withSchool($school, fn () => DB::table('communication_domain_consent_events')
            ->selectRaw('DISTINCT ON (guardian_id) guardian_id, status')
            ->where('school_id', $school->id)
            ->whereIn('guardian_id', $guardianIds)
            ->where('channel', $channel->value)
            ->orderBy('guardian_id')
            ->orderByDesc('recorded_at')
            ->orderByDesc('id')
            ->get()
            ->mapWithKeys(fn ($row) => [$row->guardian_id => CommunicationConsentStatus::from($row->status)]));
    }

    /**
     * @param  array<int, string>  $studentIds
     * @return Collection<string, CommunicationConsentStatus> studentId => current status
     */
    public function currentStatusesForStudents(School $school, array $studentIds, CommunicationChannel $channel): Collection
    {
        if ($studentIds === []) {
            return collect();
        }

        return $this->context->withSchool($school, fn () => DB::table('communication_domain_consent_events')
            ->selectRaw('DISTINCT ON (student_id) student_id, status')
            ->where('school_id', $school->id)
            ->whereIn('student_id', $studentIds)
            ->where('channel', $channel->value)
            ->orderBy('student_id')
            ->orderByDesc('recorded_at')
            ->orderByDesc('id')
            ->get()
            ->mapWithKeys(fn ($row) => [$row->student_id => CommunicationConsentStatus::from($row->status)]));
    }

    private function record(
        School $school,
        User $actor,
        CommunicationChannel $channel,
        CommunicationConsentStatus $status,
        ?string $source,
        ?string $note,
        ?Guardian $guardian,
        ?Student $student,
    ): CommunicationDomainConsentEvent {
        return $this->context->withSchool($school, function () use ($school, $actor, $channel, $status, $source, $note, $guardian, $student) {
            $event = CommunicationDomainConsentEvent::query()->create([
                'school_id' => $school->id,
                'guardian_id' => $guardian?->id,
                'student_id' => $student?->id,
                'channel' => $channel->value,
                'status' => $status->value,
                'recorded_at' => now(),
                'recorded_by_user_id' => $actor->id,
                'source' => $source,
                'note' => $note,
            ]);

            $eventType = $status === CommunicationConsentStatus::Granted
                ? 'communication.consent.granted'
                : 'communication.consent.withdrawn';

            $this->audit->school($school, $eventType, actor: $actor, subject: $event, metadata: array_filter([
                'guardianId' => $guardian?->id,
                'studentId' => $student?->id,
                'channel' => $channel->value,
            ], fn ($value) => $value !== null));

            return $event;
        });
    }
}

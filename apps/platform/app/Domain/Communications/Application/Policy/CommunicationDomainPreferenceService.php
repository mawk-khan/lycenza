<?php

namespace App\Domain\Communications\Application\Policy;

use App\Domain\Communications\Domain\CommunicationChannel;
use App\Domain\Communications\Infrastructure\CommunicationDomainPreference;
use App\Domain\Guardians\Infrastructure\Guardian;
use App\Domain\Students\Infrastructure\Student;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Collection;

/**
 * Phase 5D.2 §11/§27/§35 -- the sole write and batched-read path for a
 * Guardian/Student domain identity's current external-channel
 * preference. Never IN_APP (brief §7/§38 -- a linked identity's IN_APP
 * eligibility always comes from its SchoolMembership's own
 * App\Domain\Communications\Infrastructure\CommunicationPreference,
 * untouched by this class). Mirrors
 * App\Domain\Communications\Application\Policy\CommunicationPreferenceService's
 * validate -> write -> audit shape.
 */
class CommunicationDomainPreferenceService
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
    ) {}

    public function setPreferenceForGuardian(
        School $school,
        Guardian $guardian,
        User $actor,
        CommunicationChannel $channel,
        bool $enabled,
    ): CommunicationDomainPreference {
        return $this->set($school, $actor, $channel, $enabled, guardian: $guardian, student: null);
    }

    public function setPreferenceForStudent(
        School $school,
        Student $student,
        User $actor,
        CommunicationChannel $channel,
        bool $enabled,
    ): CommunicationDomainPreference {
        return $this->set($school, $actor, $channel, $enabled, guardian: null, student: $student);
    }

    public function currentPreferenceForGuardian(School $school, Guardian $guardian, CommunicationChannel $channel): ?CommunicationDomainPreference
    {
        return $this->context->withSchool($school, fn () => CommunicationDomainPreference::query()
            ->where('guardian_id', $guardian->id)
            ->where('channel', $channel->value)
            ->first());
    }

    public function currentPreferenceForStudent(School $school, Student $student, CommunicationChannel $channel): ?CommunicationDomainPreference
    {
        return $this->context->withSchool($school, fn () => CommunicationDomainPreference::query()
            ->where('student_id', $student->id)
            ->where('channel', $channel->value)
            ->first());
    }

    /**
     * Phase 5D.2 §66 -- ONE batched query for an entire audience
     * chunk, never one query per Guardian, mirroring
     * AccountLinkService::activeLinksForGuardians()'s identical
     * batching discipline.
     *
     * @param  array<int, string>  $guardianIds
     * @return Collection<string, CommunicationDomainPreference> guardianId => current preference row
     */
    public function currentPreferencesForGuardians(School $school, array $guardianIds, CommunicationChannel $channel): Collection
    {
        if ($guardianIds === []) {
            return collect();
        }

        return $this->context->withSchool($school, fn () => CommunicationDomainPreference::query()
            ->whereIn('guardian_id', $guardianIds)
            ->where('channel', $channel->value)
            ->get()
            ->keyBy('guardian_id'));
    }

    /**
     * @param  array<int, string>  $studentIds
     * @return Collection<string, CommunicationDomainPreference> studentId => current preference row
     */
    public function currentPreferencesForStudents(School $school, array $studentIds, CommunicationChannel $channel): Collection
    {
        if ($studentIds === []) {
            return collect();
        }

        return $this->context->withSchool($school, fn () => CommunicationDomainPreference::query()
            ->whereIn('student_id', $studentIds)
            ->where('channel', $channel->value)
            ->get()
            ->keyBy('student_id'));
    }

    private function set(
        School $school,
        User $actor,
        CommunicationChannel $channel,
        bool $enabled,
        ?Guardian $guardian,
        ?Student $student,
    ): CommunicationDomainPreference {
        return $this->context->withSchool($school, function () use ($school, $actor, $channel, $enabled, $guardian, $student) {
            $preference = CommunicationDomainPreference::query()->updateOrCreate(
                [
                    'school_id' => $school->id,
                    'guardian_id' => $guardian?->id,
                    'student_id' => $student?->id,
                    'channel' => $channel->value,
                ],
                ['preference' => $enabled ? 'enabled' : 'disabled'],
            );

            $this->audit->school($school, 'communication.domain_preference.updated', actor: $actor, subject: $preference, metadata: array_filter([
                'guardianId' => $guardian?->id,
                'studentId' => $student?->id,
                'channel' => $channel->value,
                'preference' => $preference->preference,
            ], fn ($value) => $value !== null));

            return $preference;
        });
    }
}

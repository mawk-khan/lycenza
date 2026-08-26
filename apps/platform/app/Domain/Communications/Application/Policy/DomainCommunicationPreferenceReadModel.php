<?php

namespace App\Domain\Communications\Application\Policy;

use App\Domain\Communications\Application\Channels\GuardianEmailAddressResolver;
use App\Domain\Communications\Domain\CommunicationChannel;
use App\Domain\Guardians\Infrastructure\Guardian;
use App\Domain\Students\Infrastructure\Student;
use App\Models\School;

/**
 * Phase 5D.2 §36 -- composes preference + consent + endpoint
 * availability into one DomainCommunicationPreferenceState per
 * Guardian/Student, for the admin detail-page UI. Read-only; never
 * writes, never decides delivery eligibility itself (that remains
 * App\Domain\Communications\Application\AnnouncementService's job,
 * §21's decision order) -- this is presentation composition only.
 */
class DomainCommunicationPreferenceReadModel
{
    public function __construct(
        private readonly CommunicationDomainPreferenceService $preferences,
        private readonly CommunicationConsentService $consents,
        private readonly GuardianEmailAddressResolver $guardianEmailAddressResolver,
    ) {}

    public function forGuardianEmail(School $school, Guardian $guardian): DomainCommunicationPreferenceState
    {
        $preference = $this->preferences->currentPreferenceForGuardian($school, $guardian, CommunicationChannel::Email);
        $consentStatus = $this->consents->currentStatusForGuardian($school, $guardian, CommunicationChannel::Email);
        $endpointAvailable = $this->guardianEmailAddressResolver->resolve($guardian) !== null;

        return new DomainCommunicationPreferenceState(
            channel: CommunicationChannel::Email->value,
            preferenceEnabled: $preference?->isEnabled(),
            consentStatus: $consentStatus,
            endpointAvailable: $endpointAvailable,
        );
    }

    /**
     * Phase 5D.2 §10/§32 -- Student has no canonical external email
     * endpoint today (brief re-confirmed: no StudentEmailAddressResolver/
     * StudentContact exists anywhere in this repository). This method
     * exists for structural/test symmetry only -- `endpointAvailable`
     * is always `false`, honestly, never a fabricated endpoint.
     */
    public function forStudentEmail(School $school, Student $student): DomainCommunicationPreferenceState
    {
        $preference = $this->preferences->currentPreferenceForStudent($school, $student, CommunicationChannel::Email);
        $consentStatus = $this->consents->currentStatusForStudent($school, $student, CommunicationChannel::Email);

        return new DomainCommunicationPreferenceState(
            channel: CommunicationChannel::Email->value,
            preferenceEnabled: $preference?->isEnabled(),
            consentStatus: $consentStatus,
            endpointAvailable: false,
        );
    }
}

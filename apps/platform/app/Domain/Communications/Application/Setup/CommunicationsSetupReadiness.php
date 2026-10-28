<?php

namespace App\Domain\Communications\Application\Setup;

use App\Models\School;
use App\Support\Setup\SetupReadiness;
use App\Support\Setup\SetupReadinessProvider;

/**
 * ORG.12A (ADR 0080 §10): Communications and the School events calendar
 * need no mandatory School setup -- a School without its own approval,
 * channel or conversation policy row uses the documented safe default
 * (CommunicationApprovalPolicy, CommunicationChannelPolicy). Email delivery
 * is the platform email layer (ADR 0055), not School configuration.
 */
class CommunicationsSetupReadiness implements SetupReadinessProvider
{
    public function keys(): array
    {
        return ['communications.communications', 'communications.events'];
    }

    public function evaluate(string $key, School $school): SetupReadiness
    {
        return SetupReadiness::noSetupRequired($key === 'communications.events'
            ? 'The School events calendar needs no separate setup.'
            : 'Announcements and conversations work with the School\'s safe default policies.');
    }
}

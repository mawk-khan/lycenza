<?php

namespace App\Domain\Communications\Application\Policy;

use App\Domain\Communications\Domain\CommunicationChannel;
use App\Domain\Communications\Infrastructure\CommunicationPreference;
use App\Models\SchoolMembership;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Tenancy\TenantContext;

/**
 * Phase 5A.5 §11/§29/§30 -- the sole write path for a recipient's own
 * channel preference. Self-service only in this checkpoint (brief
 * §30: "prefer self-service membership preferences... do not invent
 * broad impersonation powers") -- there is no method here that takes
 * an arbitrary actor updating someone ELSE's membership;
 * App\Domain\Communications\Http\Controllers\CommunicationPreferenceController
 * always resolves `$membership` from the CURRENT authenticated
 * actor's own active membership in the current School, never from a
 * client-supplied membership id (root CLAUDE.md rule 19).
 */
class CommunicationPreferenceService
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
    ) {}

    public function setPreference(
        SchoolMembership $membership,
        User $actor,
        CommunicationChannel $channel,
        bool $enabled,
    ): CommunicationPreference {
        return $this->context->withSchool($membership->school, function () use ($membership, $actor, $channel, $enabled) {
            $preference = CommunicationPreference::query()->updateOrCreate(
                ['school_id' => $membership->school_id, 'school_membership_id' => $membership->id, 'channel' => $channel->value],
                ['preference' => $enabled ? 'enabled' : 'disabled'],
            );

            $this->audit->school($membership->school, 'communication.preference.updated', actor: $actor, subject: $preference, metadata: [
                'channel' => $channel->value,
                'preference' => $preference->preference,
            ]);

            return $preference;
        });
    }
}

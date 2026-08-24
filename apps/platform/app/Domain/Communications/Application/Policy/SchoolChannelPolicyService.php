<?php

namespace App\Domain\Communications\Application\Policy;

use App\Domain\Communications\Domain\CommunicationChannel;
use App\Domain\Communications\Infrastructure\CommunicationChannelPolicy;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Tenancy\TenantContext;

/**
 * Phase 5A.5 §15/§26 -- the sole write path for a School's explicit
 * channel-policy overrides, mirroring
 * App\Support\Settings\SchoolSettingsService's validate -> write ->
 * audit shape. Read access for administration
 * (App\Domain\Communications\Http\Controllers\CommunicationChannelPolicyController::show())
 * goes through CommunicationChannelPolicyService::policyFor() directly
 * -- this class only handles writes.
 */
class SchoolChannelPolicyService
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
    ) {}

    public function setPolicy(
        School $school,
        User $actor,
        CommunicationChannel $channel,
        bool $optionalAllowed,
        bool $requiredAllowed,
        bool $recipientCanOptOut,
    ): CommunicationChannelPolicy {
        return $this->context->withSchool($school, function () use ($school, $actor, $channel, $optionalAllowed, $requiredAllowed, $recipientCanOptOut) {
            $policy = CommunicationChannelPolicy::query()->updateOrCreate(
                ['school_id' => $school->id, 'channel' => $channel->value],
                [
                    'optional_allowed' => $optionalAllowed,
                    'required_allowed' => $requiredAllowed,
                    'recipient_can_opt_out' => $recipientCanOptOut,
                ],
            );

            $this->audit->school($school, 'communication.channel_policy.updated', actor: $actor, subject: $policy, metadata: [
                'channel' => $channel->value,
                'optionalAllowed' => $optionalAllowed,
                'requiredAllowed' => $requiredAllowed,
                'recipientCanOptOut' => $recipientCanOptOut,
            ]);

            return $policy;
        });
    }
}

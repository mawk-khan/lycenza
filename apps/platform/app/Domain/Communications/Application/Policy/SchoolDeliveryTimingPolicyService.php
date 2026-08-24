<?php

namespace App\Domain\Communications\Application\Policy;

use App\Domain\Communications\Domain\CommunicationChannel;
use App\Domain\Communications\Infrastructure\CommunicationDeliveryTimingPolicy;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Tenancy\TenantContext;

/**
 * Phase 5A.9 -- the sole write path for a School's quiet-hours policy,
 * mirroring SchoolChannelPolicyService's own validate(controller) ->
 * write -> audit shape exactly. Read access
 * (App\Domain\Communications\Http\Controllers\CommunicationChannelPolicyController::show())
 * queries the model directly, same division of responsibility as the
 * channel-policy pair.
 */
class SchoolDeliveryTimingPolicyService
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
    ) {}

    public function setPolicy(
        School $school,
        User $actor,
        CommunicationChannel $channel,
        bool $enabled,
        ?string $quietHoursStart,
        ?string $quietHoursEnd,
        bool $emergencyBypassAllowed = false,
    ): CommunicationDeliveryTimingPolicy {
        return $this->context->withSchool($school, function () use ($school, $actor, $channel, $enabled, $quietHoursStart, $quietHoursEnd, $emergencyBypassAllowed) {
            $existing = CommunicationDeliveryTimingPolicy::query()
                ->where('school_id', $school->id)
                ->where('channel', $channel->value)
                ->first();
            $wasEnabled = $existing !== null ? $existing->enabled : false;

            $policy = CommunicationDeliveryTimingPolicy::query()->updateOrCreate(
                ['school_id' => $school->id, 'channel' => $channel->value],
                [
                    'enabled' => $enabled,
                    'quiet_hours_start' => $quietHoursStart,
                    'quiet_hours_end' => $quietHoursEnd,
                    'emergency_bypass_allowed' => $emergencyBypassAllowed,
                ],
            );

            $eventType = match (true) {
                $enabled && ! $wasEnabled => 'communication.delivery_timing_policy.enabled',
                ! $enabled && $wasEnabled => 'communication.delivery_timing_policy.disabled',
                default => 'communication.delivery_timing_policy.updated',
            };

            $this->audit->school($school, $eventType, actor: $actor, subject: $policy, metadata: [
                'channel' => $channel->value,
                'enabled' => $enabled,
                'quietHoursStart' => $quietHoursStart,
                'quietHoursEnd' => $quietHoursEnd,
                'emergencyBypassAllowed' => $emergencyBypassAllowed,
            ]);

            return $policy;
        });
    }
}

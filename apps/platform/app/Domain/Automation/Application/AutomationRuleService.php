<?php

namespace App\Domain\Automation\Application;

use App\Domain\Automation\Application\Catalog\AutomationRuleCatalog;
use App\Domain\Automation\Application\Catalog\AutomationRuleType;
use App\Domain\Automation\Application\Exceptions\IneligibleAutomationOwnerException;
use App\Domain\Automation\Application\Exceptions\UnknownAutomationRuleTypeException;
use App\Domain\Automation\Infrastructure\AutomationRuleInstance;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Configuration of a School's rule instances (ADR 0043 §1, §5, §9). Every
 * public method requires `automation.manage`; `automation.view` never
 * reaches here.
 *
 * - enable(): creates the School's instance of a catalog rule type if
 *   needed, and enables (or re-enables a suspended) instance with the
 *   ACTING manager as accountable owner. Ownership is accepted by that
 *   person, never assigned to someone else.
 * - takeOwnership(): the acting manager becomes owner; status unchanged.
 * - disable(): stops future executions.
 * - suspend(): the system consequence of a failed authority check or the
 *   flood cap (actor recorded as none).
 *
 * Each change is one School audit event (`automation.rule.*`), ids and
 * codes only. Configuring grants nothing: the owner's authority is
 * re-verified before every execution.
 */
class AutomationRuleService
{
    use AuthorizesCapability;

    public const MANAGE_CAPABILITY = 'automation.manage';

    public function __construct(
        private readonly AutomationRuleCatalog $catalog,
        private readonly OwnerAuthorityVerifier $authority,
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
    ) {}

    public function enable(School $school, string $ruleTypeKey, User $actor): AutomationRuleInstance
    {
        $this->authorizeCapabilityFor($actor, self::MANAGE_CAPABILITY, $school);
        $ruleType = $this->ruleType($ruleTypeKey);
        $this->assertEligibleOwner($actor, $ruleType, $school);

        return $this->context->withSchool($school, fn () => DB::transaction(function () use ($school, $ruleType, $actor): AutomationRuleInstance {
            $instance = $this->lockedInstance($school, $ruleType);
            $created = $instance === null;

            if ($created) {
                $instance = AutomationRuleInstance::query()->create([
                    'school_id' => $school->id,
                    'rule_type' => $ruleType->key(),
                    'status' => AutomationRuleInstance::STATUS_DISABLED,
                ]);
                $this->record($school, 'automation.rule.created', $actor, $instance, []);
            }

            $previousStatus = $instance->status;
            $previousOwner = $instance->owner_user_id;

            $instance->update([
                'status' => AutomationRuleInstance::STATUS_ENABLED,
                'owner_user_id' => $actor->id,
                'enabled_at' => now(),
                'suspended_at' => null,
                'suspension_reason' => null,
            ]);

            $this->record($school, 'automation.rule.enabled', $actor, $instance, [
                'previousStatus' => $previousStatus,
                'ownerUserId' => $actor->id,
                'previousOwnerUserId' => $previousOwner,
            ]);

            return $instance;
        }));
    }

    public function disable(School $school, string $ruleTypeKey, User $actor): AutomationRuleInstance
    {
        $this->authorizeCapabilityFor($actor, self::MANAGE_CAPABILITY, $school);
        $ruleType = $this->ruleType($ruleTypeKey);

        return $this->context->withSchool($school, fn () => DB::transaction(function () use ($school, $ruleType, $actor): AutomationRuleInstance {
            $instance = $this->lockedInstance($school, $ruleType) ?? throw new UnknownAutomationRuleTypeException('This rule has not been configured.');

            if ($instance->status === AutomationRuleInstance::STATUS_DISABLED) {
                return $instance;
            }

            $previousStatus = $instance->status;
            $instance->update([
                'status' => AutomationRuleInstance::STATUS_DISABLED,
                'suspended_at' => null,
                'suspension_reason' => null,
            ]);

            $this->record($school, 'automation.rule.disabled', $actor, $instance, ['previousStatus' => $previousStatus]);

            return $instance;
        }));
    }

    public function takeOwnership(School $school, string $ruleTypeKey, User $actor): AutomationRuleInstance
    {
        $this->authorizeCapabilityFor($actor, self::MANAGE_CAPABILITY, $school);
        $ruleType = $this->ruleType($ruleTypeKey);
        $this->assertEligibleOwner($actor, $ruleType, $school);

        return $this->context->withSchool($school, fn () => DB::transaction(function () use ($school, $ruleType, $actor): AutomationRuleInstance {
            $instance = $this->lockedInstance($school, $ruleType) ?? throw new UnknownAutomationRuleTypeException('This rule has not been configured.');

            if ($instance->owner_user_id === $actor->id) {
                return $instance;
            }

            $previousOwner = $instance->owner_user_id;
            $instance->update(['owner_user_id' => $actor->id]);

            $this->record($school, 'automation.rule.owner_changed', $actor, $instance, [
                'ownerUserId' => $actor->id,
                'previousOwnerUserId' => $previousOwner,
            ]);

            return $instance;
        }));
    }

    /**
     * System consequence (no human actor). Call inside the School's context
     * and an open transaction that already holds the instance row.
     */
    public function suspend(School $school, AutomationRuleInstance $instance, string $reason, ?string $executionId = null): void
    {
        if ($instance->status === AutomationRuleInstance::STATUS_SUSPENDED) {
            return;
        }

        $previousStatus = $instance->status;
        $instance->update([
            'status' => AutomationRuleInstance::STATUS_SUSPENDED,
            'suspended_at' => now(),
            'suspension_reason' => $reason,
        ]);

        $this->record($school, 'automation.rule.suspended', null, $instance, array_filter([
            'previousStatus' => $previousStatus,
            'reason' => $reason,
            'ownerUserId' => $instance->owner_user_id,
            'executionId' => $executionId,
        ], fn ($value) => $value !== null));
    }

    private function ruleType(string $key): AutomationRuleType
    {
        return $this->catalog->find($key) ?? throw new UnknownAutomationRuleTypeException('Unknown Automation rule type.');
    }

    private function assertEligibleOwner(User $actor, AutomationRuleType $ruleType, School $school): void
    {
        $failure = $this->authority->failureFor($actor, $ruleType, $school);

        if ($failure !== null) {
            throw IneligibleAutomationOwnerException::because(match ($failure) {
                OwnerAuthorityVerifier::OWNER_CAPABILITY_MISSING => 'you do not hold every capability this rule needs',
                default => 'your account or membership is not active in this School',
            });
        }
    }

    private function lockedInstance(School $school, AutomationRuleType $ruleType): ?AutomationRuleInstance
    {
        return AutomationRuleInstance::query()
            ->where('school_id', $school->id)
            ->where('rule_type', $ruleType->key())
            ->lockForUpdate()
            ->first();
    }

    /** @param array<string, string|null> $metadata */
    private function record(School $school, string $eventType, ?User $actor, AutomationRuleInstance $instance, array $metadata): void
    {
        $this->audit->school($school, $eventType, actor: $actor, subject: $instance, metadata: [
            'ruleInstanceId' => $instance->id,
            'ruleType' => $instance->rule_type,
            ...$metadata,
        ]);
    }
}

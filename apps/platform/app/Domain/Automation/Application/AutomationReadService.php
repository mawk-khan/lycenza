<?php

namespace App\Domain\Automation\Application;

use App\Domain\Automation\Application\Catalog\AutomationRuleCatalog;
use App\Domain\Automation\Application\Catalog\AutomationRuleType;
use App\Domain\Automation\Infrastructure\AutomationExecution;
use App\Domain\Automation\Infrastructure\AutomationReviewItem;
use App\Domain\Automation\Infrastructure\AutomationRuleInstance;
use App\Models\School;
use App\Models\User;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Tenancy\TenantContext;

/**
 * The Automation page's read model (ADR 0043 §6): requires
 * `automation.view` in the School. Returns the catalog rules with this
 * School's instance state, the School's opt-in state, the most recent
 * executions and review items -- identifiers, codes and timestamps, plus
 * the owner's display name (a staff account the viewer already works
 * with). `canManage` only decides which controls the page shows; every
 * mutation is authorized again by AutomationRuleService.
 */
class AutomationReadService
{
    use AuthorizesCapability;

    public const VIEW_CAPABILITY = 'automation.view';

    public const RECENT_LIMIT = 25;

    public function __construct(
        private readonly AutomationRuleCatalog $catalog,
        private readonly AutomationFeatureGate $gate,
        private readonly CapabilityResolver $capabilities,
        private readonly TenantContext $context,
    ) {}

    /** @return array<string, mixed> */
    public function overview(School $school, User $viewer): array
    {
        $this->authorizeCapabilityFor($viewer, self::VIEW_CAPABILITY, $school);

        return $this->context->withSchool($school, function () use ($school, $viewer): array {
            $instances = AutomationRuleInstance::query()->where('school_id', $school->id)->with('owner:id,name')->get()->keyBy('rule_type');

            $executions = AutomationExecution::query()
                ->where('school_id', $school->id)
                ->orderByDesc('created_at')->orderByDesc('id')
                ->limit(self::RECENT_LIMIT)
                ->get(['id', 'rule_instance_id', 'trigger_event_type', 'subject_type', 'subject_id', 'status', 'outcome_code', 'attempts', 'created_at', 'completed_at']);

            $items = AutomationReviewItem::query()
                ->where('school_id', $school->id)
                ->orderByDesc('created_at')->orderByDesc('id')
                ->limit(self::RECENT_LIMIT)
                ->get(['id', 'rule_instance_id', 'execution_id', 'item_type', 'subject_type', 'subject_id', 'created_at']);

            $ruleTypeByInstance = $instances->mapWithKeys(fn (AutomationRuleInstance $i) => [$i->id => $i->rule_type]);

            return [
                'schoolEnabled' => $this->gate->isEnabledFor($school),
                'canManage' => $this->capabilities->canInSchool($viewer, AutomationRuleService::MANAGE_CAPABILITY, $school),
                'viewerUserId' => $viewer->id,
                'rules' => array_map(function (AutomationRuleType $type) use ($instances): array {
                    $instance = $instances->get($type->key());

                    return [
                        'key' => $type->key(),
                        'label' => $type->label(),
                        'description' => $type->description(),
                        'triggerEventType' => $type->triggerEventType(),
                        'tier' => $type->tier(),
                        'requiredCapabilities' => $type->requiredCapabilities(),
                        'instance' => $instance === null ? null : [
                            'id' => $instance->id,
                            'status' => $instance->status,
                            'ownerUserId' => $instance->owner_user_id,
                            'ownerName' => $instance->owner?->name,
                            'enabledAt' => $instance->enabled_at?->toIso8601String(),
                            'suspendedAt' => $instance->suspended_at?->toIso8601String(),
                            'suspensionReason' => $instance->suspension_reason,
                        ],
                    ];
                }, $this->catalog->all()),
                'executions' => $executions->map(fn (AutomationExecution $e): array => [
                    'id' => $e->id,
                    'ruleType' => $ruleTypeByInstance->get($e->rule_instance_id),
                    'triggerEventType' => $e->trigger_event_type,
                    'subjectType' => $e->subject_type,
                    'subjectId' => $e->subject_id,
                    'status' => $e->status,
                    'outcomeCode' => $e->outcome_code,
                    'attempts' => $e->attempts,
                    'createdAt' => $e->created_at->toIso8601String(),
                    'completedAt' => $e->completed_at?->toIso8601String(),
                ])->all(),
                'reviewItems' => $items->map(fn (AutomationReviewItem $i): array => [
                    'id' => $i->id,
                    'ruleType' => $ruleTypeByInstance->get($i->rule_instance_id),
                    'itemType' => $i->item_type,
                    'subjectType' => $i->subject_type,
                    'subjectId' => $i->subject_id,
                    'executionId' => $i->execution_id,
                    'createdAt' => $i->created_at->toIso8601String(),
                ])->all(),
            ];
        });
    }
}

<?php

namespace App\Domain\Fees\Application;

use App\Domain\Fees\Application\Exceptions\FeeLateFeeRuleNotEditableException;
use App\Domain\Fees\Application\Exceptions\FeeLateFeeRuleNotFoundException;
use App\Domain\Fees\Application\Exceptions\InvalidFeeLateFeeRuleException;
use App\Domain\Fees\Domain\FeeAmount;
use App\Domain\Fees\Infrastructure\FeeHead;
use App\Domain\Fees\Infrastructure\FeeLateFeeRule;
use App\Domain\Fees\Infrastructure\FeeStructure;
use App\Domain\Fees\Infrastructure\FeeStructureLine;
use App\Domain\Finance\Application\LedgerAccountSummary;
use App\Domain\Finance\Application\LedgerService;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * FEE.5 (ADR 0062 §16.1; owner decision H): late-fee rule configuration.
 * Legal status: DEVELOPMENT AUTHORISED — PROD LEGAL SIGN-OFF REQUIRED (ADR
 * 0058 E31/E32); nothing here asserts that a rate, amount or grace period
 * is lawful.
 *
 * - Reads: `finance.fee_structures.view`; every write:
 *   `finance.fee_structures.manage` (Fee setup authority, no new
 *   capability).
 * - The model is exactly H: `fixed` (> 0) or `percentage` of current
 *   outstanding (0 < p <= 100, two decimals), `grace_days` >= 0, optional
 *   `max_amount` cap > 0; scope = one fee structure + optional fee head (a
 *   line of that structure); `late_fee_head_id` carries the accounts. No
 *   tiers, recurrence, interest or formula exists.
 * - Rules start inactive, are edited only while inactive, and are
 *   activated only when the late-fee head and its accounts are active.
 *   Every change bumps `configuration_version` (a run's preview goes
 *   stale). Never deleted. Audited: `late_fee_rule.created`, `.updated`,
 *   `.status_changed` (ids and codes only).
 */
class LateFeeRuleService
{
    use AuthorizesCapability;

    private const PERCENTAGE_PATTERN = '/^(0|[1-9]\d{0,2})(\.\d{1,2})?$/';

    public function __construct(
        private readonly LedgerService $ledger,
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
    ) {}

    /** @return Collection<int, FeeLateFeeRule> */
    public function list(School $school, User $actor): Collection
    {
        $this->authorizeCapabilityFor($actor, 'finance.fee_structures.view', $school);

        return $this->context->withSchool($school, fn () => FeeLateFeeRule::query()
            ->where('school_id', $school->id)
            ->orderBy('name')
            ->orderBy('id')
            ->get());
    }

    public function get(School $school, string $ruleId, User $actor): FeeLateFeeRule
    {
        $this->authorizeCapabilityFor($actor, 'finance.fee_structures.view', $school);

        return $this->context->withSchool($school, fn () => $this->find($school, $ruleId));
    }

    /** @param array<string, mixed> $data */
    public function create(School $school, array $data, User $actor): FeeLateFeeRule
    {
        $this->authorizeCapabilityFor($actor, 'finance.fee_structures.manage', $school);

        return $this->context->withSchool($school, fn () => DB::transaction(function () use ($school, $data, $actor) {
            $structureId = (string) ($data['fee_structure_id'] ?? '');
            if (! Str::isUuid($structureId) || ! FeeStructure::query()->where('school_id', $school->id)->whereKey($structureId)->exists()) {
                throw new InvalidFeeLateFeeRuleException('fee_structure_id', 'Choose a fee structure of this School.');
            }

            $rule = new FeeLateFeeRule;
            $rule->forceFill([
                'school_id' => $school->id,
                'fee_structure_id' => $structureId,
                'status' => FeeLateFeeRule::STATUS_INACTIVE,
                'currency' => 'INR',
                'created_by_user_id' => $actor->id,
                ...$this->validated($school, $structureId, $data),
            ])->save();

            $this->audit->school($school, 'late_fee_rule.created', actor: $actor, subject: $rule, metadata: [
                'lateFeeRuleId' => $rule->id,
                'feeStructureId' => $rule->fee_structure_id,
                'kind' => $rule->kind,
            ]);

            return $rule->refresh();
        }));
    }

    /** @param array<string, mixed> $data */
    public function update(School $school, string $ruleId, array $data, User $actor): FeeLateFeeRule
    {
        $this->authorizeCapabilityFor($actor, 'finance.fee_structures.manage', $school);

        return $this->context->withSchool($school, fn () => DB::transaction(function () use ($school, $ruleId, $data, $actor) {
            $rule = $this->find($school, $ruleId, lock: true);
            if ($rule->isActive()) {
                throw new FeeLateFeeRuleNotEditableException($rule->id);
            }

            $values = $this->validated($school, $rule->fee_structure_id, $data);
            $changed = array_keys(array_filter($values, fn ($value, $key) => (string) $value !== (string) $rule->getAttribute($key), ARRAY_FILTER_USE_BOTH));
            if ($changed === []) {
                return $rule;
            }

            $rule->forceFill([...$values, 'configuration_version' => $rule->configuration_version + 1])->save();

            $this->audit->school($school, 'late_fee_rule.updated', actor: $actor, subject: $rule, metadata: [
                'lateFeeRuleId' => $rule->id,
                'changedFields' => $changed,
            ]);

            return $rule->refresh();
        }));
    }

    public function activate(School $school, string $ruleId, User $actor): FeeLateFeeRule
    {
        return $this->setStatus($school, $ruleId, FeeLateFeeRule::STATUS_ACTIVE, $actor);
    }

    public function deactivate(School $school, string $ruleId, User $actor): FeeLateFeeRule
    {
        return $this->setStatus($school, $ruleId, FeeLateFeeRule::STATUS_INACTIVE, $actor);
    }

    /**
     * Trusted (no capability check): the rule as `App\Domain\Payments`' late-fee
     * run sees it; `$lock` takes it FOR SHARE for the caller's transaction,
     * so a concurrent edit or deactivation waits for the item to finish.
     */
    public function snapshot(School $school, string $ruleId, bool $lock = false): ?LateFeeRuleSnapshot
    {
        return $this->context->withSchool($school, function () use ($school, $ruleId, $lock) {
            $rule = Str::isUuid($ruleId)
                ? FeeLateFeeRule::query()->where('school_id', $school->id)->when($lock, fn ($q) => $q->sharedLock())->find($ruleId)
                : null;
            if ($rule === null) {
                return null;
            }

            $head = FeeHead::query()->where('school_id', $school->id)->when($lock, fn ($q) => $q->sharedLock())->findOrFail($rule->late_fee_head_id);

            return new LateFeeRuleSnapshot(
                ruleId: $rule->id,
                name: $rule->name,
                feeStructureId: $rule->fee_structure_id,
                feeHeadId: $rule->fee_head_id,
                lateFeeHeadId: $rule->late_fee_head_id,
                lateFeeHeadName: $head->name,
                graceDays: $rule->grace_days,
                kind: $rule->kind,
                fixedAmount: $rule->fixed_amount,
                percentage: $rule->percentage,
                maxAmount: $rule->max_amount,
                status: $rule->status,
                configurationVersion: $rule->configuration_version,
                receivableLedgerAccountId: $head->receivable_ledger_account_id,
                revenueLedgerAccountId: $head->revenue_ledger_account_id,
                accountsValid: $head->isActive()
                    && $this->isActiveAccount($school, 'asset', $head->receivable_ledger_account_id)
                    && $this->isActiveAccount($school, 'income', $head->revenue_ledger_account_id),
            );
        });
    }

    private function setStatus(School $school, string $ruleId, string $to, User $actor): FeeLateFeeRule
    {
        $this->authorizeCapabilityFor($actor, 'finance.fee_structures.manage', $school);

        return $this->context->withSchool($school, fn () => DB::transaction(function () use ($school, $ruleId, $to, $actor) {
            $rule = $this->find($school, $ruleId, lock: true);
            if ($rule->status === $to) {
                return $rule;
            }

            if ($to === FeeLateFeeRule::STATUS_ACTIVE && ! ($this->snapshot($school, $rule->id)->accountsValid ?? false)) {
                throw new InvalidFeeLateFeeRuleException('late_fee_head_id', 'The late-fee head and its receivable and revenue accounts must be active.');
            }

            $from = $rule->status;
            $rule->forceFill(['status' => $to, 'configuration_version' => $rule->configuration_version + 1])->save();

            $this->audit->school($school, 'late_fee_rule.status_changed', actor: $actor, subject: $rule, metadata: [
                'lateFeeRuleId' => $rule->id,
                'from' => $from,
                'to' => $to,
            ]);

            return $rule->refresh();
        }));
    }

    private function find(School $school, string $ruleId, bool $lock = false): FeeLateFeeRule
    {
        $rule = Str::isUuid($ruleId)
            ? FeeLateFeeRule::query()->where('school_id', $school->id)->when($lock, fn ($q) => $q->lockForUpdate())->find($ruleId)
            : null;

        return $rule ?? throw new FeeLateFeeRuleNotFoundException($ruleId);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validated(School $school, string $structureId, array $data): array
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 120) {
            throw new InvalidFeeLateFeeRuleException('name', 'Give the rule a name of at most 120 characters.');
        }

        $headId = ($data['fee_head_id'] ?? null) ?: null;
        if ($headId !== null && (! Str::isUuid((string) $headId) || ! FeeStructureLine::query()->where('fee_structure_id', $structureId)->where('fee_head_id', $headId)->exists())) {
            throw new InvalidFeeLateFeeRuleException('fee_head_id', 'Choose a fee head that is a line of this fee structure, or leave it empty for every line.');
        }

        $lateHeadId = (string) ($data['late_fee_head_id'] ?? '');
        if (! Str::isUuid($lateHeadId) || ! FeeHead::query()->where('school_id', $school->id)->whereKey($lateHeadId)->exists()) {
            throw new InvalidFeeLateFeeRuleException('late_fee_head_id', 'Choose the fee head that late fees are charged under.');
        }

        $grace = $data['grace_days'] ?? null;
        if (! is_int($grace) && ! (is_string($grace) && ctype_digit($grace))) {
            throw new InvalidFeeLateFeeRuleException('grace_days', 'Grace days must be a whole number of days, 0 or more.');
        }
        $grace = (int) $grace;
        if ($grace < 0 || $grace > 3650) {
            throw new InvalidFeeLateFeeRuleException('grace_days', 'Grace days must be between 0 and 3650.');
        }

        $kind = (string) ($data['kind'] ?? '');
        $fixed = null;
        $percentage = null;
        if ($kind === FeeLateFeeRule::KIND_FIXED) {
            if (! FeeAmount::isValidPositive($data['fixed_amount'] ?? null)) {
                throw new InvalidFeeLateFeeRuleException('fixed_amount', 'Enter an amount greater than zero with at most two decimal places.');
            }
            $fixed = FeeAmount::normalize((string) $data['fixed_amount']);
        } elseif ($kind === FeeLateFeeRule::KIND_PERCENTAGE) {
            $p = $data['percentage'] ?? null;
            if (! is_string($p) || preg_match(self::PERCENTAGE_PATTERN, $p) !== 1 || bccomp($p, '0', 2) <= 0 || bccomp($p, '100', 2) > 0) {
                throw new InvalidFeeLateFeeRuleException('percentage', 'Enter a percentage above 0 and at most 100, with at most two decimal places.');
            }
            $percentage = bcadd($p, '0', 2);
        } else {
            throw new InvalidFeeLateFeeRuleException('kind', 'Choose a fixed amount or a percentage of the outstanding balance.');
        }

        $cap = ($data['max_amount'] ?? null);
        $cap = $cap === '' ? null : $cap;
        if ($cap !== null) {
            if (! FeeAmount::isValidPositive($cap)) {
                throw new InvalidFeeLateFeeRuleException('max_amount', 'A cap must be an amount greater than zero, or empty for no cap.');
            }
            $cap = FeeAmount::normalize((string) $cap);
        }

        return [
            'name' => $name,
            'fee_head_id' => $headId,
            'late_fee_head_id' => $lateHeadId,
            'grace_days' => $grace,
            'kind' => $kind,
            'fixed_amount' => $fixed,
            'percentage' => $percentage,
            'max_amount' => $cap,
        ];
    }

    private function isActiveAccount(School $school, string $type, string $accountId): bool
    {
        return $this->ledger->activeAccountsOfType($school, $type)
            ->contains(fn (LedgerAccountSummary $a) => $a->ledgerAccountId === $accountId);
    }
}

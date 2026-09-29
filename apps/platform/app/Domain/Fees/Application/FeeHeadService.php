<?php

namespace App\Domain\Fees\Application;

use App\Domain\Fees\Application\Exceptions\DuplicateFeeHeadCodeException;
use App\Domain\Fees\Application\Exceptions\FeeHeadNotFoundException;
use App\Domain\Fees\Application\Exceptions\InvalidFeeHeadException;
use App\Domain\Fees\Domain\FeeCode;
use App\Domain\Fees\Infrastructure\FeeHead;
use App\Domain\Finance\Application\LedgerAccountSummary;
use App\Domain\Finance\Application\LedgerService;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * FEE.1 (ADR 0062 §5): the only write path for `fee_heads`.
 *
 * A fee head maps future charges to an ACTIVE `asset` receivable account
 * and an ACTIVE `income` revenue account of the same School, and the two
 * must differ. Account type and School are also database-enforced
 * (`fee_heads_account_type_trigger`, composite FKs); account STATUS is
 * checked here, through Finance's `LedgerService::activeAccountsOfType()`
 * (Fees never reads `ledger_accounts` itself), and again at every future
 * assessment (FEE.2).
 *
 * Codes are immutable once created. Mapping changes are audited with the
 * before/after account ids. Heads are deactivated, never deleted; a
 * reactivation re-validates the mapping. No tax classification exists
 * (ADR 0062 §17.5, [LEGAL REVIEW REQUIRED]).
 *
 * Authorization boundary: `finance.fee_structures.manage` on every call.
 */
class FeeHeadService
{
    use AuthorizesCapability;

    public function __construct(
        private readonly LedgerService $ledger,
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
    ) {}

    /**
     * @param  array{code: string, name: string, description?: string|null, receivable_ledger_account_id: string, revenue_ledger_account_id: string}  $data
     */
    public function create(School $school, array $data, User $actor): FeeHead
    {
        $this->authorizeCapabilityFor($actor, 'finance.fee_structures.manage', $school);

        if (! FeeCode::isValid($data['code'])) {
            throw new InvalidFeeHeadException('code', FeeCode::RULE_MESSAGE);
        }

        $this->assertValidName($data['name']);
        $this->assertValidMapping($school, $data['receivable_ledger_account_id'], $data['revenue_ledger_account_id']);

        return $this->context->withSchool($school, function () use ($school, $data, $actor) {
            return DB::transaction(function () use ($school, $data, $actor) {
                $head = new FeeHead;
                $head->forceFill([
                    'school_id' => $school->id,
                    'code' => $data['code'],
                    'name' => trim($data['name']),
                    'description' => $this->blankToNull($data['description'] ?? null),
                    'status' => FeeHead::STATUS_ACTIVE,
                    'receivable_ledger_account_id' => $data['receivable_ledger_account_id'],
                    'revenue_ledger_account_id' => $data['revenue_ledger_account_id'],
                    'currency' => 'INR',
                ]);

                try {
                    $head->save();
                } catch (UniqueConstraintViolationException $e) {
                    if (! str_contains($e->getMessage(), 'fee_heads_school_id_code_ci_unique')) {
                        throw $e;
                    }

                    throw new DuplicateFeeHeadCodeException($head->code);
                }

                $this->audit->school($school, 'fee_head.created', actor: $actor, subject: $head, metadata: [
                    'feeHeadId' => $head->id,
                    'code' => $head->code,
                    'receivableLedgerAccountId' => $head->receivable_ledger_account_id,
                    'revenueLedgerAccountId' => $head->revenue_ledger_account_id,
                ]);

                return $head->refresh();
            });
        });
    }

    /**
     * Updates the name, description and/or account mapping. Status changes
     * go through deactivate()/reactivate().
     *
     * @param  array{name?: string, description?: string|null, receivable_ledger_account_id?: string, revenue_ledger_account_id?: string}  $data
     */
    public function update(School $school, string $feeHeadId, array $data, User $actor): FeeHead
    {
        $this->authorizeCapabilityFor($actor, 'finance.fee_structures.manage', $school);

        return $this->context->withSchool($school, function () use ($school, $feeHeadId, $data, $actor) {
            return DB::transaction(function () use ($school, $feeHeadId, $data, $actor) {
                $head = $this->lock($school, $feeHeadId);

                $before = [
                    'receivableLedgerAccountId' => $head->receivable_ledger_account_id,
                    'revenueLedgerAccountId' => $head->revenue_ledger_account_id,
                ];

                $changed = [];

                if (array_key_exists('name', $data)) {
                    $this->assertValidName($data['name']);
                }

                if (array_key_exists('name', $data) && trim($data['name']) !== $head->name) {
                    $head->name = trim($data['name']);
                    $changed[] = 'name';
                }

                if (array_key_exists('description', $data) && $this->blankToNull($data['description']) !== $head->description) {
                    $head->description = $this->blankToNull($data['description']);
                    $changed[] = 'description';
                }

                $receivable = $data['receivable_ledger_account_id'] ?? $head->receivable_ledger_account_id;
                $revenue = $data['revenue_ledger_account_id'] ?? $head->revenue_ledger_account_id;
                $mappingChanged = $receivable !== $head->receivable_ledger_account_id
                    || $revenue !== $head->revenue_ledger_account_id;

                if ($mappingChanged) {
                    $this->assertValidMapping($school, $receivable, $revenue);
                    $head->receivable_ledger_account_id = $receivable;
                    $head->revenue_ledger_account_id = $revenue;
                    $changed[] = 'ledgerAccounts';
                }

                if ($changed === []) {
                    return $head;
                }

                $head->save();

                $metadata = ['feeHeadId' => $head->id, 'changedFields' => $changed];
                if ($mappingChanged) {
                    $metadata['before'] = $before;
                    $metadata['after'] = [
                        'receivableLedgerAccountId' => $head->receivable_ledger_account_id,
                        'revenueLedgerAccountId' => $head->revenue_ledger_account_id,
                    ];
                }

                $this->audit->school($school, 'fee_head.updated', actor: $actor, subject: $head, metadata: $metadata);

                return $head->refresh();
            });
        });
    }

    public function deactivate(School $school, string $feeHeadId, User $actor): FeeHead
    {
        $this->authorizeCapabilityFor($actor, 'finance.fee_structures.manage', $school);

        return $this->context->withSchool($school, function () use ($school, $feeHeadId, $actor) {
            return DB::transaction(function () use ($school, $feeHeadId, $actor) {
                $head = $this->lock($school, $feeHeadId);

                if (! $head->isActive()) {
                    return $head;
                }

                $head->forceFill(['status' => FeeHead::STATUS_INACTIVE])->save();

                $this->audit->school($school, 'fee_head.deactivated', actor: $actor, subject: $head, metadata: [
                    'feeHeadId' => $head->id,
                ]);

                return $head->refresh();
            });
        });
    }

    public function reactivate(School $school, string $feeHeadId, User $actor): FeeHead
    {
        $this->authorizeCapabilityFor($actor, 'finance.fee_structures.manage', $school);

        return $this->context->withSchool($school, function () use ($school, $feeHeadId, $actor) {
            return DB::transaction(function () use ($school, $feeHeadId, $actor) {
                $head = $this->lock($school, $feeHeadId);

                if ($head->isActive()) {
                    return $head;
                }

                $this->assertValidMapping($school, $head->receivable_ledger_account_id, $head->revenue_ledger_account_id);

                $head->forceFill(['status' => FeeHead::STATUS_ACTIVE])->save();

                $this->audit->school($school, 'fee_head.updated', actor: $actor, subject: $head, metadata: [
                    'feeHeadId' => $head->id,
                    'changedFields' => ['status'],
                ]);

                return $head->refresh();
            });
        });
    }

    private function lock(School $school, string $feeHeadId): FeeHead
    {
        $head = FeeHead::query()->where('school_id', $school->id)->lockForUpdate()->find($feeHeadId);

        if ($head === null) {
            throw new FeeHeadNotFoundException($feeHeadId);
        }

        return $head;
    }

    private function assertValidMapping(School $school, string $receivableId, string $revenueId): void
    {
        if ($receivableId === $revenueId) {
            throw new InvalidFeeHeadException('revenue_ledger_account_id', 'The receivable and revenue ledger accounts must be different.');
        }

        $assets = $this->ledger->activeAccountsOfType($school, 'asset')->map(fn (LedgerAccountSummary $a) => $a->ledgerAccountId);
        if (! $assets->contains($receivableId)) {
            throw new InvalidFeeHeadException('receivable_ledger_account_id', 'The receivable account must be an active asset account of this School.');
        }

        $income = $this->ledger->activeAccountsOfType($school, 'income')->map(fn (LedgerAccountSummary $a) => $a->ledgerAccountId);
        if (! $income->contains($revenueId)) {
            throw new InvalidFeeHeadException('revenue_ledger_account_id', 'The revenue account must be an active income account of this School.');
        }
    }

    private function assertValidName(string $name): void
    {
        $trimmed = trim($name);

        if ($trimmed === '' || mb_strlen($trimmed) > 120) {
            throw new InvalidFeeHeadException('name', 'A fee head name is required (at most 120 characters).');
        }
    }

    private function blankToNull(?string $value): ?string
    {
        $trimmed = $value === null ? null : trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}

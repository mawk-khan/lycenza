<?php

namespace App\Domain\Canteen\Application;

use App\Domain\Canteen\Application\Exceptions\CanteenBillingAccountInvalidException;
use App\Domain\Canteen\Application\Exceptions\CanteenBillingNotConfiguredException;
use App\Domain\Canteen\Infrastructure\CanteenBillingConfiguration;
use App\Domain\Finance\Infrastructure\LedgerAccount;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Thin CRUD-with-audit for the School-wide singleton billing
 * configuration (docs pattern: App\Domain\AcademicStructure\Application\AcademicYearService's
 * level of complexity), PLUS the one real invariant this checkpoint
 * needs shared between "save the configuration" and "fulfill an
 * Order": `resolveValidated()` is the SINGLE place account-type/
 * active/distinctness validation lives, called by both `configure()`
 * (eager validation at save time, better UX) and
 * `CanteenOrderService::fulfill()` (authoritative validation at
 * fulfillment time -- an account's status can change after the
 * configuration was saved, so fulfillment never trusts a stale
 * save-time check alone).
 *
 * Reads `App\Domain\Finance\Infrastructure\LedgerAccount` directly
 * (read-only) -- Canteen already depends on Finance (via
 * `App\Domain\Fees\Application\ChargeService::assess()`), and no
 * Finance Application-layer read service exposes account-type/status
 * validation in the shape this module needs; this mirrors
 * `App\Domain\Hostel`/`App\Domain\Library`'s own established
 * precedent of reading a depended-upon module's model directly for a
 * narrow, read-only purpose (never a write).
 */
class CanteenBillingConfigurationService
{
    public function __construct(
        private readonly AuditRecorder $audit,
    ) {}

    public function configure(School $school, string $receivableLedgerAccountId, string $revenueLedgerAccountId, ?User $actor = null): CanteenBillingConfiguration
    {
        return DB::transaction(function () use ($school, $receivableLedgerAccountId, $revenueLedgerAccountId, $actor) {
            $this->validateAccounts($school, $receivableLedgerAccountId, $revenueLedgerAccountId);

            try {
                $config = CanteenBillingConfiguration::query()->updateOrCreate(
                    ['school_id' => $school->id],
                    [
                        'receivable_ledger_account_id' => $receivableLedgerAccountId,
                        'revenue_ledger_account_id' => $revenueLedgerAccountId,
                        'currency' => 'INR',
                    ],
                );
            } catch (QueryException $e) {
                if (str_contains($e->getMessage(), 'canteen_billing_config_distinct_accounts_check')) {
                    throw new CanteenBillingAccountInvalidException('the receivable and revenue accounts must be different.');
                }

                throw $e;
            }

            $this->audit->school($school, 'canteen.billing_configuration.saved', actor: $actor, subject: $config, metadata: [
                'receivableLedgerAccountId' => $receivableLedgerAccountId,
                'revenueLedgerAccountId' => $revenueLedgerAccountId,
            ]);

            return $config;
        });
    }

    /**
     * The authoritative pre-fulfillment check: must exist, both
     * accounts must exist/be active/belong to this School/have the
     * correct type (asset for receivable, income for revenue)/differ
     * from each other. Returns the validated configuration row so the
     * caller never has to re-query it.
     */
    public function resolveValidated(School $school): CanteenBillingConfiguration
    {
        $config = CanteenBillingConfiguration::query()->where('school_id', $school->id)->first();

        if ($config === null) {
            throw new CanteenBillingNotConfiguredException;
        }

        $this->validateAccounts($school, $config->receivable_ledger_account_id, $config->revenue_ledger_account_id);

        return $config;
    }

    private function validateAccounts(School $school, string $receivableLedgerAccountId, string $revenueLedgerAccountId): void
    {
        if ($receivableLedgerAccountId === $revenueLedgerAccountId) {
            throw new CanteenBillingAccountInvalidException('the receivable and revenue accounts must be different.');
        }

        $receivable = LedgerAccount::query()->where('school_id', $school->id)->find($receivableLedgerAccountId);
        $revenue = LedgerAccount::query()->where('school_id', $school->id)->find($revenueLedgerAccountId);

        if ($receivable === null || $revenue === null) {
            throw new CanteenBillingAccountInvalidException('one or both ledger accounts could not be found for this School.');
        }

        if (! $receivable->isActive() || ! $revenue->isActive()) {
            throw new CanteenBillingAccountInvalidException('one or both ledger accounts are inactive.');
        }

        if ($receivable->type !== 'asset') {
            throw new CanteenBillingAccountInvalidException("the receivable account must be of type 'asset'.");
        }

        if ($revenue->type !== 'income') {
            throw new CanteenBillingAccountInvalidException("the revenue account must be of type 'income'.");
        }
    }
}

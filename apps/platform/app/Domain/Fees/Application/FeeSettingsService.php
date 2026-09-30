<?php

namespace App\Domain\Fees\Application;

use App\Domain\Fees\Application\Exceptions\InvalidFeeSettingsException;
use App\Domain\Fees\Application\Exceptions\ReceiptNumberingLockedException;
use App\Domain\Fees\Infrastructure\FeeSetting;
use App\Domain\Finance\Application\LedgerAccountSummary;
use App\Domain\Finance\Application\LedgerService;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * FEE.3/FEE.4 (ADR 0062 §14.5, §17.2; owner decisions F2, I): the
 * School-level Fees settings singleton -- the concession account and the
 * receipt numbering (prefix, financial-year start month). FEE.3 text: the
 * concession account -- one active INR `expense` account
 * ("Fee concessions and scholarships"), never inferred from a fee head.
 *
 * Setting it needs `finance.fee_structures.manage` (Fee setup authority);
 * reading it needs `finance.fee_structures.view`. Changing it never moves
 * posted history: each adjustment snapshots its own debit account.
 * `validConcessionAccountId()` is the trusted re-validation every posting
 * runs (F2: exists, same School, INR, active, `expense`; else NULL, and
 * the posting fails closed).
 *
 * Serialization: every settings mutation takes the School's
 * `fees.settings:{school}` transaction advisory lock EXCLUSIVE, then the
 * `fee_settings` row. Receipt issuance takes the same lock SHARED
 * (`receiptNumberingForIssuance()`) before it reads the numbering, so a
 * first receipt and a numbering change never pass each other (FEE closure
 * remediation): issuers never block each other, and whichever side commits
 * first is what the other sees.
 */
class FeeSettingsService
{
    use AuthorizesCapability;

    public function __construct(
        private readonly LedgerService $ledger,
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
    ) {}

    public function concessionAccountId(School $school, User $actor): ?string
    {
        $this->authorizeCapabilityFor($actor, 'finance.fee_structures.view', $school);

        return $this->configuredAccountId($school);
    }

    /** @return Collection<int, LedgerAccountSummary> the School's active INR expense accounts */
    public function concessionAccountOptions(School $school, User $actor): Collection
    {
        $this->authorizeCapabilityFor($actor, 'finance.fee_structures.view', $school);

        return $this->ledger->activeAccountsOfType($school, 'expense');
    }

    public function setConcessionAccount(School $school, string $ledgerAccountId, User $actor): FeeSetting
    {
        $this->authorizeCapabilityFor($actor, 'finance.fee_structures.manage', $school);

        return $this->context->withSchool($school, fn () => DB::transaction(function () use ($school, $ledgerAccountId, $actor) {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [self::lockKey($school)]);

            if (! $this->isActiveExpenseAccount($school, $ledgerAccountId)) {
                throw new InvalidFeeSettingsException('concession_ledger_account_id', 'Choose an active expense account of this School.');
            }

            $settings = FeeSetting::query()->where('school_id', $school->id)->lockForUpdate()->first()
                ?? new FeeSetting(['school_id' => $school->id, 'currency' => 'INR']);
            $before = $settings->concession_ledger_account_id;

            if ($before === $ledgerAccountId) {
                return $settings;
            }

            $settings->forceFill(['concession_ledger_account_id' => $ledgerAccountId])->save();

            $this->audit->school($school, 'fee_settings.concession_account_changed', actor: $actor, subject: $settings, metadata: [
                'before' => $before,
                'after' => $ledgerAccountId,
            ]);

            return $settings->refresh();
        }));
    }

    /**
     * FEE.4 (ADR 0062 §17.2): the School's effective receipt numbering.
     * Trusted (no capability check) -- `App\Domain\Payments`'
     * `ReceiptIssuer` reads it through this Application method, never the
     * `fee_settings` table (Payments -> Fees, the permitted direction).
     */
    public function receiptNumbering(School $school): ReceiptNumberingSettings
    {
        $row = $this->context->withSchool($school, fn () => FeeSetting::query()->where('school_id', $school->id)->first());

        return new ReceiptNumberingSettings(
            prefix: $row->receipt_prefix ?? ReceiptNumberingSettings::DEFAULT_PREFIX,
            financialYearStartMonth: $row->financial_year_start_month ?? ReceiptNumberingSettings::DEFAULT_START_MONTH,
        );
    }

    /**
     * FEE closure remediation: the numbering a receipt is ISSUED with.
     * Trusted (no capability check); only `ReceiptIssuer` calls it, inside
     * the caller's transaction. It takes the School's settings lock SHARED
     * -- held until that transaction ends -- and only then reads, so:
     *
     * - a numbering change that committed first is what the issuer uses
     *   (READ COMMITTED: the read after the lock is a fresh snapshot);
     * - a numbering change that starts while the issuer is open waits for
     *   it, and then its database guard sees the committed series and
     *   refuses a now-frozen prefix or start month.
     *
     * Without the lock the guard could not see an uncommitted first series,
     * so both could commit (the closure-audit race).
     */
    public function receiptNumberingForIssuance(School $school): ReceiptNumberingSettings
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('receiptNumberingForIssuance() must run inside the issuing transaction.');
        }

        DB::select('SELECT pg_advisory_xact_lock_shared(hashtextextended(?, 0))', [self::lockKey($school)]);

        return $this->receiptNumbering($school);
    }

    public function receiptNumberingFor(School $school, User $actor): ReceiptNumberingSettings
    {
        $this->authorizeCapabilityFor($actor, 'finance.fee_structures.view', $school);

        return $this->receiptNumbering($school);
    }

    /**
     * Sets the receipt prefix (uppercased; letters, digits and hyphens, at
     * most 16) and the financial-year start month (1-12). The database
     * refuses changing the prefix while the School's current series has
     * receipts, and the start month once any receipt exists
     * (`fee_settings_receipt_numbering_guard_trigger`, Payments-owned);
     * that refusal becomes `ReceiptNumberingLockedException`.
     */
    public function setReceiptNumbering(School $school, string $prefix, int $startMonth, User $actor): ReceiptNumberingSettings
    {
        $this->authorizeCapabilityFor($actor, 'finance.fee_structures.manage', $school);

        $prefix = strtoupper(trim($prefix));
        if (preg_match(ReceiptNumberingSettings::PREFIX_PATTERN, $prefix) !== 1) {
            throw new InvalidFeeSettingsException('receipt_prefix', 'Use 1-16 letters, digits or hyphens, starting with a letter or digit.');
        }
        if ($startMonth < 1 || $startMonth > 12) {
            throw new InvalidFeeSettingsException('financial_year_start_month', 'Choose a month from January to December.');
        }

        return $this->context->withSchool($school, fn () => DB::transaction(function () use ($school, $prefix, $startMonth, $actor) {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [self::lockKey($school)]);

            $settings = FeeSetting::query()->where('school_id', $school->id)->lockForUpdate()->first()
                ?? new FeeSetting(['school_id' => $school->id, 'currency' => 'INR']);
            $before = ['prefix' => $settings->receipt_prefix ?? ReceiptNumberingSettings::DEFAULT_PREFIX, 'month' => $settings->financial_year_start_month ?? ReceiptNumberingSettings::DEFAULT_START_MONTH];

            if ($before['prefix'] !== $prefix || $before['month'] !== $startMonth || ! $settings->exists) {
                try {
                    $settings->forceFill(['receipt_prefix' => $prefix, 'financial_year_start_month' => $startMonth])->save();
                } catch (QueryException $e) {
                    $message = $e->getMessage();
                    if (str_contains($message, 'the receipt prefix cannot change')) {
                        throw new ReceiptNumberingLockedException('The receipt prefix cannot change while this financial year\'s receipt series has receipts.');
                    }
                    if (str_contains($message, 'start month cannot change')) {
                        throw new ReceiptNumberingLockedException('The financial-year start month cannot change once receipts have been issued.');
                    }

                    throw $e;
                }

                $this->audit->school($school, 'fee_settings.receipt_numbering_changed', actor: $actor, subject: $settings, metadata: [
                    'before' => $before,
                    'after' => ['prefix' => $prefix, 'month' => $startMonth],
                ]);
            }

            return new ReceiptNumberingSettings($prefix, $startMonth);
        }));
    }

    /**
     * Trusted (no capability check): the stored settings, for a write
     * response the caller already authorized.
     *
     * @return array{concessionLedgerAccountId: string|null, receiptPrefix: string, financialYearStartMonth: int}
     */
    public function snapshot(School $school): array
    {
        $numbering = $this->receiptNumbering($school);

        return [
            'concessionLedgerAccountId' => $this->configuredAccountId($school),
            'receiptPrefix' => $numbering->prefix,
            'financialYearStartMonth' => $numbering->financialYearStartMonth,
        ];
    }

    /** Trusted (no capability check): the configured account id if it is still valid for posting, else null. */
    public function validConcessionAccountId(School $school): ?string
    {
        $id = $this->configuredAccountId($school);

        return $id !== null && $this->isActiveExpenseAccount($school, $id) ? $id : null;
    }

    /** The School's settings lock key: EXCLUSIVE for every mutation, SHARED for receipt issuance. */
    private static function lockKey(School $school): string
    {
        return "fees.settings:{$school->id}";
    }

    private function configuredAccountId(School $school): ?string
    {
        return $this->context->withSchool($school, fn () => FeeSetting::query()->where('school_id', $school->id)->value('concession_ledger_account_id'));
    }

    private function isActiveExpenseAccount(School $school, string $ledgerAccountId): bool
    {
        return $this->ledger->activeAccountsOfType($school, 'expense')
            ->contains(fn (LedgerAccountSummary $a) => $a->ledgerAccountId === $ledgerAccountId);
    }
}

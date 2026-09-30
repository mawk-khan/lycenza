<?php

namespace App\Domain\Fees\Application;

use App\Domain\Fees\Application\Exceptions\InvalidFeeSettingsException;
use App\Domain\Fees\Infrastructure\FeeSetting;
use App\Domain\Finance\Application\LedgerAccountSummary;
use App\Domain\Finance\Application\LedgerService;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * FEE.3 (ADR 0062 §14.5, owner decision F2): the School-level Fees settings
 * singleton's concession account -- one active INR `expense` account
 * ("Fee concessions and scholarships"), never inferred from a fee head.
 *
 * Setting it needs `finance.fee_structures.manage` (Fee setup authority);
 * reading it needs `finance.fee_structures.view`. Changing it never moves
 * posted history: each adjustment snapshots its own debit account.
 * `validConcessionAccountId()` is the trusted re-validation every posting
 * runs (F2: exists, same School, INR, active, `expense`; else NULL, and
 * the posting fails closed).
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
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ["fees.settings:{$school->id}"]);

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

    /** Trusted (no capability check): the configured account id if it is still valid for posting, else null. */
    public function validConcessionAccountId(School $school): ?string
    {
        $id = $this->configuredAccountId($school);

        return $id !== null && $this->isActiveExpenseAccount($school, $id) ? $id : null;
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

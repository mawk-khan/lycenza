<?php

namespace App\Domain\Finance\Application;

use App\Domain\Finance\Application\Exceptions\DuplicateLedgerAccountCodeException;
use App\Domain\Finance\Application\Exceptions\InvalidLedgerAccountException;
use App\Domain\Finance\Application\Exceptions\LedgerAccountNotFoundException;
use App\Domain\Finance\Infrastructure\LedgerAccount;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * FEE.1 (ADR 0062 §6, decision K1): the minimal, Finance-owned chart of
 * accounts administration a production School needs before any fee head
 * can map to a ledger account. Two operations only:
 *
 * - create an INR account with a code, name and one of the five account
 *   types (`ledger_accounts_type_check`);
 * - activate or deactivate it.
 *
 * Deliberately absent: delete (revoked from the runtime role), type,
 * currency or code changes (the database also refuses a type change once
 * an account has been posted to), system accounts, hierarchies and
 * templates. Posting does not start refusing inactive accounts here; that
 * remains FINANCE.md's deferred decision.
 *
 * Authorization boundary: `finance.accounts.manage`, checked on every
 * call. Reads stay on `LedgerReadService` (`finance.ledger.view`).
 */
class LedgerAccountAdministrationService
{
    use AuthorizesCapability;

    public const TYPES = ['asset', 'liability', 'equity', 'income', 'expense'];

    public const STATUSES = ['active', 'inactive'];

    private const CODE_PATTERN = '/^[A-Z0-9][A-Z0-9_.-]{0,31}$/';

    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
    ) {}

    /**
     * @param  array{code: string, name: string, type: string}  $data
     */
    public function create(School $school, array $data, User $actor): LedgerAccountSummary
    {
        $this->authorizeCapabilityFor($actor, 'finance.accounts.manage', $school);

        $code = strtoupper(trim($data['code']));
        $name = trim($data['name']);

        if (! preg_match(self::CODE_PATTERN, $code)) {
            throw new InvalidLedgerAccountException('code', 'An account code is 1-32 letters, digits, ".", "_" or "-", starting with a letter or digit.');
        }

        if ($name === '' || mb_strlen($name) > 120) {
            throw new InvalidLedgerAccountException('name', 'An account name is required (at most 120 characters).');
        }

        if (! in_array($data['type'], self::TYPES, true)) {
            throw new InvalidLedgerAccountException('type', 'An account type is one of: '.implode(', ', self::TYPES).'.');
        }

        return $this->context->withSchool($school, function () use ($school, $code, $name, $data, $actor) {
            return DB::transaction(function () use ($school, $code, $name, $data, $actor) {
                $account = new LedgerAccount;
                $account->forceFill([
                    'school_id' => $school->id,
                    'code' => $code,
                    'name' => $name,
                    'type' => $data['type'],
                    'currency' => 'INR', // ledger_accounts_currency_inr_only_check
                    'is_system' => false,
                    'status' => 'active',
                ]);

                try {
                    $account->save();
                } catch (UniqueConstraintViolationException $e) {
                    if (! str_contains($e->getMessage(), 'ledger_accounts_school_id_code_ci_unique')) {
                        throw $e;
                    }

                    throw new DuplicateLedgerAccountCodeException($code);
                }

                $this->audit->school($school, 'ledger_account.created', actor: $actor, subject: $account, metadata: [
                    'ledgerAccountId' => $account->id,
                    'code' => $account->code,
                    'type' => $account->type,
                ]);

                return LedgerAccountSummary::fromModel($account->refresh());
            });
        });
    }

    /**
     * Activating an active account (or deactivating an inactive one) is a
     * no-op: nothing changes and nothing is audited.
     */
    public function changeStatus(School $school, string $ledgerAccountId, string $status, User $actor): LedgerAccountSummary
    {
        $this->authorizeCapabilityFor($actor, 'finance.accounts.manage', $school);

        if (! in_array($status, self::STATUSES, true)) {
            throw new InvalidLedgerAccountException('status', 'An account status is active or inactive.');
        }

        return $this->context->withSchool($school, function () use ($school, $ledgerAccountId, $status, $actor) {
            return DB::transaction(function () use ($school, $ledgerAccountId, $status, $actor) {
                $account = LedgerAccount::query()
                    ->where('school_id', $school->id)
                    ->lockForUpdate()
                    ->find($ledgerAccountId);

                if ($account === null) {
                    throw new LedgerAccountNotFoundException($ledgerAccountId);
                }

                if ($account->status === $status) {
                    return LedgerAccountSummary::fromModel($account);
                }

                $from = $account->status;
                $account->forceFill(['status' => $status])->save();

                $this->audit->school($school, 'ledger_account.status_changed', actor: $actor, subject: $account, metadata: [
                    'ledgerAccountId' => $account->id,
                    'from' => $from,
                    'to' => $status,
                ]);

                return LedgerAccountSummary::fromModel($account->refresh());
            });
        });
    }
}

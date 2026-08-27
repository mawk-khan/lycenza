<?php

namespace App\Domain\Finance\Application\Exceptions;

/**
 * Raised uniformly whether a referenced ledger account id genuinely
 * does not exist OR exists only in a different School --
 * `LedgerService`'s account lookup is already scoped by
 * `App\Support\Tenancy\SchoolScope` (via TenantContext), so a
 * cross-School id is simply absent from the resolved set, identical to
 * a nonexistent one. This is deliberate: the message never reveals
 * which case occurred, so a caller cannot use repeated posting
 * attempts to probe whether an account id exists in another School
 * (CLAUDE.md's "no oracle" principle, same as every other cross-School
 * lookup in this repository).
 */
class LedgerAccountNotFoundException extends FinanceException
{
    public function __construct(string $ledgerAccountId)
    {
        parent::__construct(404, 'LEDGER_ACCOUNT_NOT_FOUND', "Ledger account '{$ledgerAccountId}' was not found.");
    }
}

<?php

namespace App\Domain\Payroll\Application;

use App\Domain\Payroll\Infrastructure\PayrollRun;
use App\Domain\Payroll\Infrastructure\PayrollRunPosting;
use App\Models\User;
use App\Support\Authorization\AuthorizesCapability;

/**
 * Phase 9.7 -- the authorized ADMINISTRATIVE entry point for posting
 * and reversing Payroll runs, mirroring
 * `App\Domain\Finance\Application\LedgerAdministrationService`'s exact
 * per-method distinct-capability shape (its own trusted core,
 * `PayrollPostingService`, performs no capability check itself -- see
 * that class's own docblock). No transport may call
 * `PayrollPostingService` directly; a future controller depends on
 * this class.
 *
 * `payroll.runs.post` and `payroll.runs.reverse` are deliberately
 * SEPARATE capabilities (ADR 0032 "Separation of duties"), mirroring
 * `finance.ledger.post`/`.reverse`'s identical "reversal is a
 * materially higher-risk financial correction action" reasoning.
 */
class PayrollPostingAdministrationService
{
    use AuthorizesCapability;

    public function __construct(private readonly PayrollPostingService $posting) {}

    public function post(PayrollRun $run, User $actor): PayrollRunPosting
    {
        $this->authorizeCapabilityFor($actor, 'payroll.runs.post', $run->school);

        return $this->posting->post($run, $actor);
    }

    public function reverse(PayrollRun $run, User $actor, ?string $reason = null): PayrollRunPosting
    {
        $this->authorizeCapabilityFor($actor, 'payroll.runs.reverse', $run->school);

        return $this->posting->reverse($run, $actor, $reason);
    }
}

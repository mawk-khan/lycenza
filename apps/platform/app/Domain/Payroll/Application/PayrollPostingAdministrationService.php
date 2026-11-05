<?php

namespace App\Domain\Payroll\Application;

use App\Domain\Payroll\Infrastructure\PayrollRun;
use App\Domain\Payroll\Infrastructure\PayrollRunPosting;
use App\Models\ApiIdempotencyKey;
use App\Models\User;
use App\Support\Auth\Mfa\FreshMfaProof;
use App\Support\Authorization\AuthorizesCapability;

/**
 * Phase 9.7 -- the authorized ADMINISTRATIVE entry point for posting
 * and reversing Payroll runs, mirroring
 * `App\Domain\Finance\Application\LedgerAdministrationService`'s exact
 * per-method distinct-capability shape (its own trusted core,
 * `PayrollPostingService`, since HR.R1-C1 (S-13) ALSO re-reads the
 * actor's current capability under the run lock and requires a fresh-MFA
 * proof (S-5) -- this class's cached check stays the early refusal). No transport may call
 * `PayrollPostingService` directly; a future controller depends on
 * this class.
 *
 * `payroll.runs.post` and `payroll.runs.reverse` are deliberately
 * SEPARATE capabilities (ADR 0034 "Separation of duties"), mirroring
 * `finance.ledger.post`/`.reverse`'s identical "reversal is a
 * materially higher-risk financial correction action" reasoning.
 */
class PayrollPostingAdministrationService
{
    use AuthorizesCapability;

    public function __construct(private readonly PayrollPostingService $posting) {}

    /**
     * `$idempotencyRecord`, when supplied, is threaded straight through
     * to `PayrollPostingService::post()`'s own `completeWithin()`
     * handling. Authorization-before-replay is guaranteed at the ROUTE
     * level, not here: `routes/api.php` places `capability:payroll.runs.post`
     * BEFORE `idempotent` in this route's middleware array, so a
     * revoked actor is rejected by `App\Http\Middleware\EnsureCapability`
     * before `App\Http\Middleware\EnsureIdempotent` ever runs -- a
     * cached successful response can never be replayed to an actor who
     * has since lost the capability. This method's own
     * `authorizeCapabilityFor()` call below is the SAME defense-in-depth
     * double-check every Administration service already performs
     * regardless of idempotency, not the primary guarantee.
     */
    public function post(PayrollRun $run, User $actor, FreshMfaProof $mfa, ?ApiIdempotencyKey $idempotencyRecord = null): PayrollRunPosting
    {
        $this->authorizeCapabilityFor($actor, 'payroll.runs.post', $run->school);

        return $this->posting->post($run, $actor, $mfa, $idempotencyRecord);
    }

    public function reverse(PayrollRun $run, User $actor, FreshMfaProof $mfa, ?string $reason = null, ?ApiIdempotencyKey $idempotencyRecord = null): PayrollRunPosting
    {
        $this->authorizeCapabilityFor($actor, 'payroll.runs.reverse', $run->school);

        return $this->posting->reverse($run, $actor, $mfa, $reason, $idempotencyRecord);
    }
}

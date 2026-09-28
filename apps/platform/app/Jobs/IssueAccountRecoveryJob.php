<?php

namespace App\Jobs;

use App\Domain\Identity\Application\AccountRecovery\AccountRecoveryIssuer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Phase 0O.10A (ADR 0056 section 5.2): issuance happens OFF the request path,
 * so the recovery request's response time never depends on the account.
 * The payload is the canonical email only, and it is ENCRYPTED in the queue
 * backend and `failed_jobs` (ShouldBeEncrypted). Identity-level: no School
 * context, no School lifecycle effect (recovery is not a School operation).
 *
 * `$tries = 1`: an issuance is never retried blindly (a lost one is simply a
 * request the person repeats); `$timeout = 30` < `retry_after`.
 */
class IssueAccountRecoveryJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(public readonly string $email) {}

    public function handle(AccountRecoveryIssuer $issuer): void
    {
        $issuer->issue($this->email);
    }
}

<?php

namespace App\Jobs;

use App\Models\School;
use App\Support\Email\EmailSubmissionService;
use App\Support\Email\PlatformEmailScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Phase 0O.9A (ADR 0055 section 10): ONE submission attempt of one email
 * message. The payload is two ids -- never the recipient, subject or body
 * (nothing sealed can reach `jobs`/`failed_jobs`).
 *
 * `$tries = 1`: the message's own state (`next_attempt_at`, attempts) owns
 * every retry (rule 59); `$timeout = 30` stays well below the queues'
 * 90 s `retry_after` (rule 58) and below the 120 s claim lease.
 * EmailSubmissionService::claim() re-checks the School lifecycle through
 * SchoolOperationalGuard at execution time (ADR 0047); an identity-level
 * message has no School (ADR 0056) and runs in PlatformEmailScope.
 */
class SubmitEmailMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(
        public readonly ?string $schoolId,
        public readonly string $messageId,
    ) {}

    public function handle(EmailSubmissionService $submissions, TenantContext $context, PlatformEmailScope $platform): void
    {
        // ADR 0056 section 9.3: an identity-level message (no School) is
        // processed in the platform email scope, never a School context.
        if ($this->schoolId === null) {
            $platform->run(fn () => $submissions->process($this->messageId));

            return;
        }

        $school = School::query()->find($this->schoolId);

        if ($school === null) {
            return;
        }

        // withSchool(): the School context ends with this unit of work (in a
        // worker there is no previous context, so it ends cleared -- rule 57),
        // and a synchronous nested run (the sync queue) restores its caller's.
        $context->withSchool($school, fn () => $submissions->process($this->messageId));
    }
}

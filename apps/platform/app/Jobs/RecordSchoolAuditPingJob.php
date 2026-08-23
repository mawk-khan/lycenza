<?php

namespace App\Jobs;

use App\Support\Audit\AuditRecorder;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantScoped;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Phase 0B primitive proving tenant-aware queue context propagation
 * (section 25) end to end: dispatch -> job payload carries school_id
 * explicitly -> SetTenantContextForJob middleware initializes
 * TenantContext (and the RLS session variable) -> this job writes a
 * School-scoped audit event using TenantContext::requireSchool()
 * (fails loudly if context is somehow missing) -> context is cleared.
 * Not a business feature -- deliberately trivial.
 */
class RecordSchoolAuditPingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, TenantScoped;

    // Phase 0C.4 section 23/24: every job declares $tries/$timeout
    // explicitly rather than relying on Laravel's implicit defaults, and
    // $timeout stays well under the queue connection's retry_after
    // (90s, config/queue.php) so a stalled worker is never re-picked-up
    // by a second worker while the first is still legitimately running
    // -- see docs/architecture/RELIABILITY.md "Job timeout invariants".
    public int $tries = 3;

    public int $timeout = 15;

    public function __construct(private readonly string $note = 'ping')
    {
        $this->captureTenantContext();
    }

    public function handle(TenantContext $context, AuditRecorder $audit): void
    {
        $school = $context->requireSchool();

        $audit->school($school, 'diagnostics.ping', metadata: ['note' => $this->note]);
    }
}

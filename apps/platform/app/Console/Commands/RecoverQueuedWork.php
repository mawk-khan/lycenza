<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Phase 0O.4A (ADR 0050 section 10): the operator entry point for
 * recovering durable work after a Redis/queue loss (e.g. after restoring
 * or replacing Redis). A thin orchestrator: each source-owned command
 * decides, from PostgreSQL, what still needs processing -- nothing here
 * knows a domain's state machine:
 *
 * - platform:outbox-dispatch -- pending events, and `dispatched` events
 *   never acknowledged (OutboxReconciler, receipts-driven);
 * - platform:webhook-deliveries-redispatch -- due retries, expired leases,
 *   `pending` deliveries unclaimed for a processing lease;
 * - platform:communication-deliveries-redispatch -- the same for
 *   Communication deliveries;
 * - automation:executions-redispatch -- Automation already recovers
 *   (pending executions carry a `next_attempt_at` grace).
 *
 * All four also run every minute from the scheduler, so recovery needs no
 * operator in normal operation; each is bounded, idempotent and
 * duplicate-safe. Work becomes eligible only once its staleness window has
 * passed (a lease, or the outbox job's full retry lifecycle), so work that
 * is legitimately still queued is not raced.
 */
class RecoverQueuedWork extends Command
{
    protected $signature = 'platform:recover-queued-work {--batch=100 : Maximum rows per source (per School where source-owned) per run}';

    protected $description = 'Re-dispatch durable work whose queued jobs were lost (PostgreSQL-driven, idempotent).';

    /** @var list<string> */
    public const SOURCES = [
        'platform:outbox-dispatch',
        'platform:webhook-deliveries-redispatch',
        'platform:communication-deliveries-redispatch',
        'automation:executions-redispatch',
    ];

    public function handle(): int
    {
        $status = self::SUCCESS;

        foreach (self::SOURCES as $command) {
            $code = $this->call($command, ['--batch' => (int) $this->option('batch')]);
            $status = $code === self::SUCCESS ? $status : self::FAILURE;
        }

        return $status;
    }
}

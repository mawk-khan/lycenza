<?php

namespace App\Console\Commands;

use App\Jobs\ProcessOutboxEventJob;
use App\Support\Events\OutboxReconciler;
use App\Support\Observability\ErrorReporter;
use App\Support\Observability\QueueName;
use App\Support\Observability\RecoveryMetrics;
use App\Support\Observability\SafeException;
use App\Support\Observability\SchedulerHeartbeatRecorder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Reliable outbox dispatcher (section 9). Safe for concurrent
 * execution: `SELECT ... FOR UPDATE SKIP LOCKED` (Postgres-native row
 * locking) means two dispatcher processes running this command at the
 * same time simply pick DIFFERENT rows -- neither blocks on the other,
 * and neither can double-dispatch the same row. The DB status update
 * and the queue push happen in the same transaction via `afterCommit`
 * (Laravel only actually pushes to Redis after the transaction
 * commits), so a mid-transaction crash never leaves a "dispatched but
 * not actually queued" or "queued but not marked dispatched" row.
 *
 * `withoutOverlapping` at the Schedule level (see routes/console.php)
 * is a SEPARATE, coarser safety net for the scheduled invocation
 * specifically -- this command's own FOR UPDATE SKIP LOCKED logic is
 * what actually makes concurrent runs safe (proven in
 * OutboxDispatcherConcurrencyTest), the schedule lock just avoids
 * routinely running two overlapping instances for no reason.
 */
class DispatchOutboxEvents extends Command
{
    protected $signature = 'platform:outbox-dispatch {--batch=100 : Maximum rows to claim per run}';

    protected $description = 'Dispatch pending domain_event_outbox rows into the queue.';

    public function handle(SchedulerHeartbeatRecorder $heartbeats, OutboxReconciler $reconciler, RecoveryMetrics $recoveryMetrics): int
    {
        $batchSize = (int) $this->option('batch');
        $reconcileStartedAt = null;
        $dispatched = 0;
        $recovered = ['redispatched' => 0, 'acknowledged' => 0, 'failed' => 0];

        try {
            $dispatched = DB::transaction(function () use ($batchSize) {
                $rows = DB::table('domain_event_outbox')
                    ->where('status', 'pending')
                    ->where('available_at', '<=', now())
                    ->orderBy('occurred_at')
                    ->limit($batchSize)
                    ->lock('for update skip locked')
                    ->get(['id']);

                foreach ($rows as $row) {
                    DB::table('domain_event_outbox')->where('id', $row->id)->update([
                        'status' => 'dispatched',
                        'dispatched_at' => now(),
                        'attempts' => DB::raw('attempts + 1'),
                        'updated_at' => now(),
                    ]);

                    ProcessOutboxEventJob::dispatch($row->id)
                        ->onQueue(QueueName::Default->value)
                        ->afterCommit();
                }

                return $rows->count();
            });

            // Phase 0O.4A (ADR 0050 section 10): recover events whose queued
            // job was lost (Redis loss), decided from PostgreSQL receipts.
            $reconcileStartedAt = microtime(true);
            $recovered = $reconciler->reconcile($batchSize);
            $recoveryMetrics->record('outbox', true, $reconcileStartedAt, [
                'inspected' => array_sum($recovered), ...$recovered,
            ]);
            $reconcileStartedAt = null;

            $heartbeats->recordSuccess('outbox-dispatch');
        } catch (\Throwable $e) {
            if ($reconcileStartedAt !== null) {
                $recoveryMetrics->record('outbox', false, $reconcileStartedAt);
            }
            $heartbeats->recordFailure('outbox-dispatch', SafeException::code($e));
            app(ErrorReporter::class)->report($e, 'platform.outbox_dispatch.failed', 'scheduler', 'outbox-dispatch');
            $this->error('Outbox dispatch failed ('.SafeException::code($e).').');

            return self::FAILURE;
        }

        $this->info("Dispatched {$dispatched} outbox event(s); recovered {$recovered['redispatched']}, acknowledged {$recovered['acknowledged']}, failed {$recovered['failed']}.");
        Log::info('platform.outbox_dispatch.completed', ['dispatched' => $dispatched, ...$recovered]);

        return self::SUCCESS;
    }
}

<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Phase 0C.4 (section 15/16): both commands below are ALREADY safe
// under genuinely concurrent execution by construction -- Postgres
// `FOR UPDATE SKIP LOCKED` (see each command's own docblock), not this
// schedule-level lock. `withoutOverlapping()` here is a separate,
// coarser efficiency safeguard for the SCHEDULED invocation
// specifically: it stops a slow run from piling up a second
// overlapping run on the SAME server one minute later, which would
// otherwise just be wasted duplicate work (SKIP LOCKED means the
// second run would find nothing left to claim, not corrupt anything).
// `onOneServer()` is deliberately NOT added -- there is no multi-server
// deployment yet, and when one exists, running this on every server is
// still correct (just redundant), so `onOneServer()` is a future
// efficiency upgrade, not a correctness requirement, per section 15's
// "do not blindly add both to everything."
Schedule::command('platform:outbox-dispatch')
    ->everyMinute()
    ->withoutOverlapping()
    ->name('outbox-dispatch');

Schedule::command('platform:webhook-deliveries-redispatch')
    ->everyMinute()
    ->withoutOverlapping()
    ->name('webhook-deliveries-redispatch');

// Phase 5A.3 (brief §21): same reasoning as the two commands above --
// App\Jobs\ProcessCommunicationDeliveryJob's own atomic claim() makes
// this safe under genuinely concurrent execution by construction, so
// withoutOverlapping() here is purely a coarser efficiency safeguard,
// not a correctness requirement.
Schedule::command('platform:communication-deliveries-redispatch')
    ->everyMinute()
    ->withoutOverlapping()
    ->name('communication-deliveries-redispatch');

// Phase 5A.4 (brief §20/§21): same reasoning again --
// AnnouncementService::publish()'s own atomic conditional UPDATE is
// the real concurrency guarantee (see its docblock), so
// withoutOverlapping() here is purely a coarser efficiency safeguard
// against a slow run piling up a second overlapping one, not a
// correctness requirement.
Schedule::command('communications:publish-scheduled')
    ->everyMinute()
    ->withoutOverlapping()
    ->name('communications-publish-scheduled');

// Phase 0L.6 (ADR 0043 §7): Automation execution retries and crashed-lease
// recovery. RunAutomationExecutionJob's own lease claim is the correctness
// guarantee; withoutOverlapping() is an efficiency safeguard only.
Schedule::command('automation:executions-redispatch')
    ->everyMinute()
    ->withoutOverlapping()
    ->name('automation-executions-redispatch');

// Phase 0N.3 (ADR 0044 section 10): record expiry of platform School
// elevations whose session went away without another request. The
// conditional finish in SchoolElevationService is the correctness
// guarantee; withoutOverlapping() is an efficiency safeguard only.
Schedule::command('platform:expire-school-elevations')
    ->everyMinute()
    ->withoutOverlapping()
    ->name('expire-school-elevations');

// Phase 0C closeout: the two retention prunes run daily. Neither records
// a scheduler heartbeat -- OperationalStatusService judges heartbeats
// against one minute-scale staleness threshold, which a daily task would
// always breach; each command logs its own structured result instead.
//
// platform:idempotency-prune -- expired api_idempotency_keys (TTL
// idempotency.default_ttl_hours, 48h), deferred by Phase 0C.2 until
// "the rest of Phase 0C's operational-safety work"
// (docs/architecture/RELIABILITY.md "Expiration and pruning").
Schedule::command('platform:idempotency-prune')
    ->dailyAt('02:10')
    ->withoutOverlapping()
    ->name('idempotency-prune');

// platform:webhook-deliveries-prune -- terminal webhook delivery history
// older than WEBHOOKS_DELIVERY_RETENTION_DAYS. That setting has no
// default (no retention period decided, [LEGAL REVIEW REQUIRED]), so
// until it is configured this run deletes nothing and logs
// `webhooks.deliveries_prune.skipped`.
Schedule::command('platform:webhook-deliveries-prune')
    ->dailyAt('02:20')
    ->withoutOverlapping()
    ->name('webhook-deliveries-prune');

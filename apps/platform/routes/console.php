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

// Phase 0O.9A (ADR 0055 section 10): the email layer's recovery sweep --
// due/abandoned messages, content expiry, unapplied provider events. The
// submission job's own claim is the correctness guarantee.
Schedule::command('platform:email-messages-redispatch')
    ->everyMinute()
    ->withoutOverlapping()
    ->name('email-messages-redispatch');

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
// Phase 0O.5A (ADR 0051 §11): one no-op canary per required worker queue
// every minute -- the worker-class heartbeat that distinguishes an idle
// queue from a dead worker.
Schedule::command('platform:dispatch-worker-canaries')
    ->everyMinute()
    ->withoutOverlapping()
    ->name('worker-canaries');

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

// E21-D4 / E21-D13 (docs/security/E21-RETENTION-DETERMINATION.md,
// project-adopted, pending legal ratification): processed outbox rows
// (OUTBOX_RETENTION_DAYS) and failed jobs (FAILED_JOBS_RETENTION_DAYS).
// Neither has a default; until configured each run deletes nothing and
// logs `retention.*.unconfigured`.
Schedule::command('platform:outbox-prune')
    ->dailyAt('02:40')
    ->withoutOverlapping()
    ->name('outbox-prune');

Schedule::command('platform:failed-jobs-prune')
    ->dailyAt('02:50')
    ->withoutOverlapping()
    ->name('failed-jobs-prune');

// E21.2B (E21-D1/D2/D6, project-adopted, pending legal ratification): audit,
// released suppressions and authority history expire through the narrow
// database retention functions (RetentionExpiry). The runtime role still
// holds no DELETE on those ledgers, so the ordinary scheduler needs no
// elevated credentials. Audit runs before authority history, so elevations
// whose audit events have expired become eligible the same night. Each has
// no default: until configured it deletes nothing.
Schedule::command('platform:audit-prune')
    ->dailyAt('03:00')
    ->withoutOverlapping()
    ->name('audit-prune');

Schedule::command('platform:email-suppressions-prune')
    ->dailyAt('03:10')
    ->withoutOverlapping()
    ->name('email-suppressions-prune');

Schedule::command('platform:authority-history-prune')
    ->dailyAt('03:20')
    ->withoutOverlapping()
    ->name('authority-history-prune');

// E21.2C (E21-D3/D5, project-adopted, pending legal ratification):
// Communications content and delivery telemetry, then proven orphan
// objects. The orphan run comes after, so attachment bytes whose delete
// failed during the Communications purge are retried the same night.
// Neither has a default: until configured each deletes nothing.
Schedule::command('platform:communications-prune')
    ->dailyAt('03:40')
    ->withoutOverlapping()
    ->name('communications-prune');

Schedule::command('platform:storage-orphans-prune')
    ->dailyAt('04:10')
    ->withoutOverlapping()
    ->name('storage-orphans-prune');

// E21.3B (E21.2G I2): ended portal invitations, PORTAL_INVITATION_RETENTION_DAYS
// (7) after they ended. No default: until configured it deletes nothing. A
// Student-subject one ended long enough ago no longer keeps its Student, but
// correctness does not depend on running before the Student run.
Schedule::command('platform:portal-invitations-prune')
    ->dailyAt('04:20')
    ->withoutOverlapping()
    ->name('portal-invitations-prune');

// E21.2D (E21-D7): Student operational history (7 y) and core academic
// record (25 y) after final exit. No default: until configured it deletes
// nothing. It runs after the Communications and orphan runs. Correctness
// does not depend on the order: each purge rechecks under its own lock.
Schedule::command('platform:student-retention-prune')
    ->dailyAt('04:30')
    ->withoutOverlapping()
    ->name('student-retention-prune');

// E21.3C (E21.2G AD2/G1): rejected/withdrawn applications 1 y after their
// terminal decision, and Guardian personal data 1 y after the Guardian last
// had a Student relationship. No default: until configured each deletes
// nothing. Each unit rechecks under its own lock, so the order relative to
// the Student run (which may end a Guardian's last relationship) does not
// matter: the Guardian clock starts only when that commits.
Schedule::command('platform:admissions-retention-prune')
    ->dailyAt('04:35')
    ->withoutOverlapping()
    ->name('admissions-retention-prune');

Schedule::command('platform:guardian-retention-prune')
    ->dailyAt('04:40')
    ->withoutOverlapping()
    ->name('guardian-retention-prune');

// E21.3D (E21.2G A1): year-bound academic operations, 7 y after their Academic
// Year ended. No default: until configured it deletes nothing. It runs after
// the Student run (which removes the attendance records that keep a register
// header) and before the Employee run (which these rows' teacher references
// may keep), but every unit rechecks under its own lock, so the order only
// decides how soon a released row goes, never whether it may.
Schedule::command('platform:academic-retention-prune')
    ->dailyAt('04:45')
    ->withoutOverlapping()
    ->name('academic-retention-prune');

// E21.3E (E21.2G O2-O4): ended driver assignments (7 y), checked-out visits
// (1 y) and completed automation executions (1 y). No default: an unset
// period skips its category. It runs before the Employee run (driver and
// host references may keep an Employee), but each unit rechecks under its
// own lock, so the order never decides whether a row may go.
Schedule::command('platform:operations-retention-prune')
    ->dailyAt('04:48')
    ->withoutOverlapping()
    ->name('operations-retention-prune');

// E21.2E (E21-D9): ancillary HR details (2 y) and employment/payroll
// evidence (8 y) after final separation. No default: until configured it
// deletes nothing. Each purge rechecks under its own lock, so correctness
// does not depend on the order.
// E21-RH.2 (ADR 0066 §10): its HRX participants run as the dedicated
// retention identity on pgsql_retention (DB_RETENTION_*, the scheduler's
// database_retention secret group) -- never pgsql_admin. Without that
// credential they refuse (errors, nothing deleted) while the rest runs.
Schedule::command('platform:employee-retention-prune')
    ->dailyAt('04:50')
    ->withoutOverlapping()
    ->name('employee-retention-prune');

// E21.3A2 (E21-D8, ADR 0064): settled Finance detail of periods closed
// >= FINANCE_RETENTION_YEARS (8) ago. Off unless FINANCE_RETENTION_ENABLED;
// until then it is a clean no-op (the heartbeat still proves it runs).
// Each unit rechecks under its own lock and accounting check, so
// correctness does not depend on the order relative to the other runs.
Schedule::command('platform:finance-retention-prune')
    ->dailyAt('05:10')
    ->withoutOverlapping()
    ->name('finance-retention-prune');

// E21.3F (E21-D9 x E21-D8): posted payroll evidence, EMPLOYEE_EVIDENCE_RETENTION_YEARS
// (at least 8) after final separation, then emptied payroll runs (releasing
// their journal entries to Finance). Unset deletes nothing. It runs after
// the Employee and Finance runs: what it releases they re-evaluate the next
// day, and each unit rechecks under its own locks, so correctness never
// depends on the order.
Schedule::command('platform:payroll-retention-prune')
    ->dailyAt('05:20')
    ->withoutOverlapping()
    ->name('payroll-retention-prune');

// Phase 0O.10A (ADR 0056 section 13): ended password-recovery credentials
// are deleted 24 hours later (technical data; the audit is separate).
Schedule::command('platform:account-recovery-prune')
    ->hourly()
    ->withoutOverlapping()
    ->name('account-recovery-prune');

// Phase 0O.12B (ADR 0059 section 21): ended bootstrap activation credentials
// (24 h) and ended staff invitations (7 days) -- technical cleanup; the audit
// ledgers are the record and are never touched.
Schedule::command('platform:staff-account-credentials-prune')
    ->hourly()
    ->withoutOverlapping()
    ->name('staff-account-credentials-prune');

// Phase 0O.9A (ADR 0055 section 21): email metadata retention,
// MAIL_RETENTION_DAYS -- no default ([LEGAL REVIEW REQUIRED]); until it is
// configured this run deletes nothing and logs `platform.email_prune.unconfigured`.
Schedule::command('platform:email-prune')
    ->dailyAt('02:30')
    ->withoutOverlapping()
    ->name('email-prune');

// Phase 0O.8A (ADR 0054 section 7.1): custom-domain checks -- queues due
// domains (pending ~15 min, live daily, 1 h after a failure), bounded per run
// by DOMAIN_CHECKS_PER_RUN; the DNS/TLS work runs in queue workers, never in
// the scheduler. A no-op while custom domains are disabled. Each row is
// claimed (next_check_at) before it is queued, so an overlapping run is
// harmless; withoutOverlapping() is an efficiency safeguard only.
Schedule::command('platform:domains-check')
    ->everyMinute()
    ->withoutOverlapping()
    ->name('domains-check');

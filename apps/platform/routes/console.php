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

// platform:idempotency-prune is deliberately NOT scheduled here --
// unchanged from Phase 0C.2's explicit decision (run manually/ad hoc
// until a future checkpoint's retention policy actually requires
// automatic pruning). Phase 0C.4 does not change that.

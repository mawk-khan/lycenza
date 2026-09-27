<?php

use App\Support\Auth\CrossHostHandoff;
use App\Support\Auth\HandoffUnavailable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Cache;

// Phase 0O.8A (ADR 0054 amendment): one redemption of a cross-host sign-in
// ticket against the REAL Redis store, in a separate OS process, for
// CrossHostHandoffConcurrencyTest. Signals `ready-<n>`, then waits for the
// parent's `go` file so every contender redeems together.
//
// Usage: php consume-session-handoff.php <dir> <n> <ticket>

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $dir, $n, $ticket] = $argv;
config(['domains.handoff_store' => 'redis']);
Cache::store('redis')->get('warm-up'); // connect before the barrier
$handoff = $app->make(CrossHostHandoff::class);

touch("{$dir}/ready-{$n}");
$deadline = microtime(true) + 60; // a failure bound, not a race window
while (! file_exists("{$dir}/go")) {
    if (microtime(true) > $deadline) {
        echo 'timeout';
        exit(2);
    }
    usleep(200);
}

try {
    echo $handoff->consume($ticket) !== null ? 'redeemed' : 'refused';
} catch (HandoffUnavailable) {
    echo 'unavailable';
}

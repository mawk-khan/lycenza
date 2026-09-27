<?php

use App\Support\ServiceAuth\ServiceAssertionReplayGuard;
use App\Support\ServiceAuth\ServiceAuthenticationException;
use App\Support\ServiceAuth\VerifiedService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Cache;

// Phase 0O.7A (ADR 0053 section 5.5): one jti consumption against the REAL
// Redis cache store, in a separate OS process, for
// ServiceAssertionReplayConcurrencyTest. Signals `ready-<n>`, then waits for
// the parent's `go` file so every contender calls the store together.
//
// Usage: php consume-service-assertion-jti.php <dir> <n> <jti> <exp>

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $dir, $n, $jti, $exp] = $argv;
$guard = new ServiceAssertionReplayGuard(Cache::store('redis'));
Cache::store('redis')->get('warm-up'); // connect before the barrier

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
    $guard->consume(new VerifiedService('ai-gateway', 'test-kid', $jti, (int) $exp), time());
    echo 'accepted';
} catch (ServiceAuthenticationException $e) {
    echo 'rejected:'.$e->reason;
}

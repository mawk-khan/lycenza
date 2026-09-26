<?php

use App\Support\Observability\SchedulerHeartbeatRecorder;
use Illuminate\Contracts\Console\Kernel;
use Tests\Support\Concurrency\HeldTransaction;

// Phase 0O.5A: one heartbeat write in a separate OS process, for
// HeartbeatConcurrencyTest (two worker replicas recording the same
// process-class heartbeat at the same moment).
//
// Usage: php record-heartbeat.php <name>

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

HeldTransaction::run(fn () => $app->make(SchedulerHeartbeatRecorder::class)->recordSuccess($argv[1]));

echo 'recorded';

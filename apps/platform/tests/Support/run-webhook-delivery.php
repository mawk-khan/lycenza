<?php

use App\Jobs\DeliverWebhookJob;
use Illuminate\Contracts\Console\Kernel;

// Standalone bootstrap script for WebhookDeliveryConcurrencyTest: run
// in a GENUINELY separate OS process (via Symfony\Process::start(),
// non-blocking) so two real, independent PHP processes race
// App\Jobs\DeliverWebhookJob::handle() for the identical delivery id
// against real PostgreSQL -- not a sequential simulation. Mirrors
// tests/Feature/Idempotency/IdempotencyRealConcurrencyTest.php's
// pattern of spawning real subprocesses for its mandatory real-
// concurrency proof.
//
// Usage: php run-webhook-delivery.php <schoolId> <deliveryId>

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

[, $schoolId, $deliveryId] = $argv;

DeliverWebhookJob::dispatchSync($schoolId, $deliveryId);

echo 'done';

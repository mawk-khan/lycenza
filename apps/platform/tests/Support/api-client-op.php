<?php

use App\Models\School;
use App\Models\User;
use App\Support\ApiClients\ApiClientService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Validation\ValidationException;
use Tests\Support\Concurrency\HeldTransaction;

// Standalone bootstrap script for PartnerApiClientConcurrencyTest: one
// partner-client mutation in a GENUINELY separate OS process.
//
// Usage: php api-client-op.php <rotate|revoke> <schoolId> <actorId> <clientId>

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $operation, $schoolId, $actorId, $clientId] = $argv;

try {
    echo HeldTransaction::run(function () use ($app, $operation, $schoolId, $actorId, $clientId) {
        $service = $app->make(ApiClientService::class);
        $school = School::query()->findOrFail($schoolId);
        $actor = User::query()->findOrFail($actorId);

        if ($operation === 'rotate') {
            $service->rotate($school, $actor, $clientId);

            return 'rotated';
        }

        $service->revoke($school, $actor, $clientId);

        return 'revoked';
    });
} catch (ValidationException $e) {
    echo 'invalid:'.implode(',', array_keys($e->errors()));
} catch (Throwable $e) {
    echo 'error:'.$e::class.':'.$e->getMessage();
}

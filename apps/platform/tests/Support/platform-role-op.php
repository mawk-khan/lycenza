<?php

use App\Domain\Platform\Application\Roles\PlatformRoleGovernanceService;
use App\Models\PlatformRoleAssignment;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Validation\ValidationException;
use Tests\Support\Concurrency\HeldTransaction;

// Standalone bootstrap script for PlatformRoleGovernanceConcurrencyTest: one
// platform-role governance operation in a GENUINELY separate OS process.
//
// Usage:
//   php platform-role-op.php grant  <actorId> <targetEmail>
//   php platform-role-op.php revoke <actorId> <assignmentId>

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $operation, $actorId, $argument] = $argv;

try {
    $result = HeldTransaction::run(function () use ($app, $operation, $actorId, $argument) {
        $governance = $app->make(PlatformRoleGovernanceService::class);
        $actor = User::query()->findOrFail($actorId);

        if ($operation === 'grant') {
            $governance->grant($actor, $argument, 'platform_auditor');

            return 'granted';
        }

        $governance->revoke($actor, PlatformRoleAssignment::query()->findOrFail($argument));

        return 'revoked';
    });
    echo $result;
} catch (ValidationException $e) {
    echo 'invalid:'.implode(',', array_keys($e->errors()));
} catch (Throwable $e) {
    echo 'error:'.$e::class.':'.$e->getMessage();
}

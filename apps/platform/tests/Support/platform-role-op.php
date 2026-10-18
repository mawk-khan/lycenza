<?php

use App\Domain\Platform\Application\Roles\PlatformRoleGovernanceService;
use App\Models\PlatformRoleAssignment;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Tests\Support\Concurrency\HeldTransaction;

// Standalone bootstrap script for PlatformRoleGovernanceConcurrencyTest: one
// platform-role governance operation in a GENUINELY separate OS process.
//
// Usage (ORG.1A, V-2: each operation carries the actor's fresh code):
//   php platform-role-op.php grant  <actorId> <targetEmail> <recoveryCode>
//   php platform-role-op.php revoke <actorId> <assignmentId> <recoveryCode>

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $operation, $actorId, $argument, $code] = $argv;
$request = Request::create('/app/platform/roles/grants', 'POST', ['mfa_code' => $code]);

try {
    $result = HeldTransaction::run(function () use ($app, $request, $operation, $actorId, $argument) {
        $governance = $app->make(PlatformRoleGovernanceService::class);
        $actor = User::query()->findOrFail($actorId);

        if ($operation === 'grant') {
            $governance->grant($request, $actor, $argument, 'platform_auditor');

            return 'granted';
        }

        $governance->revoke($request, $actor, PlatformRoleAssignment::query()->findOrFail($argument));

        return 'revoked';
    });
    echo $result;
} catch (ValidationException $e) {
    echo 'invalid:'.implode(',', array_keys($e->errors()));
} catch (Throwable $e) {
    echo 'error:'.$e::class.':'.$e->getMessage();
}

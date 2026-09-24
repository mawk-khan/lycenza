<?php

use App\Domain\Platform\Application\Elevation\ElevationDeniedException;
use App\Domain\Platform\Application\Elevation\SchoolElevationService;
use App\Domain\Platform\Application\Groups\SchoolGroupGovernanceService;
use App\Models\GroupRoleAssignment;
use App\Models\School;
use App\Models\SchoolGroup;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Tests\Support\Concurrency\HeldTransaction;

// Standalone bootstrap script for GroupAuthorityConcurrencyTest: one
// Group-authority operation in a GENUINELY separate OS process, so two real
// PHP processes race against real PostgreSQL. Mirrors start-school-elevation.php.
//
// Usage:
//   php group-authority-op.php start  <actorId> <groupId> <schoolId> <recoveryCode>
//   php group-authority-op.php remove <platformActorId> <groupId> <schoolId>
//   php group-authority-op.php revoke <platformActorId> <grantId>
//   php group-authority-op.php grant  <platformActorId> <groupId> <userEmail>

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$args = array_slice($argv, 1);
$operation = array_shift($args);

try {
    $result = HeldTransaction::run(function () use ($app, $operation, $args) {
        $governance = $app->make(SchoolGroupGovernanceService::class);

        switch ($operation) {
            case 'start':
                [$actorId, $groupId, $schoolId, $code] = $args;
                $request = Request::create('/app/groups/'.$groupId.'/elevation', 'POST');
                $session = $app->make('session')->driver('array');
                $session->start();
                $request->setLaravelSession($session);
                $app->instance('request', $request);
                $app->make(SchoolElevationService::class)->start($request, User::query()->findOrFail($actorId), $schoolId, 'operational_support', true, $code, SchoolGroup::query()->findOrFail($groupId));

                return 'started';
            case 'remove':
                [$actorId, $groupId, $schoolId] = $args;
                $governance->removeSchool(User::query()->findOrFail($actorId), SchoolGroup::query()->findOrFail($groupId), School::query()->findOrFail($schoolId));

                return 'removed';
            case 'revoke':
                [$actorId, $grantId] = $args;
                $governance->revoke(User::query()->findOrFail($actorId), GroupRoleAssignment::query()->findOrFail($grantId));

                return 'revoked';
            case 'grant':
                [$actorId, $groupId, $email] = $args;
                $governance->grant(User::query()->findOrFail($actorId), SchoolGroup::query()->findOrFail($groupId), $email);

                return 'granted';
        }

        return 'unknown';
    });
    echo $result;
} catch (ElevationDeniedException $e) {
    echo 'rejected:'.$e->outcome;
} catch (ValidationException $e) {
    echo 'invalid:'.implode(',', array_keys($e->errors()));
} catch (Throwable $e) {
    echo 'error:'.$e::class.':'.$e->getMessage();
}

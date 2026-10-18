<?php

use App\Domain\Platform\Application\Modules\SchoolModuleEntitlementDeniedException;
use App\Domain\Platform\Application\Modules\SchoolModuleEntitlementService;
use App\Models\School;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Tests\Support\Concurrency\HeldTransaction;

// Standalone bootstrap script for SchoolModuleEntitlementConcurrencyTest
// (ORG.5): one module entitlement change in a GENUINELY separate OS process.
// Mirrors school-lifecycle-op.php.
//
// Usage:
//   php school-module-op.php enable|disable <actorId> <schoolId> <module> <recoveryCode>

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$operation, $actorId, $schoolId, $module, $code] = array_slice($argv, 1);

$request = Request::create('/app/platform/schools', 'POST');
$session = $app->make('session')->driver('array');
$session->start();
$request->setLaravelSession($session);
$app->instance('request', $request);

try {
    echo HeldTransaction::run(function () use ($app, $request, $operation, $actorId, $schoolId, $module, $code) {
        $service = $app->make(SchoolModuleEntitlementService::class);
        $actor = User::query()->findOrFail($actorId);
        $school = School::query()->findOrFail($schoolId);

        match ($operation) {
            'enable' => $service->enable($request, $actor, $school, $module, 'platform_policy', true, $code),
            'disable' => $service->disable($request, $actor, $school, $module, 'platform_policy', true, $code),
        };

        return $operation.'d';
    });
} catch (SchoolModuleEntitlementDeniedException $e) {
    echo 'rejected:'.$e->outcome;
} catch (Throwable $e) {
    echo 'error:'.$e::class.':'.$e->getMessage();
}

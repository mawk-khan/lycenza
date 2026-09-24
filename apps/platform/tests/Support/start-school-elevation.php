<?php

use App\Domain\Platform\Application\Elevation\ElevationDeniedException;
use App\Domain\Platform\Application\Elevation\SchoolElevationService;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Tests\Support\Concurrency\HeldTransaction;

// Standalone bootstrap script for SchoolElevationConcurrencyTest: run in
// a GENUINELY separate OS process so two real PHP processes race
// SchoolElevationService::start() for the SAME platform actor (two
// different Schools, two different recovery codes) against real
// PostgreSQL. Mirrors activate-academic-year.php.
//
// Usage: php start-school-elevation.php <actorId> <schoolId> <recoveryCode>

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

[, $actorId, $schoolId, $code] = $argv;

$request = Request::create('/app/platform/elevation', 'POST');
$session = $app->make('session')->driver('array');
$session->start();
$request->setLaravelSession($session);
$app->instance('request', $request);

try {
    $actor = User::query()->findOrFail($actorId);
    HeldTransaction::run(fn () => $app->make(SchoolElevationService::class)->start($request, $actor, $schoolId, 'incident_response', true, $code));
    echo 'started';
} catch (ElevationDeniedException $e) {
    echo 'rejected:'.$e->outcome;
} catch (Throwable $e) {
    echo 'error:'.$e::class.':'.$e->getMessage();
}

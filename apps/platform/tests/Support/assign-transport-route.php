<?php

use App\Domain\HR\Infrastructure\Employee;
use App\Domain\Transport\Application\TransportRouteAssignmentService;
use App\Domain\Transport\Infrastructure\TransportRoute;
use App\Domain\Transport\Infrastructure\TransportVehicle;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;

// Standalone bootstrap script for
// TransportRouteAssignmentConcurrencyTest: run in a GENUINELY separate
// OS process (via Symfony\Process::start(), non-blocking) so two real,
// independent PHP processes race
// TransportRouteAssignmentService::assign() for the SAME Route against
// real PostgreSQL -- not a sequential simulation. Mirrors
// checkout-library-copy.php's identical pattern.
//
// Usage: php assign-transport-route.php <schoolId> <routeId> <vehicleId> <driverEmployeeId>

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

[, $schoolId, $routeId, $vehicleId, $driverEmployeeId] = $argv;

$context = $app->make(TenantContext::class);
$school = School::query()->findOrFail($schoolId);
$context->set($school);

try {
    $route = TransportRoute::query()->findOrFail($routeId);
    $vehicle = TransportVehicle::query()->findOrFail($vehicleId);
    $driver = Employee::query()->findOrFail($driverEmployeeId);
    $assignment = $app->make(TransportRouteAssignmentService::class)->assign($route, $vehicle, $driver);
    echo 'assigned:'.$assignment->id;
} catch (Throwable $e) {
    echo 'rejected:'.$e::class;
} finally {
    $context->clearAll();
}

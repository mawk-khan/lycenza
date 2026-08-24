<?php

use App\Domain\HR\Application\EmployeeService;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;

// Standalone bootstrap script for EmployeeNumberConcurrencyTest: run in
// a GENUINELY separate OS process (via Symfony\Process::start(),
// non-blocking) so several real, independent PHP processes race
// EmployeeService::create() for the SAME School against real
// PostgreSQL -- not a sequential simulation. Mirrors
// activate-academic-year.php's identical pattern.
//
// Usage: php create-employee.php <schoolId> <fullName> <actorId>
// <actorId> is a User already holding hr.employees.manage at
// <schoolId>, created and committed by the parent test before any
// subprocess spawns (Phase 8A.10 -- EmployeeService::create() now
// requires and authorizes a real actor).
// Prints the allocated employee_number on success, or "rejected:<class>"
// on failure.

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

[, $schoolId, $fullName, $actorId] = $argv;

$context = $app->make(TenantContext::class);
$school = School::query()->findOrFail($schoolId);
$actor = User::query()->findOrFail($actorId);

try {
    $employee = $app->make(EmployeeService::class)->create($school, ['full_name' => $fullName], $actor);
    echo $employee->employee_number;
} catch (Throwable $e) {
    echo 'rejected:'.$e::class;
} finally {
    $context->clearAll();
}

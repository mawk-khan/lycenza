<?php

use App\Domain\HR\Application\EmployeeLifecycleService;
use App\Domain\HR\Application\Exceptions\EmploymentAlreadyEndedException;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;

// Standalone bootstrap script for a real separation concurrency proof
// (checkpoint 8A.13 section 36): run in a GENUINELY separate OS process
// so several real, independent PHP processes race
// EmployeeLifecycleService::separate() for the SAME EmploymentRecord
// against real PostgreSQL. Mirrors rehire-employee.php's identical
// pattern.
//
// Usage: php separate-employment.php <schoolId> <actorId> <employmentId> <endsOn>
// Prints "separated:<employmentId>" on success, "already_ended" if a
// concurrent winner already closed it, or "error:<class>" otherwise.

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

[, $schoolId, $actorId, $employmentId, $endsOn] = array_pad($argv, 5, null);

$context = $app->make(TenantContext::class);
$school = School::query()->findOrFail($schoolId);
$actor = User::query()->findOrFail($actorId);
$employment = $context->withSchool($school, fn () => EmploymentRecord::query()->findOrFail($employmentId));

try {
    $result = $app->make(EmployeeLifecycleService::class)->separate($employment, $endsOn, $actor);
    echo 'separated:'.$result->id;
} catch (EmploymentAlreadyEndedException) {
    echo 'already_ended';
} catch (Throwable $e) {
    echo 'error:'.$e::class;
} finally {
    $context->clearAll();
}

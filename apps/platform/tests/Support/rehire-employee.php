<?php

use App\Domain\HR\Application\EmployeeLifecycleService;
use App\Domain\HR\Application\Exceptions\EmploymentOverlapException;
use App\Domain\HR\Application\Exceptions\RehireRequiresEmploymentHistoryException;
use App\Domain\HR\Infrastructure\Employee;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;

// Standalone bootstrap script for a real rehire concurrency proof
// (checkpoint 8A.13 section 35): run in a GENUINELY separate OS
// process (via Symfony\Process::start(), non-blocking) so several real,
// independent PHP processes race EmployeeLifecycleService::rehire()
// for the SAME Employee against real PostgreSQL -- not a sequential
// simulation. Mirrors tests/Support/import-employee.php's/
// create-employee.php's identical pattern (8A.1/8A.12).
//
// Usage: php rehire-employee.php <schoolId> <actorId> <employeeId> <startsOn>
// Prints "created:<employmentId>" on success, "overlap" if a concurrent
// winner already claimed the date range, "no_history" if the Employee
// somehow has none, or "error:<class>" for anything else (it should
// never reach that branch for the inputs this script is used with).

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

[, $schoolId, $actorId, $employeeId, $startsOn] = array_pad($argv, 5, null);

$context = $app->make(TenantContext::class);
$school = School::query()->findOrFail($schoolId);
$actor = User::query()->findOrFail($actorId);
$employee = $context->withSchool($school, fn () => Employee::query()->findOrFail($employeeId));

try {
    $employment = $app->make(EmployeeLifecycleService::class)->rehire(
        $employee,
        ['employment_type' => 'permanent', 'starts_on' => $startsOn],
        $actor,
    );
    echo 'created:'.$employment->id;
} catch (EmploymentOverlapException) {
    echo 'overlap';
} catch (RehireRequiresEmploymentHistoryException) {
    echo 'no_history';
} catch (Throwable $e) {
    echo 'error:'.$e::class;
} finally {
    $context->clearAll();
}

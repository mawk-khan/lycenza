<?php

use App\Domain\HR\Application\EmployeeImportService;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Tests\Support\Concurrency\HeldTransaction;

// Standalone bootstrap script for a real Employee-import concurrency
// proof: run in a GENUINELY separate OS process (via
// Symfony\Process::start(), non-blocking) so several real, independent
// PHP processes race EmployeeImportService::import() for the SAME
// School (and, in the same-User case, the same target User) against
// real PostgreSQL -- not a sequential simulation. Mirrors
// create-employee.php's/set-assignment-manager.php's identical pattern
// (8A.1/8A.5).
//
// Usage: php import-employee.php <schoolId> <actorId> <fullName> [<userId>]
// Prints "<status>:<employeeId-or-empty>" on success (status is one of
// created/duplicate_exact/duplicate_potential/failed), or
// "error:<class>" if the call itself throws (it should not, for any
// input this script is used with).

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

[, $schoolId, $actorId, $fullName, $userId] = array_pad($argv, 5, null);

$context = $app->make(TenantContext::class);
$school = School::query()->findOrFail($schoolId);
$actor = User::query()->findOrFail($actorId);

$row = ['full_name' => $fullName];
if (! empty($userId)) {
    $row['user_id'] = $userId;
}

try {
    $result = HeldTransaction::run(fn () => $app->make(EmployeeImportService::class)->import($school, $actor, [$row]));
    $rowResult = $result->rows[0];
    echo $rowResult->status.':'.($rowResult->employeeId ?? '');
} catch (Throwable $e) {
    echo 'error:'.$e::class;
} finally {
    $context->clearAll();
}

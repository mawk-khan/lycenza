<?php

use App\Domain\HR\Application\EmployeePersonalDetailService;
use App\Domain\HR\Application\Profile\EmployeeReferenceCodeService;
use App\Domain\HR\Application\Profile\EmployeeReferenceKind;
use App\Domain\HR\Infrastructure\Employee;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Tests\Support\Concurrency\HeldTransaction;

// HR.2A: one staff-profile write run in a genuinely separate OS process, for
// StaffProfileConcurrencyTest (Tests\Concerns\ForcesConcurrentOverlap).
//
// Usage:
//   php hr-profile-op.php <schoolId> record-code <employeeId> <kind> <value> <actorId>
//   php hr-profile-op.php <schoolId> personal <employeeId> <nationality> <version> <actorId>
// Prints "ok:<id>" or "rejected:<SQLSTATE|class[:reason]>".

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $schoolId, $kind] = $argv;
$context = $app->make(TenantContext::class);
$context->set(School::query()->findOrFail($schoolId));

try {
    $id = HeldTransaction::run(fn (): string => match ($kind) {
        'record-code' => $app->make(EmployeeReferenceCodeService::class)->record(
            Employee::query()->findOrFail($argv[3]), EmployeeReferenceKind::from($argv[4]), $argv[5], User::query()->findOrFail($argv[6]),
        )->id,
        'personal' => $app->make(EmployeePersonalDetailService::class)->setDetails(
            Employee::query()->findOrFail($argv[3]), ['nationality' => $argv[4]], User::query()->findOrFail($argv[6]), $argv[5],
        )->id,
        default => throw new InvalidArgumentException("unknown kind {$kind}"),
    });
    echo 'ok:'.$id;
} catch (QueryException $e) {
    echo 'rejected:'.$e->getCode();
} catch (Throwable $e) {
    echo 'rejected:'.class_basename($e).(property_exists($e, 'reason') ? ':'.$e->reason : '');
} finally {
    $context->clearAll();
}

<?php

use App\Domain\Leave\Application\Exceptions\LeaveException;
use App\Domain\Leave\Application\LeaveLedgerService;
use App\Domain\Leave\Application\LeaveYearService;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Tests\Support\Concurrency\HeldTransaction;

// Standalone bootstrap script for LeaveConcurrencyTest (HRX.1): one ledger
// operation in a GENUINELY separate OS process.
//
// Usage:
//   php leave-op.php allocate <schoolId> <employmentId> <typeId> <yearId> <units> <actorId>
//   php leave-op.php debit    <schoolId> <employmentId> <typeId> <yearId> <units> <actorId>
//   php leave-op.php run      <schoolId> <typeId> <yearId> <actorId>
//   php leave-op.php open     <schoolId> <date> <actorId>
//   php leave-op.php schedule <schoolId> <startMonth> <effectiveFrom> <actorId>

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$args = array_slice($argv, 1);
$operation = array_shift($args);

try {
    $school = School::query()->findOrFail($args[0]);
    $ledger = $app->make(LeaveLedgerService::class);
    $years = $app->make(LeaveYearService::class);
    echo $app->make(TenantContext::class)->withSchool($school, fn () => HeldTransaction::run(fn (): string => match ($operation) {
        'allocate' => 'ok:'.$ledger->allocate($school, $args[1], $args[2], $args[3], (int) $args[4], User::query()->findOrFail($args[5]))->units,
        'debit' => 'ok:'.$ledger->adjust($school, $args[1], $args[2], $args[3], 'debit', (int) $args[4], 'allocation_correction', User::query()->findOrFail($args[5]))->units,
        'run' => 'ok:'.$ledger->executeRun($school, $args[1], $args[2], User::query()->findOrFail($args[3]))->allocated_count,
        'open' => (fn ($y) => 'ok:'.$y->starts_on->toDateString().'..'.$y->ends_on->toDateString().($y->is_transition ? ':transition' : ''))($years->open($school, $args[1], User::query()->findOrFail($args[2]))),
        'schedule' => 'ok:'.$years->scheduleStartChange($school, (int) $args[1], $args[2], User::query()->findOrFail($args[3]))->effective_from->toDateString(),
        default => throw new InvalidArgumentException("unknown operation {$operation}"),
    }));
} catch (LeaveException $e) {
    echo 'rejected:'.$e->errorCode();
} catch (Throwable $e) {
    echo 'rejected:'.class_basename($e);
}

<?php

use App\Domain\Leave\Application\Exceptions\LeaveException;
use App\Domain\Leave\Application\LeaveLedgerService;
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

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$args = array_slice($argv, 1);
$operation = array_shift($args);

try {
    $school = School::query()->findOrFail($args[0]);
    $ledger = $app->make(LeaveLedgerService::class);
    echo $app->make(TenantContext::class)->withSchool($school, fn () => HeldTransaction::run(fn (): string => match ($operation) {
        'allocate' => 'ok:'.$ledger->allocate($school, $args[1], $args[2], $args[3], (int) $args[4], User::query()->findOrFail($args[5]))->units,
        'debit' => 'ok:'.$ledger->adjust($school, $args[1], $args[2], $args[3], 'debit', (int) $args[4], 'allocation_correction', User::query()->findOrFail($args[5]))->units,
        'run' => 'ok:'.$ledger->executeRun($school, $args[1], $args[2], User::query()->findOrFail($args[3]))->allocated_count,
        default => throw new InvalidArgumentException("unknown operation {$operation}"),
    }));
} catch (LeaveException $e) {
    echo 'rejected:'.$e->errorCode();
} catch (Throwable $e) {
    echo 'rejected:'.class_basename($e);
}

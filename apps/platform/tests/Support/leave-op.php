<?php

use App\Domain\Leave\Application\Exceptions\LeaveException;
use App\Domain\Leave\Application\LeaveLedgerService;
use App\Domain\Leave\Application\LeaveRequestService;
use App\Domain\Leave\Application\LeaveYearCloseService;
use App\Domain\Leave\Application\LeaveYearService;
use App\Domain\Leave\Application\StaffCalendarService;
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
//   php leave-op.php submit   <schoolId> <employmentId> <typeId> <from> <to> <actorId>
//   php leave-op.php approve|reject|withdraw|cancel <schoolId> <requestId> <actorId>
//   php leave-op.php close    <schoolId> <yearId> <actorId>
//   php leave-op.php monday   <schoolId> <portion> <actorId>   (Monday's portion; the rest Mon-Fri full, weekend off)
//   php leave-op.php submit-own <schoolId> <typeId> <from> <to> <actorId>          (HRX.4 self-service)
//   php leave-op.php withdraw-own|cancel-own <schoolId> <requestId> <actorId>     (HRX.4 self-service)
//   php leave-op.php manager-approve <schoolId> <requestId> <actorId>             (HRX.2 manager path)

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$args = array_slice($argv, 1);
$operation = array_shift($args);

try {
    $school = School::query()->findOrFail($args[0]);
    $ledger = $app->make(LeaveLedgerService::class);
    $years = $app->make(LeaveYearService::class);
    $requests = $app->make(LeaveRequestService::class);
    echo $app->make(TenantContext::class)->withSchool($school, fn () => HeldTransaction::run(fn (): string => match ($operation) {
        'allocate' => 'ok:'.$ledger->allocate($school, $args[1], $args[2], $args[3], (int) $args[4], User::query()->findOrFail($args[5]))->units,
        'debit' => 'ok:'.$ledger->adjust($school, $args[1], $args[2], $args[3], 'debit', (int) $args[4], 'allocation_correction', User::query()->findOrFail($args[5]))->units,
        'run' => 'ok:'.$ledger->executeRun($school, $args[1], $args[2], User::query()->findOrFail($args[3]))->allocated_count,
        'open' => (fn ($y) => 'ok:'.$y->starts_on->toDateString().'..'.$y->ends_on->toDateString().($y->is_transition ? ':transition' : ''))($years->open($school, $args[1], User::query()->findOrFail($args[2]))),
        'schedule' => 'ok:'.$years->scheduleStartChange($school, (int) $args[1], $args[2], User::query()->findOrFail($args[3]))->effective_from->toDateString(),
        'submit' => 'ok:'.$requests->submitOnBehalf($school, $args[1], $args[2], $args[3], 'full', $args[4], 'full', null, User::query()->findOrFail($args[5]))->status,
        'approve' => 'ok:'.$requests->approve($school, $args[1], User::query()->findOrFail($args[2]))->status,
        'reject' => 'ok:'.$requests->reject($school, $args[1], 'staffing_need', User::query()->findOrFail($args[2]))->status,
        'withdraw' => 'ok:'.$requests->withdraw($school, $args[1], 'plans_changed', User::query()->findOrFail($args[2]))->status,
        'cancel' => 'ok:'.$requests->cancel($school, $args[1], 'plans_changed', User::query()->findOrFail($args[2]))->status,
        'submit-own' => 'ok:'.$requests->submitOwn($school, $args[1], $args[2], 'full', $args[3], 'full', null, User::query()->findOrFail($args[4]))->status,
        'withdraw-own' => 'ok:'.$requests->withdrawOwn($school, $args[1], 'plans_changed', User::query()->findOrFail($args[2]))->status,
        'cancel-own' => 'ok:'.$requests->cancelOwn($school, $args[1], 'plans_changed', User::query()->findOrFail($args[2]))->status,
        'manager-approve' => 'ok:'.$requests->approveAsManager($school, $args[1], User::query()->findOrFail($args[2]))->status,
        'close' => 'ok:'.$app->make(LeaveYearCloseService::class)->execute($school, $args[1], User::query()->findOrFail($args[2]))->item_count,
        'monday' => (function () use ($app, $school, $args) {
            $app->make(StaffCalendarService::class)->setWeeklyPattern($school, [1 => $args[1], 2 => 'full', 3 => 'full', 4 => 'full', 5 => 'full', 6 => 'off', 7 => 'off'], User::query()->findOrFail($args[2]));

            return 'ok:'.$args[1];
        })(),
        default => throw new InvalidArgumentException("unknown operation {$operation}"),
    }));
} catch (LeaveException $e) {
    echo 'rejected:'.$e->errorCode();
} catch (Throwable $e) {
    echo 'rejected:'.class_basename($e);
}

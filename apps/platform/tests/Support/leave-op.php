<?php

use App\Domain\HR\Application\EmploymentService;
use App\Domain\HR\Application\ReportingHierarchyService;
use App\Domain\HR\Application\Workforce\StaffType;
use App\Domain\HR\Infrastructure\EmployeeAssignment;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\Leave\Application\Accrual\LeaveAccrualService;
use App\Domain\Leave\Application\Exceptions\LeaveException;
use App\Domain\Leave\Application\LeaveEligibilityService;
use App\Domain\Leave\Application\LeaveLedgerService;
use App\Domain\Leave\Application\LeavePolicyAssignmentService;
use App\Domain\Leave\Application\LeavePolicyService;
use App\Domain\Leave\Application\LeaveRequestService;
use App\Domain\Leave\Application\LeaveYearCloseService;
use App\Domain\Leave\Application\LeaveYearService;
use App\Domain\Leave\Application\Policy\LeaveEligibilityCriteria;
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
//   php leave-op.php set-manager <schoolId> <subordinateAssignmentId> <managerAssignmentId|none> <actorId>   (ORG.8D: HR moves the line)
//   php leave-op.php eligibility-run <schoolId> <typeId> <asOf> <actorId>                          (HR.9: prints the assigned count)
//   php leave-op.php version <schoolId> <typeId> <staffType|any> <supersedesPolicyId|none> <actorId> (HR.9: one-rule version)
//   php leave-op.php end-employment <schoolId> <employmentId> <endsOn> <actorId>                   (HR: the employment ends)
//   php leave-op.php accrue <schoolId> <typeId> <yearId> <periodStart> <actorId>                   (HR.10: prints status:credited)
//   php leave-op.php end-assignment <schoolId> <assignmentId> <to> <actorId>                         (HR.10 race: a policy assignment ends)

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
        // HR.3A: a dated reporting line from today; prints the chosen manager assignment.
        'set-manager' => 'ok:'.($app->make(ReportingHierarchyService::class)->setManager(
            EmployeeAssignment::query()->findOrFail($args[1]),
            $args[2] === 'none' ? null : EmployeeAssignment::query()->findOrFail($args[2]),
            User::query()->findOrFail($args[3]),
        ) !== null ? $args[2] : ''),
        'eligibility-run' => 'ok:'.$app->make(LeaveEligibilityService::class)->executeRun($school, $args[1], $args[2], User::query()->findOrFail($args[3]))->assigned_count,
        'version' => 'ok:'.$app->make(LeavePolicyService::class)->create($school, $args[1], [
            'name' => 'Raced', 'annual_allocation_units' => 24, 'carry_forward_allowed' => false, 'carry_forward_cap_units' => null, 'carry_forward_expiry_days' => null,
        ], $args[3] === 'none' ? null : $args[3], User::query()->findOrFail($args[4]), [new LeaveEligibilityCriteria($args[2] === 'any' ? null : StaffType::from($args[2]), null, null)])->status,
        'accrue' => (function () use ($app, $school, $args) {
            $accruals = $app->make(LeaveAccrualService::class);
            $actor = User::query()->findOrFail($args[4]);
            $run = $accruals->execute($school, $args[1], $args[2], $args[3], $actor);

            return 'ok:'.$run->status.':'.$accruals->show($school, $run->id, $actor)['counts']['credited'];
        })(),
        'end-assignment' => 'ok:'.$app->make(LeavePolicyAssignmentService::class)->end($school, $args[1], $args[2], User::query()->findOrFail($args[3]))->effective_to->toDateString(),
        'end-employment' => 'ok:'.$app->make(EmploymentService::class)->end(EmploymentRecord::query()->findOrFail($args[1]), $args[2], User::query()->findOrFail($args[3]))->status,
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

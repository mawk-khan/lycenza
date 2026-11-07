<?php

use App\Domain\HR\Application\AccountLinks\EmployeeAccountLinkCorrectionService;
use App\Domain\HR\Application\AccountLinks\EmployeeAccountLinkVoidReason;
use App\Domain\HR\Application\EmployeeService;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmployeeAccountLinkCorrection;
use App\Domain\Payroll\Application\PayrollReversalReconciliationService;
use App\Domain\Payroll\Application\PayrollRunService;
use App\Domain\Payroll\Infrastructure\PayrollPeriod;
use App\Domain\Payroll\Infrastructure\PayrollRun;
use App\Models\School;
use App\Models\User;
use App\Support\Auth\Mfa\FreshMfaProof;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\Support\Concurrency\HeldTransaction;

// HR.R1-C6: a review or an account-link change in a GENUINELY separate OS process.
//
//   php payroll-reconciliation-op.php review <schoolId> <reconciliationId> <userId> <version>
//   php payroll-reconciliation-op.php link <schoolId> <employeeId> <userId>
//   php payroll-reconciliation-op.php unlink <schoolId> <employeeId>
//   php payroll-reconciliation-op.php service-link <schoolId> <employeeId> <userId> <actorId>
//   php payroll-reconciliation-op.php service-unlink <schoolId> <employeeId> <actorId>
//   php payroll-reconciliation-op.php request-correction <schoolId> <employeeId> <linkId> <actorId>
//   php payroll-reconciliation-op.php create-correction <schoolId> <targetRunId> <periodId> <actorId>
//   php payroll-reconciliation-op.php approve-correction <schoolId> <correctionId> <version> <actorId>
//   php payroll-reconciliation-op.php refuse-correction <schoolId> <correctionId> <actorId>

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $op, $schoolId] = $argv;
$school = School::query()->findOrFail($schoolId);

try {
    HeldTransaction::run(function () use ($op, $school, $argv): void {
        app(TenantContext::class)->withSchool($school, function () use ($op, $school, $argv): void {
            if ($op === 'review') {
                $user = User::query()->findOrFail($argv[4]);
                app(PayrollReversalReconciliationService::class)->review($school, $argv[3], $user, new FreshMfaProof($user->id, CarbonImmutable::now()), $argv[5], true);

                return;
            }
            // HR.R1-C7: through the real writers, as HR does it.
            if ($op === 'service-link' || $op === 'service-unlink' || $op === 'request-correction') {
                $employee = Employee::query()->whereKey($argv[3])->firstOrFail();
                $actor = User::query()->findOrFail($argv[$op === 'service-unlink' ? 4 : 5]);
                match ($op) {
                    'service-link' => app(EmployeeService::class)->linkUser($employee, $argv[4], $actor),
                    'service-unlink' => app(EmployeeService::class)->unlinkUser($employee, $actor),
                    'request-correction' => app(EmployeeAccountLinkCorrectionService::class)->request($employee, $argv[4], EmployeeAccountLinkVoidReason::WrongPerson, $actor),
                };

                return;
            }
            // HR.R1-C8 (PAY-17): a correction run of a posted run, with one delta, calculated when it is asked for.
            if ($op === 'create-correction') {
                $actor = User::query()->findOrFail($argv[5]);
                app(PayrollRunService::class)->createCorrectionRun(PayrollRun::query()->findOrFail($argv[3]), PayrollPeriod::query()->findOrFail($argv[4]), $actor);

                return;
            }
            if ($op === 'approve-correction') {
                $actor = User::query()->findOrFail($argv[5]);
                $employee = Employee::query()->findOrFail(EmployeeAccountLinkCorrection::query()->findOrFail($argv[3])->employee_id);
                app(EmployeeAccountLinkCorrectionService::class)->approve($employee, $argv[3], $argv[4], $actor, new FreshMfaProof($actor->id, CarbonImmutable::now()));

                return;
            }
            if ($op === 'refuse-correction') {
                $actor = User::query()->findOrFail($argv[4]);
                $employee = Employee::query()->findOrFail(EmployeeAccountLinkCorrection::query()->findOrFail($argv[3])->employee_id);
                app(EmployeeAccountLinkCorrectionService::class)->refuse($employee, $argv[3], $actor, new FreshMfaProof($actor->id, CarbonImmutable::now()));

                return;
            }
            Employee::query()->whereKey($argv[3])->firstOrFail();
            DB::table('employees')->where('id', $argv[3])->update(['user_id' => $op === 'link' ? $argv[4] : null]);
        });
    });
    echo 'ok';
} catch (Throwable $e) {
    echo 'rejected:'.$e::class;
}

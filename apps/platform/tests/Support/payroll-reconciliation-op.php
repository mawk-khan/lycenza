<?php

use App\Domain\HR\Infrastructure\Employee;
use App\Domain\Payroll\Application\PayrollReversalReconciliationService;
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
            Employee::query()->whereKey($argv[3])->firstOrFail();
            DB::table('employees')->where('id', $argv[3])->update(['user_id' => $op === 'link' ? $argv[4] : null]);
        });
    });
    echo 'ok';
} catch (Throwable $e) {
    echo 'rejected:'.$e::class;
}

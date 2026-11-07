<?php

use App\Domain\HR\Application\EmploymentService;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\Leave\Application\Exceptions\LeaveException;
use App\Domain\Leave\Application\LeaveRequestService;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\Concurrency\HeldTransaction;

// HR.R1-C9: the reporting-line race, in genuinely separate OS processes.
//
//   php reporting-line-op.php gate <schoolId> <employmentId>                      (holds FOR UPDATE on the employment)
//   php reporting-line-op.php end <schoolId> <employmentId> <lastDay> <actorId>
//   php reporting-line-op.php manager-approve <schoolId> <requestId> <actorId>

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $op, $schoolId] = $argv;
$school = School::query()->findOrFail($schoolId);
$app->make(TenantContext::class)->set($school);

try {
    echo HeldTransaction::run(fn (): string => match ($op) {
        'gate' => (string) DB::table('employment_records')->where('id', $argv[3])->lockForUpdate()->value('id'),
        'end' => 'ok:'.$app->make(EmploymentService::class)->end(EmploymentRecord::query()->findOrFail($argv[3]), $argv[4], User::query()->findOrFail($argv[5]))->status,
        'manager-approve' => 'ok:'.$app->make(LeaveRequestService::class)->approveAsManager($school, $argv[3], User::query()->findOrFail($argv[4]))->status,
    });
} catch (LeaveException $e) {
    echo 'rejected:'.$e->errorCode();
} catch (QueryException $e) {
    echo 'sql:'.$e->getCode();
} catch (Throwable $e) {
    echo 'rejected:'.$e::class;
}

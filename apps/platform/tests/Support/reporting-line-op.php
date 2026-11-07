<?php

use App\Domain\HR\Application\EmploymentService;
use App\Domain\HR\Application\Reporting\ReportingChangeReason;
use App\Domain\HR\Application\Reporting\ReportingRelationshipService;
use App\Domain\HR\Application\Workforce\PlacementChangeKind;
use App\Domain\HR\Application\Workforce\PlacementChangeReason;
use App\Domain\HR\Application\Workforce\PlacementChangeService;
use App\Domain\HR\Infrastructure\EmployeeAssignment;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\HR\Infrastructure\Position;
use App\Domain\Leave\Application\Exceptions\LeaveException;
use App\Domain\Leave\Application\LeaveRequestService;
use App\Domain\Leave\Infrastructure\LeaveDecision;
use App\Models\Campus;
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
//   php reporting-line-op.php gate-employee <schoolId> <employeeId>               (holds FOR SHARE on the Employee)
//   php reporting-line-op.php manager-reject <schoolId> <requestId> <actorId>
//   php reporting-line-op.php line-remove <schoolId> <employmentId> <effectiveOn> <actorId>
//   php reporting-line-op.php gate-type <schoolId> <leaveTypeId>                  (holds FOR UPDATE on the leave type)
//   php reporting-line-op.php transfer <schoolId> <assignmentId> <effectiveOn> <positionId> <campusId> <actorId>

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $op, $schoolId] = $argv;
$school = School::query()->findOrFail($schoolId);
$app->make(TenantContext::class)->set($school);

try {
    echo HeldTransaction::run(fn (): string => match ($op) {
        'gate' => (string) DB::table('employment_records')->where('id', $argv[3])->lockForUpdate()->value('id'),
        'gate-employee' => (string) DB::table('employees')->where('id', $argv[3])->sharedLock()->value('id'),
        'manager-reject' => 'ok:'.$app->make(LeaveRequestService::class)->rejectAsManager($school, $argv[3], LeaveDecision::REJECTION_REASONS[0], User::query()->findOrFail($argv[4]))->status,
        'line-remove' => 'ok:'.$app->make(ReportingRelationshipService::class)->remove(EmploymentRecord::query()->findOrFail($argv[3]), $argv[4], ReportingChangeReason::ORGANISATIONAL_CHANGE, User::query()->findOrFail($argv[5]))->id,
        'gate-type' => (string) DB::table('leave_types')->where('id', $argv[3])->lockForUpdate()->value('id'),
        'transfer' => 'ok:'.$app->make(PlacementChangeService::class)->change(
            EmployeeAssignment::query()->findOrFail($argv[3]), PlacementChangeKind::TRANSFER, $argv[4], PlacementChangeReason::CAREER_PROGRESSION,
            Position::query()->findOrFail($argv[5]), User::query()->findOrFail($argv[7]), campus: Campus::query()->findOrFail($argv[6]),
        )->id,
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

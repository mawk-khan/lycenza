<?php

use App\Domain\HR\Application\EmployeeAssignmentService;
use App\Domain\HR\Application\EmploymentService;
use App\Domain\HR\Application\Reporting\ReportingChangeReason;
use App\Domain\HR\Application\Reporting\ReportingRelationshipService;
use App\Domain\HR\Application\Workforce\PlacementChangeKind;
use App\Domain\HR\Application\Workforce\PlacementChangeReason;
use App\Domain\HR\Application\Workforce\PlacementChangeService;
use App\Domain\HR\Infrastructure\EmployeeAssignment;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\HR\Infrastructure\Position;
use App\Domain\Timetable\Application\TimetableScheduleService;
use App\Domain\Timetable\Infrastructure\TimetableEntry;
use App\Models\Campus;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Tests\Support\Concurrency\HeldTransaction;

// HR.R1-C2: the employment end, a future placement change and a Timetable
// reactivation in GENUINELY separate OS processes, the holder held open
// inside HeldTransaction, so their serialization is observed, not simulated.
//
// Usage:
//   php hr-lifecycle-op.php <schoolId> end <employmentId> <endsOn> <actorId>
//   php hr-lifecycle-op.php <schoolId> update <employmentId> <probationEndsOn> <actorId>
//   php hr-lifecycle-op.php <schoolId> transfer <sourceAssignmentId> <effectiveOn> <positionId> <campusId> <actorId>
//   php hr-lifecycle-op.php <schoolId> activate <timetableEntryId> <actorId>
//   php hr-lifecycle-op.php <schoolId> assign <employmentId> <startsOn> <positionId> <campusId> <actorId>
//   php hr-lifecycle-op.php <schoolId> primary <assignmentId> <actorId>
//   php hr-lifecycle-op.php <schoolId> report <subordinateEmploymentId> <managerEmploymentId> <effectiveOn> <actorId>

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $schoolId, $kind] = $argv;
$context = $app->make(TenantContext::class);
$context->set(School::query()->findOrFail($schoolId));

try {
    $result = HeldTransaction::run(function () use ($app, $kind, $argv): string {
        return match ($kind) {
            'end' => (string) $app->make(EmploymentService::class)->end(EmploymentRecord::query()->findOrFail($argv[3]), $argv[4], User::query()->findOrFail($argv[5]))->status,
            'update' => (string) $app->make(EmploymentService::class)->update(EmploymentRecord::query()->findOrFail($argv[3]), ['probation_ends_on' => $argv[4]], User::query()->findOrFail($argv[5]))->status,
            'transfer' => (string) $app->make(PlacementChangeService::class)->change(
                EmployeeAssignment::query()->findOrFail($argv[3]), PlacementChangeKind::TRANSFER, $argv[4], PlacementChangeReason::CAREER_PROGRESSION,
                Position::query()->findOrFail($argv[5]), User::query()->findOrFail($argv[7]), campus: Campus::query()->findOrFail($argv[6]),
            )->id,
            'activate' => (string) $app->make(TimetableScheduleService::class)->activate(TimetableEntry::query()->findOrFail($argv[3]), User::query()->findOrFail($argv[4]))->status,
            'assign' => (string) $app->make(EmployeeAssignmentService::class)->create(
                EmploymentRecord::query()->findOrFail($argv[3]), ['starts_on' => $argv[4]], Position::query()->findOrFail($argv[5]),
                User::query()->findOrFail($argv[7]), Campus::query()->findOrFail($argv[6]),
            )->id,
            'primary' => (string) $app->make(EmployeeAssignmentService::class)->setPrimary(EmployeeAssignment::query()->findOrFail($argv[3]), User::query()->findOrFail($argv[4]))->id,
            'report' => (string) $app->make(ReportingRelationshipService::class)->assign(
                EmploymentRecord::query()->findOrFail($argv[3]), EmploymentRecord::query()->findOrFail($argv[4]), $argv[5], ReportingChangeReason::ORGANISATIONAL_CHANGE, User::query()->findOrFail($argv[6]),
            )->id,
            default => throw new InvalidArgumentException("unknown kind {$kind}"),
        };
    });
    echo 'ok:'.$result;
} catch (Throwable $e) {
    echo 'rejected:'.$e::class;
    if ($e instanceof QueryException) {
        echo ' '.substr($e->getMessage(), 0, 400);
    }
} finally {
    $context->clearAll();
}

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
use App\Domain\HR\Infrastructure\ReportingRelationship;
use App\Models\Campus;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Tests\Support\Concurrency\HeldTransaction;

// HR.3A: one reporting-line write run in a genuinely separate OS process,
// for the two-process races of ReportingConcurrencyTest
// (Tests\Concerns\ForcesConcurrentOverlap / HeldTransaction).
//
// Usage:
//   php hr-reporting-op.php <schoolId> raw-line <subEmploymentId> <managerEmploymentId> <startsOn>
//   php hr-reporting-op.php <schoolId> assign <subEmploymentId> <managerEmploymentId> <effectiveOn> <actorId>
//   php hr-reporting-op.php <schoolId> end-employment <employmentId> <endsOn> <actorId>
//   php hr-reporting-op.php <schoolId> transfer <assignmentId> <campusId> <positionId> <effectiveOn> <actorId>
// Prints "ok:<id>" or "rejected:<SQLSTATE|class[:reason]>".

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $schoolId, $kind] = $argv;
$context = $app->make(TenantContext::class);
$school = School::query()->findOrFail($schoolId);
$context->set($school);

try {
    $id = HeldTransaction::run(function () use ($app, $kind, $argv, $school): string {
        return match ($kind) {
            'raw-line' => (function () use ($argv, $school): string {
                $line = new ReportingRelationship;
                $line->forceFill([
                    'school_id' => $school->id, 'subordinate_employment_id' => $argv[3], 'manager_employment_id' => $argv[4],
                    'starts_on' => $argv[5], 'started_via' => 'backfill',
                ])->save();

                return $line->id;
            })(),
            'assign' => $app->make(ReportingRelationshipService::class)->assign(
                EmploymentRecord::query()->findOrFail($argv[3]),
                EmploymentRecord::query()->findOrFail($argv[4]),
                $argv[5],
                ReportingChangeReason::ORGANISATIONAL_CHANGE,
                User::query()->findOrFail($argv[6]),
            )->id,
            'end-employment' => $app->make(EmploymentService::class)->end(
                EmploymentRecord::query()->findOrFail($argv[3]), $argv[4], User::query()->findOrFail($argv[5]), 'separated',
            )->id,
            'transfer' => $app->make(PlacementChangeService::class)->change(
                EmployeeAssignment::query()->findOrFail($argv[3]),
                PlacementChangeKind::TRANSFER,
                $argv[6],
                PlacementChangeReason::STAFFING_REQUIREMENT,
                Position::query()->findOrFail($argv[5]),
                User::query()->findOrFail($argv[7]),
                Campus::query()->findOrFail($argv[4]),
            )->id,
            default => throw new InvalidArgumentException("unknown kind {$kind}"),
        };
    });
    echo 'ok:'.$id;
} catch (QueryException $e) {
    echo 'rejected:'.$e->getCode();
} catch (Throwable $e) {
    echo 'rejected:'.class_basename($e).(property_exists($e, 'reason') ? ':'.$e->reason : '');
} finally {
    $context->clearAll();
}

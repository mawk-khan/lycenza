<?php

use App\Domain\Campuses\Application\CampusAdministrationService;
use App\Domain\HR\Application\EmployeeAssignmentService;
use App\Domain\HR\Application\EmployeeOnboardingData;
use App\Domain\HR\Application\EmployeeOnboardingService;
use App\Domain\HR\Application\EmployeeService;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmployeeAssignment;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\HR\Infrastructure\Position;
use App\Models\Campus;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\SchoolTimezone;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Tests\Support\Concurrency\HeldTransaction;

// Standalone bootstrap script for HrCampusConcurrencyTest (ORG.7E-1, ADR 0076
// §43): one Campus-scoped HR write, or one change to an Employee's placement
// or a Campus, in a GENUINELY separate OS process (HeldTransaction holder or
// contender).
//
// Usage:
//   php hr-campus-op.php edit <schoolId> <userId> <employeeId> <fullName>
//   php hr-campus-op.php assign <schoolId> <userId> <employmentId> <positionId> <campusId>
//   php hr-campus-op.php end-assignment <schoolId> <userId> <assignmentId> <endsOn>
//   php hr-campus-op.php onboard <schoolId> <userId> <campusId> <positionId> <fullName>
//   php hr-campus-op.php campus-inactivate <schoolId> <adminUserId> <campusId>
//   php hr-campus-op.php set-primary <schoolId> <userId> <assignmentId>

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$args = array_slice($argv, 1);
$operation = array_shift($args);
$school = School::query()->findOrFail(array_shift($args));
$context = $app->make(TenantContext::class);

try {
    echo HeldTransaction::run(fn () => $context->withSchool($school, function () use ($app, $operation, $args, $school): string {
        switch ($operation) {
            case 'edit':
                [$userId, $employeeId, $name] = $args;
                $app->make(EmployeeService::class)->update(Employee::query()->findOrFail($employeeId), ['full_name' => $name], User::query()->findOrFail($userId));

                return 'ok';
            case 'assign':
                [$userId, $employmentId, $positionId, $campusId] = $args;
                $app->make(EmployeeAssignmentService::class)->create(
                    EmploymentRecord::query()->findOrFail($employmentId),
                    ['starts_on' => SchoolTimezone::today($school)],
                    Position::query()->findOrFail($positionId),
                    User::query()->findOrFail($userId),
                    campus: Campus::query()->findOrFail($campusId),
                );

                return 'ok';
            case 'end-assignment':
                [$userId, $assignmentId, $endsOn] = $args;
                $app->make(EmployeeAssignmentService::class)->end(EmployeeAssignment::query()->findOrFail($assignmentId), $endsOn, User::query()->findOrFail($userId));

                return 'ok';
            case 'onboard':
                [$userId, $campusId, $positionId, $name] = $args;
                $app->make(EmployeeOnboardingService::class)->onboard($school, new EmployeeOnboardingData($name, null, null, 'full_time', SchoolTimezone::today($school), $campusId, $positionId, null), User::query()->findOrFail($userId));

                return 'ok';
            case 'set-primary':
                [$userId, $assignmentId] = $args;
                $app->make(EmployeeAssignmentService::class)->setPrimary(EmployeeAssignment::query()->findOrFail($assignmentId), User::query()->findOrFail($userId));

                return 'ok';
            case 'campus-inactivate':
                [$adminId, $campusId] = $args;
                $app->make(CampusAdministrationService::class)->update($school, $campusId, ['status' => 'inactive'], User::query()->findOrFail($adminId));

                return 'ok';
            default:
                throw new InvalidArgumentException("Unknown operation {$operation}");
        }
    }));
} catch (Throwable $e) {
    echo 'rejected:'.class_basename($e);
} finally {
    $context->clearAll();
}

<?php

use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use App\Domain\CurriculumDelivery\Application\TeacherDeliveryAccess;
use App\Domain\HR\Application\EmployeeLifecycleService;
use App\Domain\HR\Application\EmploymentService;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\TeachingAssignments\Application\ElectiveTeachingAssignmentService;
use App\Domain\TeachingAssignments\Application\Exceptions\TeachingAssignmentException;
use App\Domain\TeachingAssignments\Application\TeachingAssignmentService;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Tests\Support\Concurrency\HeldTransaction;

// S7 (ADR 0063 §47): one employment-end / teaching-ownership operation in a
// GENUINELY separate OS process for EmploymentEndTeachingOwnershipConcurrencyTest.
// HeldTransaction keeps the HOLDER's work uncommitted until released
// (ForcesConcurrentOverlap).
//
// Usage:
//   php employment-end-op.php end-employment  <schoolId> <actorId> <employeeId> <endsOn>
//   php employment-end-op.php rehire          <schoolId> <actorId> <employeeId> <startsOn>
//   php employment-end-op.php create-required <schoolId> <actorId> <employeeId> <sectionId> <offeringId> <startsOn> [<endsOn>]
//   php employment-end-op.php create-elective <schoolId> <actorId> <employeeId> <offeringId> <startsOn> [<endsOn>]
//   php employment-end-op.php teacher-delivery <schoolId> <userId> <sectionId> <offeringId> <date>

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$args = array_slice($argv, 1);
$operation = array_shift($args);
$context = $app->make(TenantContext::class);

$school = fn (string $id): School => School::query()->findOrFail($id);
$user = fn (string $id): User => User::query()->findOrFail($id);

try {
    echo HeldTransaction::run(function () use ($app, $operation, $args, $school, $user, $context): string {
        switch ($operation) {
            case 'end-employment':
                [$schoolId, $actorId, $employeeId, $endsOn] = $args;
                $s = $school($schoolId);
                $record = $context->withSchool($s, fn () => EmploymentRecord::query()->where('employee_id', $employeeId)->whereNull('ends_on')->firstOrFail());
                $app->make(EmploymentService::class)->end($record, $endsOn, $user($actorId));

                return 'employment-ended';
            case 'rehire':
                [$schoolId, $actorId, $employeeId, $startsOn] = $args;
                $s = $school($schoolId);
                $employee = $context->withSchool($s, fn () => Employee::query()->findOrFail($employeeId));
                $app->make(EmployeeLifecycleService::class)->rehire($employee, ['employment_type' => 'permanent', 'starts_on' => $startsOn, 'status' => 'active'], $user($actorId));

                return 'rehired';
            case 'create-required':
                [$schoolId, $actorId, $employeeId, $sectionId, $offeringId, $startsOn] = $args;

                return 'created:'.$app->make(TeachingAssignmentService::class)->create($school($schoolId), $employeeId, $sectionId, $offeringId, $startsOn, $args[6] ?? null, $user($actorId))->id;
            case 'create-elective':
                [$schoolId, $actorId, $employeeId, $offeringId, $startsOn] = $args;

                return 'created:'.$app->make(ElectiveTeachingAssignmentService::class)->create($school($schoolId), $employeeId, $offeringId, $startsOn, $args[5] ?? null, $user($actorId))->id;
            case 'teacher-delivery':
                // A real use-time authorization: ActingEmployee FOR SHARE, then the ownership FOR SHARE, held until commit.
                [$schoolId, $userId, $sectionId, $offeringId, $date] = $args;
                $s = $school($schoolId);
                [$section, $offering] = $context->withSchool($s, fn () => [Section::query()->findOrFail($sectionId), SubjectOffering::query()->findOrFail($offeringId)]);
                try {
                    DB::transaction(fn () => $app->make(TeacherDeliveryAccess::class)->guard($user($userId))->beforeStart($s, $section, $offering, $date));
                } catch (ModelNotFoundException) {
                    return 'refused:not-owned';
                } catch (RuntimeException $e) {
                    return 'refused:'.class_basename($e);
                }

                return 'authorized';
        }

        return 'unknown';
    });
} catch (TeachingAssignmentException $e) {
    echo 'rejected:'.$e->errorCode();
} catch (Throwable $e) {
    echo 'error:'.$e::class.':'.$e->getMessage();
} finally {
    $context->clearAll();
}

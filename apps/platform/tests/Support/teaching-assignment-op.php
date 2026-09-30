<?php

use App\Domain\HR\Application\EmployeeService;
use App\Domain\HR\Application\EmploymentService;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\TeachingAssignments\Application\Exceptions\TeachingAssignmentException;
use App\Domain\TeachingAssignments\Application\TeachingAssignmentService;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Tests\Support\Concurrency\HeldTransaction;

// Standalone bootstrap script for TeachingAssignmentConcurrencyTest (TCH.2,
// ADR 0063 section 20): one operation in a GENUINELY separate OS process,
// so two real PHP processes race against real PostgreSQL. Mirrors
// acting-employee-op.php.
//
// Usage:
//   php teaching-assignment-op.php create <schoolId> <actorId> <employeeId> <sectionId> <offeringId> <startsOn> [<endsOn>]
//   php teaching-assignment-op.php end    <schoolId> <actorId> <assignmentId> <endsOn> <reason>
//   php teaching-assignment-op.php end-employment <schoolId> <actorId> <employmentId> <endsOn>
//   php teaching-assignment-op.php archive <schoolId> <actorId> <employeeId>

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
        $assignments = $app->make(TeachingAssignmentService::class);

        switch ($operation) {
            case 'create':
                [$schoolId, $actorId, $employeeId, $sectionId, $offeringId, $startsOn] = $args;
                $assignment = $assignments->create($school($schoolId), $employeeId, $sectionId, $offeringId, $startsOn, $args[6] ?? null, $user($actorId));

                return 'created:'.$assignment->id;
            case 'end':
                [$schoolId, $actorId, $assignmentId, $endsOn, $reason] = $args;
                $assignments->end($school($schoolId), $assignmentId, $endsOn, $reason, $user($actorId));

                return 'ended';
            case 'end-employment':
                [$schoolId, $actorId, $employmentId, $endsOn] = $args;
                $s = $school($schoolId);
                $record = $context->withSchool($s, fn () => EmploymentRecord::query()->findOrFail($employmentId));
                $app->make(EmploymentService::class)->end($record, $endsOn, $user($actorId));

                return 'employment-ended';
            case 'archive':
                [$schoolId, $actorId, $employeeId] = $args;
                $s = $school($schoolId);
                $employee = $context->withSchool($s, fn () => Employee::query()->findOrFail($employeeId));
                $app->make(EmployeeService::class)->archive($employee, $user($actorId));

                return 'archived';
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

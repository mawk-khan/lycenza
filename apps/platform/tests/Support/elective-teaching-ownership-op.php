<?php

use App\Domain\TeachingAssignments\Application\ElectiveTeachingAssignmentService;
use App\Domain\TeachingAssignments\Application\Exceptions\TeachingAssignmentException;
use App\Domain\TeachingAssignments\Application\TeachingOwnership;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\Support\Concurrency\HeldTransaction;

// TCH-E (ADR 0063 section 45): one elective-ownership operation in a GENUINELY
// separate OS process for ElectiveTeachingOwnershipConcurrencyTest. HeldTransaction
// keeps the HOLDER's work uncommitted until released (ForcesConcurrentOverlap).
//
// Usage:
//   php elective-teaching-ownership-op.php create <schoolId> <actorId> <employeeId> <offeringId> <startsOn> [<endsOn>]
//   php elective-teaching-ownership-op.php end    <schoolId> <actorId> <assignmentId> <endsOn> <reason>
//   php elective-teaching-ownership-op.php hold   <schoolId> <employeeId> <offeringId> <date>

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$args = array_slice($argv, 1);
$operation = array_shift($args);
$context = $app->make(TenantContext::class);

$school = fn (string $id): School => School::query()->findOrFail($id);
$user = fn (string $id): User => User::query()->findOrFail($id);

try {
    echo HeldTransaction::run(function () use ($app, $operation, $args, $school, $user): string {
        switch ($operation) {
            case 'create':
                [$schoolId, $actorId, $employeeId, $offeringId, $startsOn] = $args;

                return 'created:'.$app->make(ElectiveTeachingAssignmentService::class)->create($school($schoolId), $employeeId, $offeringId, $startsOn, $args[5] ?? null, $user($actorId))->id;
            case 'end':
                [$schoolId, $actorId, $assignmentId, $endsOn, $reason] = $args;
                $app->make(ElectiveTeachingAssignmentService::class)->end($school($schoolId), $assignmentId, $endsOn, $reason, $user($actorId));

                return 'ended';
            case 'hold':
                [$schoolId, $employeeId, $offeringId, $date] = $args;

                // Its own transaction (a savepoint inside a held holder): the FOR SHARE lasts until the holder commits.
                return DB::transaction(fn () => $app->make(TeachingOwnership::class)->holdElective($school($schoolId), $employeeId, $offeringId, $date)) ? 'owned' : 'not-owned';
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

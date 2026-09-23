<?php

use App\Domain\HR\Application\EmployeeAssignmentService;
use App\Domain\HR\Infrastructure\EmployeeAssignment;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Tests\Support\Concurrency\HeldTransaction;

// Standalone bootstrap script for a real primary-assignment
// concurrency proof (Phase 8A closure correction, item 10): run in a
// GENUINELY separate OS process (via Symfony\Process::start(),
// non-blocking) so two real, independent PHP processes race
// EmployeeAssignmentService::setPrimary() for TWO DIFFERENT
// Assignments under the SAME EmploymentRecord -- not a sequential
// simulation. Mirrors set-assignment-manager.php's identical pattern.
//
// Usage: php set-assignment-primary.php <schoolId> <assignmentId> <actorId>
// <actorId> is a User already holding hr.employees.assignments.manage
// at <schoolId>, created and committed by the parent test before any
// subprocess spawns.
// Prints "ok:<assignmentId>" on success, or "rejected:<class>" on
// failure (a ConcurrentPrimaryAssignmentConflictException is the
// expected rejection for exactly one of the two racing processes).

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

[, $schoolId, $assignmentId, $actorId] = $argv;

$context = $app->make(TenantContext::class);
$school = School::query()->findOrFail($schoolId);
$context->set($school);

try {
    $assignment = EmployeeAssignment::query()->findOrFail($assignmentId);
    $actor = User::query()->findOrFail($actorId);

    $result = HeldTransaction::run(fn () => $app->make(EmployeeAssignmentService::class)->setPrimary($assignment, $actor));
    echo 'ok:'.$result->id;
} catch (Throwable $e) {
    echo 'rejected:'.$e::class;
} finally {
    $context->clearAll();
}

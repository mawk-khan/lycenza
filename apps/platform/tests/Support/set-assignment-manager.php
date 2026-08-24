<?php

use App\Domain\HR\Application\ReportingHierarchyService;
use App\Domain\HR\Infrastructure\EmployeeAssignment;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;

// Standalone bootstrap script for a real reporting-hierarchy
// concurrency proof: run in a GENUINELY separate OS process (via
// Symfony\Process::start(), non-blocking) so two real, independent PHP
// processes race ReportingHierarchyService::setManager() for the SAME
// pair of Assignments in OPPOSITE directions (Process 1: A's manager
// -> B; Process 2: B's manager -> A) against real PostgreSQL -- not a
// sequential simulation. Mirrors activate-academic-year.php's/
// create-employee.php's identical pattern.
//
// Usage: php set-assignment-manager.php <schoolId> <subordinateAssignmentId> <managerAssignmentId> <actorId>
// <actorId> is a User already holding hr.employees.assignments.manage
// at <schoolId>, created and committed by the parent test before any
// subprocess spawns (Phase 8A.10 -- setManager() now requires and
// authorizes a real actor).
// Prints "ok:<newManagerAssignmentId>" on success, or "rejected:<class>"
// on failure (a ReportingHierarchyCycleException is the expected
// rejection for exactly one of the two racing processes).

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

[, $schoolId, $subordinateAssignmentId, $managerAssignmentId, $actorId] = $argv;

$context = $app->make(TenantContext::class);
$school = School::query()->findOrFail($schoolId);
$context->set($school);

try {
    $subordinate = EmployeeAssignment::query()->findOrFail($subordinateAssignmentId);
    $manager = EmployeeAssignment::query()->findOrFail($managerAssignmentId);
    $actor = User::query()->findOrFail($actorId);

    $result = $app->make(ReportingHierarchyService::class)->setManager($subordinate, $manager, $actor);
    echo 'ok:'.$result->manager_assignment_id;
} catch (Throwable $e) {
    echo 'rejected:'.$e::class;
} finally {
    $context->clearAll();
}

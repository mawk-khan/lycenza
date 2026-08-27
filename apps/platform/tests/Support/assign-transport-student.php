<?php

use App\Domain\Students\Infrastructure\Student;
use App\Domain\Transport\Application\TransportStudentAssignmentService;
use App\Domain\Transport\Infrastructure\TransportRoute;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;

// Standalone bootstrap script for
// TransportStudentAssignmentConcurrencyTest: run in a GENUINELY
// separate OS process (via Symfony\Process::start(), non-blocking) so
// two real, independent PHP processes race
// TransportStudentAssignmentService::assign() for the SAME Student
// against real PostgreSQL -- not a sequential simulation. Mirrors
// checkout-library-copy.php's identical pattern.
//
// Usage: php assign-transport-student.php <schoolId> <studentId> <routeId>

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

[, $schoolId, $studentId, $routeId] = $argv;

$context = $app->make(TenantContext::class);
$school = School::query()->findOrFail($schoolId);
$context->set($school);

try {
    $student = Student::query()->findOrFail($studentId);
    $route = TransportRoute::query()->findOrFail($routeId);
    $app->make(TransportStudentAssignmentService::class)->assign($student, $route, null, null);
    echo 'assigned';
} catch (Throwable $e) {
    echo 'rejected:'.$e::class;
} finally {
    $context->clearAll();
}

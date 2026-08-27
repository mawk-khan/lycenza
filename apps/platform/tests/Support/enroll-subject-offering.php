<?php

use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use App\Domain\Students\Application\StudentSubjectEnrollmentService;
use App\Domain\Students\Infrastructure\Student;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;

// Standalone bootstrap script for the Phase 1F.2 elective-group
// concurrency tests: run in a GENUINELY separate OS process (via
// Symfony\Process::start(), non-blocking) so two real, independent PHP
// processes race StudentSubjectEnrollmentService::enroll() for two
// DIFFERENT SubjectOfferings for the SAME StudentEnrollment against
// real PostgreSQL -- not a sequential simulation. Mirrors
// activate-academic-year.php's identical pattern.
//
// Usage: php enroll-subject-offering.php <schoolId> <studentId> <subjectOfferingId> <startsOn>

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

[, $schoolId, $studentId, $subjectOfferingId, $startsOn] = $argv;

$context = $app->make(TenantContext::class);
$school = School::query()->findOrFail($schoolId);
$context->set($school);

try {
    $student = Student::query()->findOrFail($studentId);
    $offering = SubjectOffering::query()->findOrFail($subjectOfferingId);
    $enrollment = $app->make(StudentSubjectEnrollmentService::class)->enroll($student, $offering, $startsOn);
    echo 'enrolled:'.$enrollment->id;
} catch (Throwable $e) {
    echo 'rejected:'.$e::class;
} finally {
    $context->clearAll();
}

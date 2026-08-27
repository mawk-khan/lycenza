<?php

use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use App\Domain\Students\Application\StudentSubjectEnrollmentService;
use App\Domain\Students\Infrastructure\StudentSubjectEnrollment;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;

// Standalone bootstrap script for the Phase 1F.2
// enroll-vs-transfer elective-group concurrency test: run in a
// GENUINELY separate OS process, racing
// StudentSubjectEnrollmentService::transfer() against a concurrent
// enroll() (enroll-subject-offering.php) for the SAME ElectiveGroup.
// Mirrors enroll-subject-offering.php's identical pattern.
//
// Usage: php transfer-subject-offering.php <schoolId> <sourceEnrollmentId> <targetSubjectOfferingId> <effectiveDate>

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

[, $schoolId, $sourceEnrollmentId, $targetSubjectOfferingId, $effectiveDate] = $argv;

$context = $app->make(TenantContext::class);
$school = School::query()->findOrFail($schoolId);
$context->set($school);

try {
    $source = StudentSubjectEnrollment::query()->findOrFail($sourceEnrollmentId);
    $target = SubjectOffering::query()->findOrFail($targetSubjectOfferingId);
    $transferred = $app->make(StudentSubjectEnrollmentService::class)->transfer($source, $target, $effectiveDate);
    echo 'transferred:'.$transferred->id;
} catch (Throwable $e) {
    echo 'rejected:'.$e::class;
} finally {
    $context->clearAll();
}

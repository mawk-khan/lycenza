<?php

use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\Students\Application\StudentEnrollmentService;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;

// Standalone bootstrap script for
// OppositeDirectionTransferConcurrencyTest: one intra-year placement
// transfer in a genuinely separate OS process. Two of these run
// concurrently in OPPOSITE directions (A->B and B->A) to prove the new
// Section-before-Enrollment, ascending-Section-id lock order cannot
// deadlock.
//
// Usage: php transfer-student-placement.php <schoolId> <sourceEnrollmentId> <targetSectionId> <rollNumber> <effectiveDate> <actorId>

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

[, $schoolId, $sourceEnrollmentId, $targetSectionId, $rollNumber, $effectiveDate, $actorId] = $argv;

$context = $app->make(TenantContext::class);
$school = School::query()->findOrFail($schoolId);
$context->set($school);

try {
    $source = StudentEnrollment::query()->findOrFail($sourceEnrollmentId);
    $target = Section::query()->findOrFail($targetSectionId);
    $actor = User::query()->findOrFail($actorId);

    $created = $app->make(StudentEnrollmentService::class)
        ->transferPlacement($source, $target, $rollNumber, $effectiveDate, $actor);

    echo 'transferred:'.$created->id;
} catch (Throwable $e) {
    echo 'rejected:'.$e::class.':'.$e->getMessage();
} finally {
    $context->clearAll();
}

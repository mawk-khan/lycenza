<?php

use App\Domain\Students\Application\StudentProcessingAuthorizationReadService;
use App\Domain\Students\Domain\ProcessingAuthorizationPurpose;
use App\Domain\Students\Infrastructure\Student;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

// Standalone bootstrap script for ProcessingAuthorizationConcurrencyTest
// (the "read-lock vs withdrawal" race): opens a real transaction, calls
// the lock-capable read seam (which locks the Student row and the
// qualifying grant row), holds the transaction open for $sleepSeconds,
// then commits -- so a genuinely separate OS process attempting a
// terminal action against the SAME grant concurrently must block on
// the row lock until this process's transaction ends.
//
// Usage: php lock-processing-authorization-for-processing.php <schoolId> <studentId> <sleepSeconds> [barrierFile]

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

[, $schoolId, $studentId, $sleepSeconds] = $argv;
$barrierFile = $argv[4] ?? null;

if ($barrierFile !== null) {
    while (! file_exists($barrierFile)) {
        usleep(200);
    }
}

$school = School::query()->findOrFail($schoolId);
$context = $app->make(TenantContext::class);
// RLS is enforced at the database level regardless of Eloquent scope
// -- this lookup must run with the real Postgres session variable
// set, exactly like every production code path.
$student = $context->withSchool($school, fn () => Student::query()->where('id', $studentId)->firstOrFail());
$readService = $app->make(StudentProcessingAuthorizationReadService::class);

$id = DB::transaction(function () use ($readService, $school, $student, $sleepSeconds) {
    $lockedId = $readService->lockQualifyingAuthorizationIdForProcessing($school, $student, ProcessingAuthorizationPurpose::AcademicRecords);
    sleep((int) $sleepSeconds);

    return $lockedId;
});

echo 'locked:'.$id;

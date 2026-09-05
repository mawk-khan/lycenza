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
// qualifying grant row), then signals lock acquisition EXPLICITLY --
// via a file the parent test waits for -- rather than relying on the
// parent inferring "the lock must be held by now" from elapsed time.
// Only after a separate release signal appears does this process
// commit, so a genuinely separate OS process attempting a terminal
// action against the SAME grant concurrently must block on the row
// lock for as long as the parent test chooses, provably, not
// approximately.
//
// Usage: php lock-processing-authorization-for-processing.php <schoolId> <studentId> <startBarrierFile> <lockAcquiredFile> <releaseFile>

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

[, $schoolId, $studentId, $startBarrierFile, $lockAcquiredFile, $releaseFile] = $argv;

while (! file_exists($startBarrierFile)) {
    usleep(200);
}

$school = School::query()->findOrFail($schoolId);
$context = $app->make(TenantContext::class);
// RLS is enforced at the database level regardless of Eloquent scope
// -- this lookup must run with the real Postgres session variable
// set, exactly like every production code path.
$student = $context->withSchool($school, fn () => Student::query()->where('id', $studentId)->firstOrFail());
$readService = $app->make(StudentProcessingAuthorizationReadService::class);

$id = DB::transaction(function () use ($readService, $school, $student, $lockAcquiredFile, $releaseFile) {
    // At this point the Student row lock (and the qualifying grant's
    // row lock) are genuinely held by THIS transaction -- the call
    // above has already returned, meaning PostgreSQL granted both
    // locks. Only NOW do we signal "lock acquired" -- never before the
    // locking call itself has returned, unlike the prior version's
    // fixed sleep, which only ever approximated "the lock should be
    // held by now."
    $lockedId = $readService->lockQualifyingAuthorizationIdForProcessing($school, $student, ProcessingAuthorizationPurpose::AcademicRecords);

    touch($lockAcquiredFile);

    while (! file_exists($releaseFile)) {
        usleep(200);
    }

    return $lockedId;
});

echo 'locked:'.$id;

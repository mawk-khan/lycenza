<?php

use App\Domain\AcademicStructure\Application\AcademicYearService;
use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;

// Standalone bootstrap script for
// AttendanceVersusAcademicYearCloseConcurrencyTest: closes an
// AcademicYear through the AUTHORITATIVE AcademicYearService in a
// genuinely separate OS process, racing a concurrent Attendance
// submission that holds a SHARED (FOR SHARE) lock on that same
// AcademicYear row.
//
// Usage: php close-academic-year.php <schoolId> <academicYearId> <actorId>

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

[, $schoolId, $academicYearId, $actorId] = $argv;

$context = $app->make(TenantContext::class);
$school = School::query()->findOrFail($schoolId);
$context->set($school);

try {
    $year = AcademicYear::query()->findOrFail($academicYearId);
    $actor = User::query()->findOrFail($actorId);

    $closed = $app->make(AcademicYearService::class)->close($year, $actor);

    echo 'closed:'.$closed->status;
} catch (Throwable $e) {
    echo 'rejected:'.$e::class.'|'.$e->getMessage();
} finally {
    $context->clearAll();
}

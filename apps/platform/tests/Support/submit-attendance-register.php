<?php

use App\Domain\Attendance\Application\AttendanceSubmissionService;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

// Standalone bootstrap script: runs one authoritative Attendance
// register submission in a GENUINELY separate OS process (via
// Symfony\Process::start(), non-blocking) so two real, independent PHP
// processes race against real PostgreSQL -- never a sequential
// simulation. Mirrors create-timetable-entry.php's identical pattern.
//
// Usage: php submit-attendance-register.php <schoolId> <timetableEntryId> <date> <actorId> <enrollmentId:status,...>

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

[, $schoolId, $timetableEntryId, $date, $actorId, $records] = $argv;

$context = $app->make(TenantContext::class);
$school = School::query()->findOrFail($schoolId);
$context->set($school);

$payload = [];
foreach (explode(',', $records) as $pair) {
    if ($pair === '') {
        continue;
    }
    [$enrollmentId, $status] = explode(':', $pair);
    $payload[] = ['student_enrollment_id' => $enrollmentId, 'status' => $status];
}

try {
    $actor = User::query()->findOrFail($actorId);
    $service = $app->make(AttendanceSubmissionService::class);

    $session = $service->guarded(fn () => DB::transaction(
        fn () => $service->submit($school, $timetableEntryId, $date, $payload, $actor),
    ));

    echo 'submitted:'.$session->id;
} catch (Throwable $e) {
    echo 'rejected:'.$e::class.'|'.$e->getMessage();
} finally {
    $context->clearAll();
}

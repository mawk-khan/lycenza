<?php

use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\Timetable\Application\TimetableScheduleService;
use App\Domain\Timetable\Infrastructure\TimetableEntry;
use App\Domain\Timetable\Infrastructure\TimetablePeriod;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;

// Standalone bootstrap script for
// AttendanceVersusTimetableEntryConcurrencyTest: repoints an existing
// TimetableEntry through the REAL TimetableScheduleService in a
// genuinely separate OS process, racing a concurrent Attendance
// submission that snapshots that same entry under SELECT ... FOR
// UPDATE. Proves the snapshot can only ever reflect ONE coherent
// version of the entry.
//
// Usage: php mutate-timetable-entry.php <schoolId> <entryId> <offeringId> <sectionId> <teacherId> <periodId> <dayOfWeek> <actorId>

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

[, $schoolId, $entryId, $offeringId, $sectionId, $teacherId, $periodId, $dayOfWeek, $actorId] = $argv;

$context = $app->make(TenantContext::class);
$school = School::query()->findOrFail($schoolId);
$context->set($school);

try {
    $entry = TimetableEntry::query()->findOrFail($entryId);
    $offering = SubjectOffering::query()->findOrFail($offeringId);
    $section = Section::query()->findOrFail($sectionId);
    $teacher = Employee::query()->findOrFail($teacherId);
    $period = TimetablePeriod::query()->findOrFail($periodId);
    $actor = User::query()->findOrFail($actorId);

    $app->make(TimetableScheduleService::class)
        ->update($entry, $offering, $section, $teacher, null, $period, (int) $dayOfWeek, $actor);

    echo 'mutated:'.$entry->id;
} catch (Throwable $e) {
    echo 'rejected:'.$e::class;
} finally {
    $context->clearAll();
}

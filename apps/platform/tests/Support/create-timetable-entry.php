<?php

use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\Timetable\Application\TimetableScheduleService;
use App\Domain\Timetable\Infrastructure\TimetablePeriod;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;

// Standalone bootstrap script for TimetableEntryConcurrencyTest: run in
// a GENUINELY separate OS process (via Symfony\Process::start(),
// non-blocking) so two real, independent PHP processes race
// TimetableScheduleService::create() for the SAME
// School/teacher/day-of-week/Period against real PostgreSQL -- not a
// sequential simulation. Proves the teacher-double-booking guarantee
// (`timetable_entries_teacher_slot_unique`) under real concurrent
// creation. Mirrors issue-inventory-stock.php's identical pattern.
//
// Usage: php create-timetable-entry.php <schoolId> <subjectOfferingId> <sectionId> <teacherId> <periodId> <dayOfWeek> <actorId>

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

[, $schoolId, $subjectOfferingId, $sectionId, $teacherId, $periodId, $dayOfWeek, $actorId] = $argv;

$context = $app->make(TenantContext::class);
$school = School::query()->findOrFail($schoolId);
$context->set($school);

try {
    $subjectOffering = SubjectOffering::query()->findOrFail($subjectOfferingId);
    $section = Section::query()->findOrFail($sectionId);
    $teacher = Employee::query()->findOrFail($teacherId);
    $period = TimetablePeriod::query()->findOrFail($periodId);
    $actor = User::query()->findOrFail($actorId);

    $entry = $app->make(TimetableScheduleService::class)->create(
        $school, $subjectOffering, $section, $teacher, null, $period, (int) $dayOfWeek, $actor,
    );
    echo 'created:'.$entry->id;
} catch (Throwable $e) {
    echo 'rejected:'.$e::class;
} finally {
    $context->clearAll();
}

<?php

use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\Students\Application\StudentEnrollmentService;
use App\Domain\Students\Infrastructure\Student;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;

// Standalone bootstrap script for
// AttendanceVersusEnrollmentConcurrencyTest: enrolls an EXISTING
// Student into a Section in a genuinely separate OS process, racing a
// concurrent authoritative Attendance submission for that same Section.
// Both sides serialize on the SAME Section row lock.
//
// Usage: php enroll-student-placement.php <schoolId> <studentId> <sectionId> <rollNumber> <startsOn> <actorId>

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

[, $schoolId, $studentId, $sectionId, $rollNumber, $startsOn, $actorId] = $argv;

$context = $app->make(TenantContext::class);
$school = School::query()->findOrFail($schoolId);
$context->set($school);

try {
    $student = Student::query()->findOrFail($studentId);
    $section = Section::query()->findOrFail($sectionId);
    $actor = User::query()->findOrFail($actorId);

    $enrollment = $app->make(StudentEnrollmentService::class)
        ->enroll($student, $section, $rollNumber, $startsOn, $actor);

    echo 'enrolled:'.$enrollment->id;
} catch (Throwable $e) {
    echo 'rejected:'.$e::class;
} finally {
    $context->clearAll();
}

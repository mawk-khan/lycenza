<?php

use App\Domain\AcademicStructure\Application\AcademicYearService;
use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\Students\Application\StudentEnrollmentService;
use App\Domain\Students\Infrastructure\Student;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Tests\Support\Concurrency\HeldTransaction;

// SIS 5.1 DraftCalendarConcurrencyTest: one draft-year date correction, one
// activation or one placement in a GENUINELY separate OS process, run through
// HeldTransaction so the parent can force and observe the overlap. A
// correction reads the year's version FIRST, like a form opened before the
// other operation.
//
// Usage: php draft-calendar-op.php correct <schoolId> <yearId> <startsOn> <endsOn> <userId>
//        php draft-calendar-op.php activate <schoolId> <yearId>
//        php draft-calendar-op.php enroll <schoolId> <studentId> <sectionId> <startsOn>

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

[, $op, $schoolId, $id] = $argv;

$school = School::query()->findOrFail($schoolId);
$context = $app->make(TenantContext::class);
$context->set($school);

try {
    if ($op === 'correct') {
        $version = AcademicYear::query()->findOrFail($id)->updated_at->toIso8601String();
        $actor = User::query()->findOrFail($argv[6]);
        HeldTransaction::run(fn () => $app->make(AcademicYearService::class)->correctDraftDates($school, $id, ['starts_on' => $argv[4], 'ends_on' => $argv[5], 'expected_updated_at' => $version], $actor));
        echo 'corrected';
    } elseif ($op === 'activate') {
        $year = AcademicYear::query()->findOrFail($id);
        HeldTransaction::run(fn () => $app->make(AcademicYearService::class)->activate($year));
        echo 'activated';
    } else {
        $student = Student::query()->findOrFail($id);
        $section = Section::query()->findOrFail($argv[4]);
        HeldTransaction::run(fn () => $app->make(StudentEnrollmentService::class)->enroll($student, $section, '01', $argv[5]));
        echo 'enrolled';
    }
} catch (Throwable $e) {
    echo 'rejected:'.$e::class;
} finally {
    $context->clearAll();
}

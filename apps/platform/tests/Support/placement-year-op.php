<?php

use App\Domain\AcademicStructure\Application\AcademicYearService;
use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\Students\Application\StudentEnrollmentService;
use App\Domain\Students\Infrastructure\Student;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Tests\Support\Concurrency\HeldTransaction;

// SIS 4.1 PlacementYearConcurrencyTest: one placement or one Academic Year
// close in a GENUINELY separate OS process, run through HeldTransaction so the
// parent can force and observe the overlap.
//
// Usage: php placement-year-op.php enroll <schoolId> <studentId> <sectionId> <startsOn>
//        php placement-year-op.php close <schoolId> <academicYearId>

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

[, $op, $schoolId, $id] = $argv;

$context = $app->make(TenantContext::class);
$context->set(School::query()->findOrFail($schoolId));

try {
    if ($op === 'enroll') {
        $student = Student::query()->findOrFail($id);
        $section = Section::query()->findOrFail($argv[4]);
        HeldTransaction::run(fn () => $app->make(StudentEnrollmentService::class)->enroll($student, $section, '01', $argv[5]));
        echo 'enrolled';
    } else {
        $year = AcademicYear::query()->findOrFail($id);
        HeldTransaction::run(fn () => $app->make(AcademicYearService::class)->close($year));
        echo 'closed';
    }
} catch (Throwable $e) {
    echo 'rejected:'.$e::class;
} finally {
    $context->clearAll();
}

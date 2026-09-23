<?php

use App\Domain\AcademicStructure\Application\AcademicYearService;
use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Tests\Support\Concurrency\HeldTransaction;

// Standalone bootstrap script for AcademicYearActivationConcurrencyTest:
// run in a GENUINELY separate OS process (via Symfony\Process::start(),
// non-blocking) so two real, independent PHP processes race
// AcademicYearService::activate() for two DIFFERENT Academic Years of
// the SAME School against real PostgreSQL -- not a sequential
// simulation. Mirrors run-webhook-delivery.php's identical pattern.
//
// Usage: php activate-academic-year.php <schoolId> <academicYearId>

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

[, $schoolId, $academicYearId] = $argv;

$context = $app->make(TenantContext::class);
$school = School::query()->findOrFail($schoolId);
$context->set($school);

try {
    $year = AcademicYear::query()->findOrFail($academicYearId);
    HeldTransaction::run(fn () => $app->make(AcademicYearService::class)->activate($year));
    echo 'activated';
} catch (Throwable $e) {
    echo 'rejected:'.$e::class;
} finally {
    $context->clearAll();
}

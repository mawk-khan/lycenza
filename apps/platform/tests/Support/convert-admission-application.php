<?php

use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\Admissions\Application\AdmissionConversionService;
use App\Domain\Admissions\Infrastructure\AdmissionApplication;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;

// Standalone bootstrap script for AdmissionConversionConcurrencyTest:
// run in a GENUINELY separate OS process (via Symfony\Process::start(),
// non-blocking) so two real, independent PHP processes race
// AdmissionConversionService::convert() for the SAME
// AdmissionApplication against real PostgreSQL -- not a sequential
// simulation. Mirrors tests/Support/activate-academic-year.php's
// identical pattern (Phase 0D section 80 / this checkpoint's item 71).
//
// Usage: php convert-admission-application.php <schoolId> <applicationId> <sectionId> <studentNumber> <rollNumber> <startsOn>

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

[, $schoolId, $applicationId, $sectionId, $studentNumber, $rollNumber, $startsOn] = $argv;

$context = $app->make(TenantContext::class);
$school = School::query()->findOrFail($schoolId);
$context->set($school);

try {
    $application = AdmissionApplication::query()->findOrFail($applicationId);
    $section = Section::query()->findOrFail($sectionId);
    $app->make(AdmissionConversionService::class)->convert($application, $studentNumber, $section, $rollNumber, $startsOn);
    echo 'converted';
} catch (Throwable $e) {
    echo 'rejected:'.$e::class;
} finally {
    $context->clearAll();
}

<?php

use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\Admissions\Application\AdmissionConversionService;
use App\Domain\Admissions\Application\AdmissionFeeSelectionService;
use App\Domain\Admissions\Infrastructure\AdmissionApplication;
use App\Domain\Fees\Application\Sources\FeeSelectionSource;
use App\Domain\Fees\Application\Sources\FeeSourceSelectionService;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Tests\Support\Concurrency\HeldTransaction;

// OPF.3 (ADR 0067): a genuinely separate OS process for
// AdmissionFeeSelectionConcurrencyTest. HeldTransaction makes the holder keep
// its writes uncommitted until released, so the contender is observed blocked
// on them (Tests\Concerns\ForcesConcurrentOverlap).
//
// Usage:
//   php admission-fee-op.php convert <schoolId> <applicationId> <sectionId> <studentNumber> <rollNumber>
//   php admission-fee-op.php link <schoolId> <applicationId>
//   php admission-fee-op.php select <schoolId> <studentId> <academicYearId> <feeHeadId> <applicationId>

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$op = $argv[1];
$school = School::query()->findOrFail($argv[2]);
$context = $app->make(TenantContext::class);
$context->set($school);

try {
    echo HeldTransaction::run(function () use ($app, $op, $school, $argv): string {
        if ($op === 'convert') {
            $application = AdmissionApplication::query()->findOrFail($argv[3]);
            $converted = $app->make(AdmissionConversionService::class)->convert($application, $argv[5], Section::query()->findOrFail($argv[4]), $argv[6], '2026-06-01')->application;

            return 'converted:'.$converted->converted_student_id;
        }
        if ($op === 'link') {
            return $app->make(AdmissionFeeSelectionService::class)->recordForConversion(AdmissionApplication::query()->findOrFail($argv[3]), null);
        }

        $result = $app->make(FeeSourceSelectionService::class)->selectForSource($school, $argv[3], $argv[4], $argv[5], FeeSelectionSource::admissions($argv[6]), null);

        return "{$result->outcome}:{$result->selectionId}";
    });
} catch (Throwable $e) {
    echo 'error:'.$e::class;
} finally {
    $context->clearAll();
}

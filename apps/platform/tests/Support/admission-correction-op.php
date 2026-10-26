<?php

use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\Admissions\Application\AdmissionConversionService;
use App\Domain\Admissions\Application\ApplicantService;
use App\Domain\Admissions\Infrastructure\AdmissionApplication;
use App\Domain\Admissions\Infrastructure\Applicant;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Tests\Support\Concurrency\HeldTransaction;

// SIS 3.1 AdmissionCorrectionConcurrencyTest: one Applicant correction or one
// conversion in a GENUINELY separate OS process, run through HeldTransaction
// so the parent can force and observe the overlap.
//
// Usage: php admission-correction-op.php convert <schoolId> <applicationId> <sectionId> <studentNumber>
//        php admission-correction-op.php correct <schoolId> <applicantId> <firstName>

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

[, $op, $schoolId, $id] = $argv;

$context = $app->make(TenantContext::class);
$context->set(School::query()->findOrFail($schoolId));

try {
    if ($op === 'convert') {
        $application = AdmissionApplication::query()->findOrFail($id);
        $section = Section::query()->findOrFail($argv[4]);
        HeldTransaction::run(fn () => $app->make(AdmissionConversionService::class)->convert($application, $argv[5], $section, '01', '2026-06-01'));
        echo 'converted';
    } else {
        $applicant = Applicant::query()->findOrFail($id);
        $version = $applicant->updated_at->toIso8601String();
        HeldTransaction::run(fn () => $app->make(ApplicantService::class)->correct($applicant, [
            'first_name' => $argv[4], 'middle_name' => $applicant->middle_name, 'last_name' => $applicant->last_name,
            'date_of_birth' => $applicant->date_of_birth->toDateString(),
        ], $version));
        echo 'corrected';
    }
} catch (Throwable $e) {
    echo 'rejected:'.$e::class;
} finally {
    $context->clearAll();
}

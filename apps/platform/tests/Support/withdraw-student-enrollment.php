<?php

use App\Domain\Students\Application\StudentEnrollmentService;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Tests\Support\Concurrency\HeldTransaction;

// FEE.2 FeeAssessmentConcurrencyTest: an enrollment withdrawal in a separate
// OS process, racing a fee assessment item.
//
// Usage: php withdraw-student-enrollment.php <schoolId> <enrollmentId> <endedOn>

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $schoolId, $enrollmentId, $endedOn] = $argv;
$context = $app->make(TenantContext::class);
$school = School::query()->findOrFail($schoolId);
$context->set($school);

try {
    $enrollment = StudentEnrollment::query()->findOrFail($enrollmentId);
    HeldTransaction::run(fn () => $app->make(StudentEnrollmentService::class)->withdraw($enrollment, $endedOn));
    echo 'withdrawn';
} catch (Throwable $e) {
    echo 'rejected:'.$e::class;
} finally {
    $context->clearAll();
}

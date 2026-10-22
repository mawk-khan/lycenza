<?php

use App\Domain\Students\Application\StudentEnrollmentService;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Models\School;
use App\Models\User;
use App\Support\Auth\Mfa\FreshMfaProof;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Tests\Support\Concurrency\HeldTransaction;

// FEE.2 FeeAssessmentConcurrencyTest: an enrollment withdrawal in a separate
// OS process, racing a fee assessment item.
//
// Usage: php withdraw-student-enrollment.php <schoolId> <enrollmentId> <endedOn>

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $schoolId, $enrollmentId, $endedOn, $actorId] = $argv;
$context = $app->make(TenantContext::class);
$school = School::query()->findOrFail($schoolId);
$context->set($school);

try {
    $enrollment = StudentEnrollment::query()->findOrFail($enrollmentId);
    // ORG.8E: a whole-School actor, a closed reason and a fresh-MFA proof (as the transport mints one).
    $actor = User::query()->findOrFail($actorId);
    HeldTransaction::run(fn () => $app->make(StudentEnrollmentService::class)->withdraw($enrollment, $endedOn, 'left_school', $actor, new FreshMfaProof($actor->id, CarbonImmutable::now())));
    echo 'withdrawn';
} catch (Throwable $e) {
    echo 'rejected:'.$e::class;
} finally {
    $context->clearAll();
}

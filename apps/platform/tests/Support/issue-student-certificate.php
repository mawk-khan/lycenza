<?php

use App\Domain\Students\Application\Certificates\StudentCertificateService;
use App\Domain\Students\Application\Certificates\StudentCertificateType;
use App\Models\School;
use App\Models\User;
use App\Support\Auth\Mfa\FreshMfaProof;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Tests\Support\Concurrency\HeldTransaction;

// Standalone bootstrap script for StudentCertificateConcurrencyTest (SIS.EXP-4):
// run in a GENUINELY separate OS process so two real PHP processes race
// StudentCertificateService::issue() against real PostgreSQL.
//
// Usage: php issue-student-certificate.php <schoolId> <studentId> <actorId> <type> <previewFingerprint> <formKey>

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

[, $schoolId, $studentId, $actorId, $type, $fingerprint, $formKey] = $argv;

$context = $app->make(TenantContext::class);
$school = School::query()->findOrFail($schoolId);
$actor = User::query()->findOrFail($actorId);

try {
    $certificate = HeldTransaction::run(fn () => $app->make(StudentCertificateService::class)->issue(
        $school, $actor, $studentId, StudentCertificateType::from($type), $fingerprint, $formKey, new FreshMfaProof($actor->id, CarbonImmutable::now()),
    ));
    echo 'issued:'.$certificate->display_number;
} catch (Throwable $e) {
    echo 'rejected:'.$e::class;
} finally {
    $context->clearAll();
}

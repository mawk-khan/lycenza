<?php

use App\Domain\Students\Application\Certificates\StudentCertificateService;
use App\Domain\Students\Application\Certificates\StudentCertificateVoidReason;
use App\Domain\Students\Application\Documents\StudentDocumentArchiveReason;
use App\Domain\Students\Application\Documents\StudentDocumentService;
use App\Models\School;
use App\Models\User;
use App\Support\Auth\Mfa\FreshMfaProof;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Tests\Support\Concurrency\HeldTransaction;

// Standalone bootstrap script for StudentTerminalActionConcurrencyTest
// (SIS.HARDEN-1, R-7): run in a GENUINELY separate OS process so two real PHP
// processes race a certificate void or a Student document archive against
// real PostgreSQL.
//
// Usage: php terminal-student-record-op.php <void|archive> <schoolId> <studentId> <actorId> <recordId> [<expectedVersion>]

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

[, $op, $schoolId, $studentId, $actorId, $recordId] = $argv;

$context = $app->make(TenantContext::class);
$school = School::query()->findOrFail($schoolId);
$actor = User::query()->findOrFail($actorId);
$proof = new FreshMfaProof($actor->id, CarbonImmutable::now());

try {
    HeldTransaction::run(fn () => $op === 'void'
        ? $app->make(StudentCertificateService::class)->void($school, $actor, $studentId, $recordId, StudentCertificateVoidReason::from('issued_in_error'), $proof)
        : $app->make(StudentDocumentService::class)->archive($school, $actor, $studentId, $recordId, StudentDocumentArchiveReason::from('uploaded_in_error'), (int) $argv[6], $proof));
    echo 'done';
} catch (Throwable $e) {
    echo 'rejected:'.$e::class.(method_exists($e, 'getStatusCode') ? ':'.$e->getStatusCode() : '');
} finally {
    $context->clearAll();
}

<?php

use App\Domain\Students\Application\Import\StudentGuardianImport;
use App\Models\School;
use App\Models\User;
use App\Support\Auth\Mfa\FreshMfaProof;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Tests\Support\Concurrency\HeldTransaction;

// Standalone bootstrap script for StudentGuardianImportConcurrencyTest (SIS.EXP-5):
// a GENUINELY separate OS process commits a signed preview.
//
// Usage: php commit-student-import.php <schoolId> <actorId> <previewJsonPath>

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

[, $schoolId, $actorId, $previewPath] = $argv;
$preview = json_decode((string) file_get_contents($previewPath), true, flags: JSON_THROW_ON_ERROR);

$context = $app->make(TenantContext::class);
$school = School::query()->findOrFail($schoolId);
$actor = User::query()->findOrFail($actorId);

try {
    $batch = HeldTransaction::run(fn () => $app->make(StudentGuardianImport::class)->commit(
        $school, $actor, $preview['payload'], $preview['issuedAt'], $preview['resultDigest'], $preview['signature'], true, new FreshMfaProof($actor->id, CarbonImmutable::now()),
    ));
    echo 'committed:'.$batch->id;
} catch (Throwable $e) {
    echo 'rejected:'.$e::class;
} finally {
    $context->clearAll();
}

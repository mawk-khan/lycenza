<?php

use App\Domain\Payroll\Statutory\Application\Admin\EmployeePfStatusAdminService;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;

// Standalone bootstrap script for StatutoryPfStatusMutationConcurrencyTest
// (Checkpoint 9.6I, Section 6): run in a GENUINELY separate OS process
// (via Symfony\Process::start(), non-blocking) so real, independent
// PHP processes race EmployeePfStatusAdminService::configure() against
// the SAME EmploymentRecord's PF status row over real PostgreSQL.
//
// Usage: php configure-pf-status.php <schoolId> <employmentRecordId> <actorUserId> <payloadJson>

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

[, $schoolId, $employmentRecordId, $actorUserId, $payloadJson] = $argv;

$context = $app->make(TenantContext::class);
$school = School::query()->findOrFail($schoolId);
$context->set($school);

try {
    $actor = User::query()->findOrFail($actorUserId);
    $payload = json_decode($payloadJson, true, flags: JSON_THROW_ON_ERROR);
    $status = $app->make(EmployeePfStatusAdminService::class)->configure($school, $employmentRecordId, $payload, $actor);
    echo 'configured:'.$status->id;
} catch (Throwable $e) {
    echo 'rejected:'.$e::class;
} finally {
    $context->clearAll();
}

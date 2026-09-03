<?php

use App\Domain\Payroll\Statutory\Application\Admin\EmployeeEsiCoverageAdminService;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;

// Standalone bootstrap script for StatutoryEsiCoverageMutationConcurrencyTest
// (Checkpoint 9.6K, completing the concurrency matrix now that
// EmployeeEsiCoverageAdminService::correct() gives ESI coverage a real
// write path, Checkpoint 9.6I): run in a GENUINELY separate OS process
// so real, independent PHP processes race correct() against the SAME
// (employment_record_id, period_start) row over real PostgreSQL.
//
// Usage: php correct-esi-coverage.php <schoolId> <employmentRecordId> <actorUserId> <periodStart> <periodEnd> <entryWage> <isCoveredJson>

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

[, $schoolId, $employmentRecordId, $actorUserId, $periodStart, $periodEnd, $entryWage, $isCoveredJson] = $argv;

$context = $app->make(TenantContext::class);
$school = School::query()->findOrFail($schoolId);
$context->set($school);

try {
    $actor = User::query()->findOrFail($actorUserId);
    $isCovered = json_decode($isCoveredJson, true, flags: JSON_THROW_ON_ERROR);
    $coverage = $app->make(EmployeeEsiCoverageAdminService::class)->correct($school, $employmentRecordId, $periodStart, $periodEnd, $entryWage, $isCovered, $actor);
    echo 'corrected:'.$coverage->id;
} catch (Throwable $e) {
    echo 'rejected:'.$e::class;
} finally {
    $context->clearAll();
}

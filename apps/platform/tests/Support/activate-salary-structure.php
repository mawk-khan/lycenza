<?php

use App\Domain\Payroll\Application\SalaryStructureService;
use App\Domain\Payroll\Infrastructure\SalaryStructure;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Tests\Support\Concurrency\HeldTransaction;

// Standalone bootstrap script for
// SalaryStructureActivationConcurrencyTest: run in a GENUINELY separate
// OS process (via Symfony\Process::start(), non-blocking) so two real,
// independent PHP processes race SalaryStructureService::activate()
// for two DIFFERENT revisions of the SAME (school_id, code) against
// real PostgreSQL -- not a sequential simulation. Mirrors
// activate-academic-year.php's identical pattern.
//
// Usage: php activate-salary-structure.php <schoolId> <structureId> <actorUserId>

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

[, $schoolId, $structureId, $actorUserId] = $argv;

$context = $app->make(TenantContext::class);
$school = School::query()->findOrFail($schoolId);
$context->set($school);

try {
    $structure = SalaryStructure::query()->findOrFail($structureId);
    $actor = User::query()->findOrFail($actorUserId);
    HeldTransaction::run(fn () => $app->make(SalaryStructureService::class)->activate($structure, $actor));
    echo 'activated';
} catch (Throwable $e) {
    echo 'rejected:'.$e::class;
} finally {
    $context->clearAll();
}

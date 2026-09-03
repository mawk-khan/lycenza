<?php

use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\Payroll\Application\CompensationService;
use App\Domain\Payroll\Infrastructure\SalaryStructure;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;

// Standalone bootstrap script for compensation-assignment concurrency
// tests: run in a GENUINELY separate OS process (via
// Symfony\Process::start(), non-blocking) so two real, independent PHP
// processes race CompensationService::assign() against real
// PostgreSQL -- not a sequential simulation. Mirrors
// activate-academic-year.php's identical pattern.
//
// Usage: php assign-compensation.php <schoolId> <employmentRecordId> <structureId> <effectiveFrom> <actorUserId>

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

[, $schoolId, $employmentRecordId, $structureId, $effectiveFrom, $actorUserId] = $argv;

$context = $app->make(TenantContext::class);
$school = School::query()->findOrFail($schoolId);
$context->set($school);

try {
    $employmentRecord = EmploymentRecord::query()->findOrFail($employmentRecordId);
    $structure = SalaryStructure::query()->findOrFail($structureId);
    $actor = User::query()->findOrFail($actorUserId);

    $app->make(CompensationService::class)->assign(
        $school,
        $employmentRecord,
        $structure,
        Carbon::parse($effectiveFrom),
        [],
        $actor,
    );
    echo 'assigned';
} catch (Throwable $e) {
    echo 'rejected:'.$e::class;
} finally {
    $context->clearAll();
}

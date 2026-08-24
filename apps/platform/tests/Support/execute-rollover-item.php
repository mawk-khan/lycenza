<?php

use App\Domain\Students\Application\EnrollmentRolloverItemExecutionService;
use App\Domain\Students\Infrastructure\EnrollmentRolloverItem;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;

// Standalone bootstrap script for EnrollmentRolloverItemExecutionServiceTest's
// real-concurrency proof: run in a GENUINELY separate OS process (via
// Symfony\Process::start(), non-blocking) so two real, independent PHP
// processes race EnrollmentRolloverItemExecutionService::execute() for
// the SAME Item against real PostgreSQL -- not a sequential simulation.
// Mirrors activate-academic-year.php's identical pattern (Phase 0D).
//
// Usage: php execute-rollover-item.php <schoolId> <itemId>

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

[, $schoolId, $itemId] = $argv;

$context = $app->make(TenantContext::class);
$school = School::query()->findOrFail($schoolId);
$context->set($school);

try {
    $item = EnrollmentRolloverItem::query()->findOrFail($itemId);
    $result = $app->make(EnrollmentRolloverItemExecutionService::class)->execute($item);
    echo $result->execution_status.':'.($result->target_enrollment_id ?? 'null');
} catch (Throwable $e) {
    echo 'exception:'.$e::class;
} finally {
    $context->clearAll();
}

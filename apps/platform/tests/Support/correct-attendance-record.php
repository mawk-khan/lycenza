<?php

use App\Domain\Attendance\Application\AttendanceCorrectionService;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;

// Standalone bootstrap script for AttendanceCorrectionConcurrencyTest:
// one expected-status compare-and-swap correction in a genuinely
// separate OS process, so two real processes race the SAME record with
// the SAME expected_status against real PostgreSQL.
//
// Usage: php correct-attendance-record.php <schoolId> <recordId> <expectedStatus> <newStatus> <actorId>

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

[, $schoolId, $recordId, $expectedStatus, $newStatus, $actorId] = $argv;

$context = $app->make(TenantContext::class);
$school = School::query()->findOrFail($schoolId);
$context->set($school);

try {
    $actor = User::query()->findOrFail($actorId);
    $record = $app->make(AttendanceCorrectionService::class)
        ->correct($school, $recordId, $expectedStatus, $newStatus, $actor);

    echo 'corrected:'.$record->status;
} catch (Throwable $e) {
    echo 'rejected:'.$e::class;
} finally {
    $context->clearAll();
}

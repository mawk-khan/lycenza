<?php

use App\Domain\HR\Application\Retention\EmployeeRecordRetentionService;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Concurrency\HeldTransaction;

// Standalone bootstrap script for EmployeeRetentionConcurrencyTest (E21.2E):
// one operation in a GENUINELY separate OS process.
//
// Usage:
//   php employee-retention-op.php rehire          <schoolId> <employeeId>
//   php employee-retention-op.php evidence-prune  <schoolId> <cutoffDate>
//   php employee-retention-op.php ancillary-prune <schoolId> <cutoffDate>

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$args = array_slice($argv, 1);
$operation = array_shift($args);
$context = $app->make(TenantContext::class);

try {
    echo HeldTransaction::run(function () use ($app, $operation, $args, $context): string {
        $school = School::query()->findOrFail($args[0]);
        $records = $app->make(EmployeeRecordRetentionService::class);

        return match ($operation) {
            'rehire' => $context->withSchool($school, function () use ($school, $args): string {
                DB::table('employment_records')->insert([
                    'id' => (string) Str::uuid7(), 'school_id' => $school->id, 'employee_id' => $args[1], 'employment_type' => 'permanent',
                    'starts_on' => '2033-01-01', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
                ]);

                return 'rehired';
            }),
            'evidence-prune' => 'deleted:'.$records->pruneEvidence($school, $args[1], 100, false)['deleted'],
            'ancillary-prune' => 'deleted:'.$records->pruneAncillary($school, $args[1], 100, false)['deleted'],
            default => throw new InvalidArgumentException("unknown operation {$operation}"),
        };
    });
} catch (Throwable $e) {
    echo 'rejected:'.class_basename($e);
}

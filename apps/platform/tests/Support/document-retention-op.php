<?php

use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Concurrency\HeldTransaction;

// Standalone bootstrap script for DocumentsRetentionConcurrencyTest (E21.2C):
// commits a documents row for an existing object, held open, in a
// GENUINELY separate OS process (an upload's late metadata commit).
//
// Usage:
//   php document-retention-op.php insert-document <schoolId> <employeeId> <disk> <path>

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$operation, $schoolId, $employeeId, $disk, $path] = array_slice($argv, 1);

try {
    echo HeldTransaction::run(function () use ($app, $schoolId, $employeeId, $disk, $path): string {
        $school = School::query()->findOrFail($schoolId);

        return $app->make(TenantContext::class)->withSchool($school, function () use ($school, $employeeId, $disk, $path): string {
            DB::table('documents')->insert([
                'id' => (string) Str::uuid7(), 'school_id' => $school->id, 'employee_id' => $employeeId, 'classification_tier' => 'internal',
                'storage_disk' => $disk, 'storage_path' => $path, 'original_filename' => 'late.pdf', 'mime_type' => 'application/pdf',
                'size_bytes' => 5, 'uploaded_at' => now(), 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
            ]);

            return 'inserted';
        });
    });
} catch (Throwable $e) {
    echo 'rejected:'.class_basename($e);
}

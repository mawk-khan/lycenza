<?php

use App\Models\School;
use App\Support\Retention\LmsResourceRetention;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Concurrency\HeldTransaction;

// Standalone bootstrap script for AcademicRetentionConcurrencyTest (E21.3D):
// one operation in a GENUINELY separate OS process.
//
// Usage:
//   php academic-retention-op.php attach-document <schoolId> <learningContentId>
//   php academic-retention-op.php lms-prune <schoolId> <cutoffDate>

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$args = array_slice($argv, 1);
$operation = array_shift($args);
$context = $app->make(TenantContext::class);

try {
    echo HeldTransaction::run(function () use ($app, $operation, $args, $context): string {
        $school = School::query()->findOrFail($args[0]);

        return match ($operation) {
            'attach-document' => $context->withSchool($school, function () use ($school, $args): string {
                DB::table('documents')->insert([
                    'id' => (string) Str::uuid7(), 'school_id' => $school->id, 'learning_content_id' => $args[1], 'classification_tier' => 'internal',
                    'storage_disk' => 'local', 'storage_path' => "schools/{$school->id}/documents/learning_content/{$args[1]}/race.pdf",
                    'original_filename' => 'race.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 5, 'uploaded_at' => now(), 'status' => 'active',
                    'created_at' => now(), 'updated_at' => now(),
                ]);

                return 'attached';
            }),
            'lms-prune' => 'deleted:'.$app->make(LmsResourceRetention::class)->prune('learning_content', $school, $args[1], 100, false, false)['deleted'],
            default => throw new InvalidArgumentException("unknown operation {$operation}"),
        };
    });
} catch (Throwable $e) {
    echo 'rejected:'.class_basename($e);
}

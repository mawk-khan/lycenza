<?php

use App\Models\School;
use App\Support\Retention\LmsResourceRetention;
use App\Support\Retention\RetentionExpiry;
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
//   php academic-retention-op.php lms-prune <schoolId> <cutoffDate>  (E21-RH.5: as the retention identity)

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$args = array_slice($argv, 1);
$operation = array_shift($args);
$context = $app->make(TenantContext::class);

try {
    // Read as the runtime role, before any connection switch (the retention identity cannot read schools).
    $school = School::query()->findOrFail($args[0]);
    $run = fn () => HeldTransaction::run(function () use ($app, $operation, $args, $context, $school): string {
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
            'lms-prune' => (function () use ($app, $school, $args): string {
                $r = $app->make(LmsResourceRetention::class)->prune('learning_content', $school, $args[1], 100, false, false);

                return "deleted:{$r['deleted']} errors:{$r['errors']}";
            })(),
            default => throw new InvalidArgumentException("unknown operation {$operation}"),
        };
    });
    // E21-RH.5: the LMS purge runs as the retention identity, held on that connection; a Document attach is a runtime write.
    echo $operation === 'lms-prune' ? DB::usingConnection(RetentionExpiry::PRIVILEGED_CONNECTION, $run) : $run();
} catch (Throwable $e) {
    echo 'rejected:'.class_basename($e);
}

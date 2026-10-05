<?php

use App\Domain\Communications\Application\Retention\CommunicationRetentionService;
use App\Models\School;
use App\Support\Retention\RetentionExpiry;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Concurrency\HeldTransaction;

// Standalone bootstrap script for CommunicationsRetentionConcurrencyTest
// (E21.2C): one operation in a GENUINELY separate OS process.
//
// Usage:
//   php communication-retention-op.php post-message  <schoolId> <threadId> <userId>
//   php communication-retention-op.php purge-content <schoolId> <cutoffDate>

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$args = array_slice($argv, 1);
$operation = array_shift($args);
$context = $app->make(TenantContext::class);

try {
    $run = fn () => HeldTransaction::run(function () use ($app, $operation, $args, $context): string {
        $school = School::query()->findOrFail($args[0]);

        return match ($operation) {
            'post-message' => $context->withSchool($school, function () use ($school, $args): string {
                DB::table('communication_messages')->insert([
                    'id' => (string) Str::uuid7(), 'school_id' => $school->id, 'thread_id' => $args[1], 'sender_user_id' => $args[2],
                    'message_type' => 'text', 'body' => 'late reply', 'priority' => 'normal', 'status' => 'sent',
                    'created_at' => now(), 'updated_at' => now(),
                ]);

                return 'posted';
            }),
            'purge-content' => 'deleted:'.$app->make(CommunicationRetentionService::class)->pruneContent($school, $args[1], 100, false)['deleted'],
            default => throw new InvalidArgumentException("unknown operation {$operation}"),
        };
    });
    // E21-RH.6: the retention operations run as the retention identity, their held transaction on that
    // connection (the unit transactions nest in it); the racing writes stay ordinary runtime writes.
    echo in_array($operation, ['purge-content'], true) ? DB::usingConnection(RetentionExpiry::PRIVILEGED_CONNECTION, $run) : $run();
} catch (Throwable $e) {
    echo 'rejected:'.class_basename($e);
}

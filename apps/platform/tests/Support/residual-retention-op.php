<?php

use App\Domain\Communications\Application\Retention\CommunicationResidualRetentionService;
use App\Domain\Visitor\Application\Retention\VisitorRetentionService;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Concurrency\HeldTransaction;

// Standalone bootstrap script for ResidualRetentionConcurrencyTest (E21.3E):
// one operation in a GENUINELY separate OS process.
//
// Usage:
//   php residual-retention-op.php message <schoolId> <threadId> <userId>
//   php residual-retention-op.php thread-prune <schoolId> <cutoff>
//   php residual-retention-op.php edit <schoolId> <announcementId>
//   php residual-retention-op.php never-sent-prune <schoolId> <cutoff>
//   php residual-retention-op.php visit <schoolId> <visitorId> <campusId>
//   php residual-retention-op.php visitor-prune <schoolId> <cutoff>

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$args = array_slice($argv, 1);
$operation = array_shift($args);
$context = $app->make(TenantContext::class);

try {
    echo HeldTransaction::run(function () use ($app, $operation, $args, $context): string {
        $school = School::query()->findOrFail($args[0]);
        $residuals = fn () => $app->make(CommunicationResidualRetentionService::class);

        return match ($operation) {
            'message' => $context->withSchool($school, function () use ($school, $args): string {
                DB::table('communication_messages')->insert(['id' => (string) Str::uuid7(), 'school_id' => $school->id, 'thread_id' => $args[1], 'sender_user_id' => $args[2], 'body' => 'Hi', 'created_at' => now(), 'updated_at' => now()]);
                DB::table('communication_threads')->where('id', $args[1])->update(['last_activity_at' => now()]);

                return 'messaged';
            }),
            'thread-prune' => 'deleted:'.$residuals()->pruneEmptyThreads($school, CarbonImmutable::parse($args[1], 'UTC'), 100, false, false)['deleted'],
            // AnnouncementService::updateDraft(): editing a rejected announcement returns it to draft.
            'edit' => $context->withSchool($school, function () use ($args): string {
                DB::table('communication_announcements')->where('id', $args[1])->where('status', 'rejected')->update(['status' => 'draft', 'title' => 'Revised', 'updated_at' => now()]);

                return 'edited';
            }),
            'never-sent-prune' => 'deleted:'.$residuals()->pruneNeverSent($school, CarbonImmutable::parse($args[1], 'UTC'), 100, false, false)['deleted'],
            'visit' => $context->withSchool($school, function () use ($school, $args): string {
                DB::table('visitor_visits')->insert(['id' => (string) Str::uuid7(), 'school_id' => $school->id, 'visitor_id' => $args[1], 'campus_id' => $args[2], 'purpose' => 'Meeting', 'status' => 'checked_in', 'checked_in_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

                return 'visited';
            }),
            'visitor-prune' => 'deleted:'.$app->make(VisitorRetentionService::class)->prune($school, CarbonImmutable::parse($args[1], 'UTC'), 100, false, false)['deleted'],
            default => throw new InvalidArgumentException("unknown operation {$operation}"),
        };
    });
} catch (Throwable $e) {
    echo 'rejected:'.class_basename($e);
}

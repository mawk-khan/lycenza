<?php

use App\Domain\Fees\Application\FeeConcessionService;
use App\Domain\Finance\Application\Retention\FinanceRetentionService;
use App\Models\School;
use App\Models\User;
use App\Support\Retention\RetentionExpiry;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\Support\Concurrency\HeldTransaction;

// E21.3A2 (E21-D8): one Finance retention operation in a GENUINELY separate
// OS process, for FinanceRetentionConcurrencyTest (the parent forces and
// verifies the overlap -- Tests\Concerns\ForcesConcurrentOverlap).
//
// Usage:
//   php finance-retention-op.php prune <schoolId>
//   php finance-retention-op.php void-adjustment <schoolId> <adjustmentId> <userId>

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['retention.finance_enabled' => true, 'retention.finance_years' => 8]);

$args = array_slice($argv, 1);
$operation = array_shift($args);
$context = $app->make(TenantContext::class);
$school = School::query()->findOrFail($args[0]);
$context->set($school);

try {
    $run = fn () => HeldTransaction::run(function () use ($app, $operation, $args, $school): string {
        switch ($operation) {
            case 'prune':
                $r = $app->make(FinanceRetentionService::class)->prune($school, 500, false, 8);

                return "pruned:deleted={$r['deleted']},blocked={$r['dependency_blocked']},errors={$r['errors']},verification_failed={$r['verification_failed']}";
            case 'void-adjustment':
                $app->make(FeeConcessionService::class)->cancelAdjustment($school, $args[1], User::query()->findOrFail($args[2]), 'race');

                return 'voided';
        }

        throw new InvalidArgumentException("Unknown operation {$operation}");
    });
    // E21-RH.6: the retention operations run as the retention identity, their held transaction on that
    // connection (the unit transactions nest in it); the racing writes stay ordinary runtime writes.
    echo in_array($operation, ['prune'], true) ? DB::usingConnection(RetentionExpiry::PRIVILEGED_CONNECTION, $run) : $run();
} catch (Throwable $e) {
    echo 'rejected:'.$e::class.':'.$e->getMessage();
} finally {
    $context->clearAll();
}

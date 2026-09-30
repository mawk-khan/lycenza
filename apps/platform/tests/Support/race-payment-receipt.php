<?php

use App\Domain\Fees\Application\FeeSettingsService;
use App\Domain\Payments\Application\ChargeAllocationInput;
use App\Domain\Payments\Application\ManualPaymentRecordingService;
use App\Domain\Payments\Application\ReceiptBackfillService;
use App\Domain\Payments\Application\RecordManualPaymentData;
use App\Domain\Payments\Domain\ManualPaymentMethod;
use App\Models\School;
use App\Models\User;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Concurrency\HeldTransaction;

// FEE.4 PaymentReceiptConcurrencyTest: one receipt-side operation in a
// GENUINELY separate OS process (the parent forces and verifies overlap --
// Tests\Concerns\ForcesConcurrentOverlap).
//
// Usage:
//   php race-payment-receipt.php record-rollback <schoolId> <userId> <accountId> <chargeId> <amount>
//     records a manual payment (receipt included), holds the transaction
//     until released, then ROLLS IT BACK -- a failed issuer.
//   php race-payment-receipt.php backfill <schoolId>
//   php race-payment-receipt.php set-numbering <schoolId> <userId> <prefix> <startMonth>
//     changes the School's receipt numbering (FeeSettingsService).
//   php race-payment-receipt.php record-on <schoolId> <userId> <accountId> <chargeId> <amount> <YYYY-MM-DD>
//     records a manual payment received on the given (past) date.

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$args = array_slice($argv, 1);
$operation = array_shift($args);
$context = $app->make(TenantContext::class);
$school = School::query()->findOrFail($args[0]);
$context->set($school);

try {
    if ($operation === 'record-rollback') {
        [, $userId, $accountId, $chargeId, $amount] = $args;
        $holdDir = (string) getenv('CONCURRENCY_HOLD_DIR');

        DB::beginTransaction();
        $result = $app->make(ManualPaymentRecordingService::class)->record($school, new RecordManualPaymentData(
            method: ManualPaymentMethod::Cash,
            amount: Money::of($amount, 'INR'),
            occurredOn: Carbon::now($school->timezone)->toDateString(),
            reference: null,
            settlementLedgerAccountId: $accountId,
            allocations: [new ChargeAllocationInput($chargeId, Money::of($amount, 'INR'))],
            idempotencyKey: (string) Str::uuid(),
        ), User::query()->findOrFail($userId));

        touch($holdDir.'/acted');
        $deadline = microtime(true) + 120;
        while (! file_exists($holdDir.'/release') && microtime(true) < $deadline) {
            usleep(1_000);
        }
        DB::rollBack();

        echo 'rolled_back:'.$result->paymentId;
    } elseif ($operation === 'backfill') {
        echo HeldTransaction::run(function () use ($app, $school): string {
            $result = $app->make(ReceiptBackfillService::class)->backfill($school);

            return "issued:{$result['issued']}/skipped:{$result['skipped']}";
        });
    } elseif ($operation === 'set-numbering') {
        [, $userId, $prefix, $month] = $args;
        echo HeldTransaction::run(function () use ($app, $school, $userId, $prefix, $month): string {
            $numbering = $app->make(FeeSettingsService::class)->setReceiptNumbering($school, $prefix, (int) $month, User::query()->findOrFail($userId));

            return "numbering:{$numbering->prefix}:{$numbering->financialYearStartMonth}";
        });
    } elseif ($operation === 'record-on') {
        [, $userId, $accountId, $chargeId, $amount, $on] = $args;
        echo HeldTransaction::run(function () use ($app, $school, $userId, $accountId, $chargeId, $amount, $on): string {
            $result = $app->make(ManualPaymentRecordingService::class)->record($school, new RecordManualPaymentData(
                method: ManualPaymentMethod::Cash,
                amount: Money::of($amount, 'INR'),
                occurredOn: $on,
                reference: null,
                settlementLedgerAccountId: $accountId,
                allocations: [new ChargeAllocationInput($chargeId, Money::of($amount, 'INR'))],
                idempotencyKey: (string) Str::uuid(),
            ), User::query()->findOrFail($userId));

            return 'recorded:'.$result->paymentId;
        });
    } else {
        throw new InvalidArgumentException("Unknown operation {$operation}");
    }
} catch (Throwable $e) {
    echo 'rejected:'.$e::class.':'.$e->getMessage();
} finally {
    $context->clearAll();
}

<?php

use App\Domain\Payments\Application\PaymentCorrectionData;
use App\Domain\Payments\Application\PaymentCorrectionService;
use App\Models\School;
use App\Models\User;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;

// Standalone bootstrap script for UAT-C3 real-process concurrency tests: two
// genuinely separate OS processes submit payment corrections at the same
// time. Mirrors allocate-payment.php.
//
// Usage: php correct-payment.php <schoolId> <userId> <paymentId> <kind> <key> [<chargeId> <toChargeId> <amount>]

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

[, $schoolId, $userId, $paymentId, $kind, $key] = $argv;
$chargeId = $argv[6] ?? null;
$toChargeId = $argv[7] ?? null;
$amount = $argv[8] ?? null;

$context = $app->make(TenantContext::class);
$school = School::query()->findOrFail($schoolId);

try {
    $result = $app->make(PaymentCorrectionService::class)->correct($school, new PaymentCorrectionData(
        paymentId: $paymentId,
        kind: $kind,
        reason: 'Concurrent correction attempt',
        idempotencyKey: $key,
        chargeId: $chargeId,
        toChargeId: $toChargeId,
        amount: $amount === null ? null : Money::of($amount, 'INR'),
    ), User::query()->findOrFail($userId));
    echo ($result['replayed'] ? 'replayed:' : 'corrected:').$result['correctionId'];
} catch (Throwable $e) {
    echo 'rejected:'.$e::class;
} finally {
    $context->clearAll();
}

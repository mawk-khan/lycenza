<?php

use App\Domain\Payments\Infrastructure\Payment;
use App\Domain\Payments\Infrastructure\PaymentAllocation;
use App\Domain\Payments\Infrastructure\PaymentProviderEvent;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Standalone bootstrap script for the RAW concurrent Charge
// over-allocation proof (0G.5 correction, section 15): deliberately
// bypasses App\Domain\Payments\Application\PaymentProviderEventService
// entirely -- creates the PaymentProviderEvent/Payment/PaymentAllocation
// rows directly via Eloquent (still real INSERTs subject to every real
// database trigger, just never through the Application-layer service's
// own business validation) so this test proves the DATABASE trigger
// (payments_lock_and_validate_charge_allocation) alone -- not
// Application pre-validation -- is what prevents two genuinely
// concurrent processes from over-allocating the same Charge.
//
// Usage: php raw-allocate-payment.php <schoolId> <chargeId> <settlementAccountId> <journalEntryId> <amount>

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

[, $schoolId, $chargeId, $settlementAccountId, $journalEntryId, $amount] = $argv;

$context = $app->make(TenantContext::class);
$school = School::query()->findOrFail($schoolId);
$context->set($school);

try {
    DB::connection('pgsql')->transaction(function () use ($school, $chargeId, $settlementAccountId, $journalEntryId, $amount) {
        $event = PaymentProviderEvent::query()->create([
            'school_id' => $school->id,
            'provider' => 'test-provider',
            'provider_event_id' => (string) Str::uuid(),
            'event_type' => 'payment.settled',
            'provider_payment_reference' => (string) Str::uuid(),
            'amount' => $amount,
            'currency' => 'INR',
            'occurred_at' => now(),
            'received_at' => now(),
        ]);

        $payment = Payment::query()->create([
            'school_id' => $school->id,
            'provider' => 'test-provider',
            'provider_payment_reference' => $event->provider_payment_reference,
            'amount' => $amount,
            'currency' => 'INR',
            'settlement_ledger_account_id' => $settlementAccountId,
            'journal_entry_id' => $journalEntryId,
            'provider_event_id' => $event->id,
            'settled_at' => now(),
        ]);

        PaymentAllocation::query()->create([
            'school_id' => $school->id,
            'payment_id' => $payment->id,
            'charge_id' => $chargeId,
            'amount' => $amount,
            'currency' => 'INR',
        ]);
    });
    echo 'settled';
} catch (Throwable $e) {
    echo 'rejected:'.$e::class;
} finally {
    $context->clearAll();
}

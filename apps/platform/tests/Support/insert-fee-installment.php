<?php

use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Uid\UuidV7;
use Tests\Support\Concurrency\HeldTransaction;

// FEE.1 FeeStructureConcurrencyTest: a RAW instalment insert (no service,
// no application lock) racing a held activation, proving the database
// trigger's FOR SHARE parent lock on its own.
//
// Usage: php insert-fee-installment.php <schoolId> <lineId>

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

[, $schoolId, $lineId] = $argv;

$context = $app->make(TenantContext::class);
$school = School::query()->findOrFail($schoolId);
$context->set($school);

try {
    HeldTransaction::run(fn () => DB::table('fee_structure_installments')->insert([
        'id' => (string) new UuidV7, 'school_id' => $schoolId, 'fee_structure_line_id' => $lineId, 'sequence' => 99,
        'label' => 'Late', 'billing_period_key' => 'LATE', 'period_starts_on' => '2026-06-01', 'period_ends_on' => '2027-05-31',
        'due_date' => '2026-06-01', 'amount' => '1.00', 'currency' => 'INR', 'created_at' => now(), 'updated_at' => now(),
    ]));
    echo 'inserted';
} catch (Throwable $e) {
    echo 'rejected:'.(str_contains($e->getMessage(), 'immutable') ? 'immutable' : $e::class.' '.$e->getMessage());
} finally {
    $context->clearAll();
}

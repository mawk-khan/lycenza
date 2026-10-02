<?php

use App\Domain\Fees\Application\FeeSettingsService;
use App\Domain\Finance\Application\JournalLineData;
use App\Domain\Finance\Application\LedgerService;
use App\Domain\Finance\Application\Periods\FinancialPeriodCloseService;
use App\Domain\Finance\Application\PostJournalEntryData;
use App\Domain\Finance\Domain\JournalSide;
use App\Domain\Payroll\Application\PayrollPostingService;
use App\Domain\Payroll\Infrastructure\PayrollRun;
use App\Models\School;
use App\Models\User;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Tests\Support\Concurrency\HeldTransaction;

// E21.3A (ADR 0064 §4): one financial-period operation in a GENUINELY
// separate OS process, for FinancialPeriodConcurrencyTest (the parent forces
// and verifies the overlap -- Tests\Concerns\ForcesConcurrentOverlap).
//
// Usage:
//   php finance-period-op.php close <schoolId> <userId> <periodId> <periodKey>
//   php finance-period-op.php post <schoolId> <debitAccountId> <creditAccountId> <amount>
//   php finance-period-op.php reverse <schoolId> <journalEntryId>
//   php finance-period-op.php payroll-post <schoolId> <runId> <userId>
//   php finance-period-op.php set-month <schoolId> <userId> <month>

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$args = array_slice($argv, 1);
$operation = array_shift($args);
$context = $app->make(TenantContext::class);
// The School context must outlive the held outer transaction: the deferred
// journal balance trigger runs at the real COMMIT under RLS.
$school = School::query()->findOrFail($args[0]);
$context->set($school);

try {
    echo HeldTransaction::run(function () use ($app, $operation, $args, $school): string {
        switch ($operation) {
            case 'close':
                $period = $app->make(FinancialPeriodCloseService::class)->close($school, $args[2], $args[3], User::query()->findOrFail($args[1]));

                return 'closed:'.$period->key;
            case 'post':
                $result = $app->make(LedgerService::class)->post($school, new PostJournalEntryData('INR', 'Race posting', [
                    new JournalLineData($args[1], JournalSide::Debit, Money::of($args[3], 'INR')),
                    new JournalLineData($args[2], JournalSide::Credit, Money::of($args[3], 'INR')),
                ]));

                return 'posted:'.$result->journalEntryId;
            case 'reverse':
                $result = $app->make(LedgerService::class)->reverseById($school, $args[1]);

                return 'reversed:'.$result->journalEntryId;
            case 'payroll-post':
                $posting = $app->make(PayrollPostingService::class)->post(PayrollRun::query()->findOrFail($args[1]), User::query()->findOrFail($args[2]));

                return 'posted:'.$posting->journal_entry_id;
            case 'set-month':
                $app->make(FeeSettingsService::class)->setReceiptNumbering($school, 'RCPT', (int) $args[2], User::query()->findOrFail($args[1]));

                return 'month:'.$args[2];
        }

        throw new InvalidArgumentException("Unknown operation {$operation}");
    });
} catch (Throwable $e) {
    echo 'rejected:'.$e::class.':'.$e->getMessage();
} finally {
    $context->clearAll();
}

<?php

use App\Domain\Fees\Application\ChargeCancellationReason;
use App\Domain\Fees\Application\ChargeService;
use App\Domain\Finance\Infrastructure\FinancialPeriod;
use App\Domain\Payroll\Application\PayrollPostingService;
use App\Domain\Payroll\Infrastructure\PayrollRun;
use App\Models\School;
use App\Models\User;
use App\Support\Auth\Mfa\FreshMfaProof;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Concurrency\HeldTransaction;

// Standalone bootstrap script for SubledgerJournalReversalConcurrencyTest
// (ORG.8-P1, ADR 0076 §57): one reversal of a journal entry, in a GENUINELY
// separate OS process (HeldTransaction holder or contender).
//
// Usage:
//   php subledger-reversal-op.php raw-reverse <schoolId> <journalEntryId>
//   php subledger-reversal-op.php cancel-charge <schoolId> <chargeId> <actorId>
//   php subledger-reversal-op.php reverse-payroll <schoolId> <runId> <actorId>
//
// raw-reverse is the back door the guard closes: a runtime-role INSERT of a
// balanced reversal (entry + mirrored lines) with no owner link. It answers
// ok, or rejected:guard / rejected:already-reversed / rejected:<class>.

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$args = array_slice($argv, 1);
$operation = array_shift($args);
$school = School::query()->findOrFail(array_shift($args));
$context = $app->make(TenantContext::class);
// Session-level for the whole process, so the School context is still set at
// the held transaction's real COMMIT (where the deferred checks run).
$context->set($school);

try {
    echo HeldTransaction::run(function () use ($app, $operation, $args, $school): string {
        switch ($operation) {
            case 'raw-reverse':
                [$entryId] = $args;
                DB::transaction(function () use ($entryId, $school): void {
                    $original = DB::table('journal_entries')->where('id', $entryId)->first();
                    // The model creates the posting's period; this raw write does it explicitly (not the guard path).
                    FinancialPeriod::ensureContaining($school->id, $school->timezone ?: 'UTC', now());
                    $id = (string) Str::uuid7();
                    DB::table('journal_entries')->insert(['id' => $id, 'school_id' => $school->id, 'currency' => $original->currency,
                        'description' => 'raw reversal', 'reversal_of_journal_entry_id' => $entryId, 'posted_at' => now()]);
                    foreach (DB::table('journal_lines')->where('journal_entry_id', $entryId)->get() as $line) {
                        DB::table('journal_lines')->insert(['id' => (string) Str::uuid7(), 'school_id' => $school->id, 'journal_entry_id' => $id,
                            'ledger_account_id' => $line->ledger_account_id, 'currency' => $line->currency,
                            'debit_amount' => $line->credit_amount, 'credit_amount' => $line->debit_amount, 'created_at' => now()]);
                    }
                });

                return 'ok';
            case 'cancel-charge':
                [$chargeId, $actorId] = $args;
                $actor = User::query()->findOrFail($actorId);
                $app->make(ChargeService::class)->cancel($school, $chargeId, $actor, ChargeCancellationReason::CANCEL, 'charged_in_error', null, new FreshMfaProof($actor->id, CarbonImmutable::now()));

                return 'ok';
            case 'reverse-payroll':
                [$runId, $actorId] = $args;
                $app->make(PayrollPostingService::class)->reverse(PayrollRun::query()->findOrFail($runId), User::query()->findOrFail($actorId));

                return 'ok';
            default:
                throw new InvalidArgumentException("Unknown operation {$operation}");
        }
    });
} catch (Throwable $e) {
    $message = $e->getMessage();
    echo 'rejected:'.match (true) {
        str_contains($message, 'journal_entries_subledger_reversal_guard') => 'guard',
        str_contains($message, 'journal_entries_reversal_of_unique') => 'already-reversed',
        default => class_basename($e).' '.mb_substr($message, 0, 300),
    };
} finally {
    $context->clearAll();
}

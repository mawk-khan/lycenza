<?php

use App\Domain\Finance\Infrastructure\JournalEntry;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

// Standalone bootstrap script for JournalReversalConcurrencyTest: run in
// a GENUINELY separate OS process (via Symfony\Process::start(),
// non-blocking) so two real, independent PHP processes race to insert
// a reversing journal_entry for the SAME original entry against real
// PostgreSQL -- not a sequential simulation. Mirrors
// activate-academic-year.php's identical pattern. Each attempt inserts
// a BALANCED two-line reversal (debit/credit swapped from the
// original) so the only thing that can make it fail is the partial
// unique index on reversal_of_journal_entry_id (ADR 0030), not the
// balance trigger.
//
// Usage: php reverse-journal-entry.php <schoolId> <originalEntryId> <debitAccountId> <creditAccountId> <amount>

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

[, $schoolId, $originalEntryId, $debitAccountId, $creditAccountId, $amount] = $argv;

$context = $app->make(TenantContext::class);
$school = School::query()->findOrFail($schoolId);
$context->set($school);

try {
    DB::transaction(function () use ($school, $originalEntryId, $debitAccountId, $creditAccountId, $amount) {
        $reversal = JournalEntry::query()->create([
            'school_id' => $school->id,
            'currency' => 'INR',
            'description' => 'Reversal attempt',
            'reversal_of_journal_entry_id' => $originalEntryId,
        ]);

        // Swapped relative to the original (credit the account that was
        // debited, debit the account that was credited).
        $reversal->lines()->create([
            'school_id' => $school->id,
            'ledger_account_id' => $creditAccountId,
            'currency' => 'INR',
            'debit_amount' => $amount,
        ]);
        $reversal->lines()->create([
            'school_id' => $school->id,
            'ledger_account_id' => $debitAccountId,
            'currency' => 'INR',
            'credit_amount' => $amount,
        ]);
    });
    echo 'reversed';
} catch (Throwable $e) {
    echo 'rejected:'.$e::class;
} finally {
    $context->clearAll();
}

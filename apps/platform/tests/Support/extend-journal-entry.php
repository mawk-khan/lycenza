<?php

use App\Domain\Finance\Infrastructure\JournalEntry;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;

// Standalone bootstrap script for
// JournalEntryLineSetImmutabilityConcurrencyTest: run in a GENUINELY
// separate OS process (via Symfony\Process::start(), non-blocking) so
// two real, independent PHP processes race to INSERT an additional
// journal_lines row against the SAME already-committed journal_entry
// against real PostgreSQL -- not a sequential simulation. Mirrors
// reverse-journal-entry.php's identical pattern. Unlike the reversal
// race, this one is not expected to let either process win -- the
// posting_txid trigger (see the 0G.1 posting-invariants migration)
// deterministically rejects BOTH, because neither process's
// transaction id can ever equal the entry's already-recorded
// posting_txid.
//
// Usage: php extend-journal-entry.php <schoolId> <entryId> <accountId>

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

[, $schoolId, $entryId, $accountId] = $argv;

$context = $app->make(TenantContext::class);
$school = School::query()->findOrFail($schoolId);
$context->set($school);

try {
    $entry = JournalEntry::query()->findOrFail($entryId);
    $entry->lines()->create([
        'school_id' => $school->id,
        'ledger_account_id' => $accountId,
        'currency' => 'INR',
        'debit_amount' => '1.00',
    ]);
    echo 'extended';
} catch (Throwable $e) {
    echo 'rejected:'.$e::class;
} finally {
    $context->clearAll();
}

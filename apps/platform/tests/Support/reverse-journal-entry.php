<?php

use App\Domain\Finance\Application\LedgerService;
use App\Domain\Finance\Infrastructure\JournalEntry;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;

// Standalone bootstrap script for JournalReversalConcurrencyTest: run in
// a GENUINELY separate OS process (via Symfony\Process::start(),
// non-blocking) so two real, independent PHP processes race to call
// App\Domain\Finance\Application\LedgerService::reverse() for the SAME
// original entry against real PostgreSQL -- not a sequential
// simulation. Mirrors activate-academic-year.php's identical pattern.
// 0G.2 (section 51 of its brief): exercises the real production
// posting/reversal service, not a raw-SQL shortcut -- the database's
// partial unique index on reversal_of_journal_entry_id (ADR 0030)
// remains the actual concurrency guarantee either way, but this proves
// it holds through the real code path, not merely through direct SQL.
//
// Usage: php reverse-journal-entry.php <schoolId> <originalEntryId>

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

[, $schoolId, $originalEntryId] = $argv;

$context = $app->make(TenantContext::class);
$school = School::query()->findOrFail($schoolId);
$context->set($school);

try {
    $original = JournalEntry::query()->findOrFail($originalEntryId);
    $app->make(LedgerService::class)->reverse($original);
    echo 'reversed';
} catch (Throwable $e) {
    echo 'rejected:'.$e::class;
} finally {
    $context->clearAll();
}

<?php

use App\Domain\Library\Application\LibraryFineService;
use App\Domain\Library\Application\LibraryLoanService;
use App\Domain\Library\Infrastructure\LibraryLoan;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Tests\Support\Concurrency\HeldTransaction;

// OPF.4 (ADR 0067): a genuinely separate OS process for
// LibraryFineConcurrencyTest. HeldTransaction makes the holder keep its writes
// uncommitted until released, so the contender is observed blocked on them
// (Tests\Concerns\ForcesConcurrentOverlap).
//
// Usage:
//   php library-fine-op.php check-in <schoolId> <loanId>
//   php library-fine-op.php void <schoolId> <fineId>

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$op = $argv[1];
$school = School::query()->findOrFail($argv[2]);
$context = $app->make(TenantContext::class);
$context->set($school);

try {
    echo HeldTransaction::run(function () use ($app, $op, $school, $argv): string {
        if ($op === 'check-in') {
            $loan = $app->make(LibraryLoanService::class)->checkIn(LibraryLoan::query()->findOrFail($argv[3]), null);

            return 'returned:'.$app->make('db')->table('library_fines')->where('library_loan_id', $loan->id)->count();
        }

        $app->make(LibraryFineService::class)->void($school, $argv[3], 'Race', null);

        return 'voided';
    });
} catch (Throwable $e) {
    echo 'error:'.$e::class;
} finally {
    $context->clearAll();
}

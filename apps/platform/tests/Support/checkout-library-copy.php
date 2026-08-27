<?php

use App\Domain\Library\Application\LibraryLoanService;
use App\Domain\Library\Infrastructure\LibraryCopy;
use App\Domain\Students\Infrastructure\Student;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;

// Standalone bootstrap script for LibraryLoanCheckoutConcurrencyTest:
// run in a GENUINELY separate OS process (via Symfony\Process::start(),
// non-blocking) so two real, independent PHP processes race
// LibraryLoanService::checkout() for the SAME Copy against real
// PostgreSQL -- not a sequential simulation. Mirrors
// activate-academic-year.php's identical pattern.
//
// Usage: php checkout-library-copy.php <schoolId> <libraryCopyId> <studentId>

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

[, $schoolId, $libraryCopyId, $studentId] = $argv;

$context = $app->make(TenantContext::class);
$school = School::query()->findOrFail($schoolId);
$context->set($school);

try {
    $copy = LibraryCopy::query()->findOrFail($libraryCopyId);
    $student = Student::query()->findOrFail($studentId);
    $app->make(LibraryLoanService::class)->checkout($copy, $student, Carbon::now()->addDays(14));
    echo 'checked_out';
} catch (Throwable $e) {
    echo 'rejected:'.$e::class;
} finally {
    $context->clearAll();
}

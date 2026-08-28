<?php

use App\Domain\Hostel\Application\HostelResidencyService;
use App\Domain\Hostel\Infrastructure\HostelBed;
use App\Domain\Students\Infrastructure\Student;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;

// Standalone bootstrap script for HostelResidencyConcurrencyTest: run
// in a GENUINELY separate OS process (via Symfony\Process::start(),
// non-blocking) so two real, independent PHP processes race
// HostelResidencyService::assign() against real PostgreSQL -- not a
// sequential simulation. Mirrors check-in-visitor.php's identical
// pattern. Used for BOTH real-concurrency proofs (same Bed / same
// Student) by varying which argument the two invocations share.
//
// Usage: php assign-hostel-residency.php <schoolId> <studentId> <hostelBedId>

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

[, $schoolId, $studentId, $hostelBedId] = $argv;

$context = $app->make(TenantContext::class);
$school = School::query()->findOrFail($schoolId);
$context->set($school);

try {
    $student = Student::query()->findOrFail($studentId);
    $bed = HostelBed::query()->findOrFail($hostelBedId);
    $app->make(HostelResidencyService::class)->assign($student, $bed);
    echo 'assigned';
} catch (Throwable $e) {
    echo 'rejected:'.$e::class;
} finally {
    $context->clearAll();
}

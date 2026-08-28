<?php

use App\Domain\Visitor\Application\VisitorVisitService;
use App\Domain\Visitor\Infrastructure\Visitor;
use App\Models\Campus;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;

// Standalone bootstrap script for VisitorVisitCheckInConcurrencyTest:
// run in a GENUINELY separate OS process (via Symfony\Process::start(),
// non-blocking) so two real, independent PHP processes race
// VisitorVisitService::checkIn() for the SAME Visitor against real
// PostgreSQL -- not a sequential simulation. Mirrors
// assign-transport-student.php's identical pattern.
//
// Usage: php check-in-visitor.php <schoolId> <visitorId> <campusId>

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

[, $schoolId, $visitorId, $campusId] = $argv;

$context = $app->make(TenantContext::class);
$school = School::query()->findOrFail($schoolId);
$context->set($school);

try {
    $visitor = Visitor::query()->findOrFail($visitorId);
    $campus = Campus::query()->findOrFail($campusId);
    $app->make(VisitorVisitService::class)->checkIn($visitor, $campus, null, 'Concurrency test visit', null);
    echo 'checked_in';
} catch (Throwable $e) {
    echo 'rejected:'.$e::class;
} finally {
    $context->clearAll();
}

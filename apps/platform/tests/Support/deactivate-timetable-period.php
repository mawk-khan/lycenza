<?php

use App\Domain\Timetable\Application\TimetablePeriodService;
use App\Domain\Timetable\Infrastructure\TimetablePeriod;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;

// Standalone bootstrap script for
// TimetableEntryVersusPeriodDeactivationConcurrencyTest: run in a
// GENUINELY separate OS process (via Symfony\Process::start(),
// non-blocking) so a real, independent PHP process races
// TimetablePeriodService::deactivate() against ANOTHER real, separate
// process racing TimetableScheduleService::create() (see
// create-timetable-entry.php) for the SAME Period, against real
// PostgreSQL -- not a sequential simulation. Proves the cross-service
// `timetable.periods` lock coordination documented on both
// TimetablePeriodService and TimetableScheduleService actually closes
// the "active TimetableEntry left referencing an inactive Period" race.
//
// Usage: php deactivate-timetable-period.php <schoolId> <periodId> <actorId>

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

[, $schoolId, $periodId, $actorId] = $argv;

$context = $app->make(TenantContext::class);
$school = School::query()->findOrFail($schoolId);
$context->set($school);

try {
    $period = TimetablePeriod::query()->findOrFail($periodId);
    $actor = User::query()->findOrFail($actorId);

    $app->make(TimetablePeriodService::class)->deactivate($period, $actor);
    echo 'deactivated:'.$period->id;
} catch (Throwable $e) {
    echo 'rejected:'.$e::class;
} finally {
    $context->clearAll();
}

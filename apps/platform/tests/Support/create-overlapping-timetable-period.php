<?php

use App\Domain\Timetable\Application\TimetablePeriodService;
use App\Domain\Timetable\Infrastructure\TimetablePeriod;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;

// Standalone bootstrap script for TimetablePeriodConcurrencyTest: run in
// a GENUINELY separate OS process (via Symfony\Process::start(),
// non-blocking) so two real, independent PHP processes race
// TimetablePeriodService::create() (or ::activate(), for the
// reactivation variant) against real PostgreSQL, not a sequential
// simulation. Mirrors activate-academic-year.php's identical pattern.
//
// IMPORTANT: the calling test MUST override CACHE_STORE=database for
// BOTH subprocesses (Symfony Process's $env constructor argument) --
// phpunit.xml's own default (CACHE_STORE=array) is an in-process-only
// store that is NOT shared between two separate OS processes, which
// would silently defeat App\Support\Concurrency\TenantLock's whole
// purpose here (each subprocess would acquire its own independent,
// invisible-to-the-other "lock" and both would proceed). The
// `database` cache store's `cache_locks` table (see the base Laravel
// `0001_01_01_000001_create_cache_table` migration) is real shared
// PostgreSQL state visible to both processes -- see
// TimetablePeriodConcurrencyTest's own docblock for why this
// checkpoint chose that specific store over requiring a live Redis
// connection.
//
// Usage: php create-overlapping-timetable-period.php <schoolId> <code> <name> <startTime> <endTime> [existingPeriodIdToActivate]

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

[, $schoolId, $code, $name, $startTime, $endTime] = $argv;
$existingPeriodId = ($argv[6] ?? '') !== '' ? $argv[6] : null;

$context = $app->make(TenantContext::class);
$school = School::query()->findOrFail($schoolId);
$context->set($school);

try {
    $service = $app->make(TimetablePeriodService::class);
    $actor = User::query()->findOrFail($argv[7]);

    if ($existingPeriodId !== null) {
        $period = TimetablePeriod::query()->findOrFail($existingPeriodId);
        $service->activate($period, $actor);
        echo 'activated:'.$period->id;
    } else {
        $period = $service->create($school, [
            'code' => $code,
            'name' => $name,
            'start_time' => $startTime,
            'end_time' => $endTime,
        ], $actor);
        echo 'created:'.$period->id;
    }
} catch (Throwable $e) {
    echo 'rejected:'.$e::class;
} finally {
    $context->clearAll();
}

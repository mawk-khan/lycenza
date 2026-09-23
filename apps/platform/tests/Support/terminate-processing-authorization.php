<?php

use App\Domain\Students\Application\Exceptions\ProcessingAuthorizationAlreadyTerminatedException;
use App\Domain\Students\Application\StudentProcessingAuthorizationService;
use App\Domain\Students\Domain\ProcessingAuthorizationBasisType;
use App\Domain\Students\Infrastructure\StudentProcessingAuthorization;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

// Standalone bootstrap script for ProcessingAuthorizationConcurrencyTest:
// one terminal action (withdraw, revoke, or supersede) run in a
// genuinely separate OS process, so two real processes can race the
// SAME grant against real PostgreSQL. Mirrors
// tests/Support/activate-mfa-factor.php's structure.
//
// Usage: php terminate-processing-authorization.php <schoolId> <grantId> <actorId> <withdraw|revoke|supersede> [barrierFile]

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

[, $schoolId, $grantId, $actorId, $action] = $argv;
$barrierFile = $argv[5] ?? null;

// Named session so a parent test can positively confirm (via
// pg_stat_activity), rather than infer from elapsed time, that this
// process is genuinely blocked waiting on a lock held elsewhere.
// PostgreSQL's SET does not accept a bind parameter -- $action is one
// of the fixed literal values this script's own usage line documents
// (withdraw|revoke|supersede), never arbitrary external input.
if (! in_array($action, ['withdraw', 'revoke', 'supersede'], true)) {
    throw new InvalidArgumentException('Unknown action: '.$action);
}
DB::connection()->statement('SET application_name TO '.DB::connection()->getPdo()->quote('spa_worker_'.$action));

if ($barrierFile !== null) {
    while (! file_exists($barrierFile)) {
        usleep(200);
    }
}

$school = School::query()->findOrFail($schoolId);
$context = $app->make(TenantContext::class);
// RLS is enforced at the database level regardless of Eloquent scope
// -- this lookup must run with the real Postgres session variable
// set, exactly like every production code path (withoutGlobalScopes()
// alone bypasses only the application-layer SchoolScope).
$grant = $context->withSchool($school, fn () => StudentProcessingAuthorization::query()->where('id', $grantId)->firstOrFail());
$actor = User::query()->findOrFail($actorId);
$service = $app->make(StudentProcessingAuthorizationService::class);

try {
    if ($action === 'supersede') {
        $result = $service->supersede($school, $grant, ProcessingAuthorizationBasisType::AdultStudentConsent, $actor);
        echo 'terminated:'.$result['terminal']->status->value;
    } else {
        $terminal = $action === 'withdraw'
            ? $service->withdraw($school, $grant, $actor)
            : $service->revoke($school, $grant, $actor);

        echo 'terminated:'.$terminal->status->value;
    }
} catch (ProcessingAuthorizationAlreadyTerminatedException) {
    echo 'rejected:already_terminated';
}

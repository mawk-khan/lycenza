<?php

use App\Domain\HR\Application\Separation\SeparationCaseService;
use App\Domain\HR\Application\Separation\SeparationException;
use App\Domain\Identity\Application\SchoolAccessLock;
use App\Domain\Identity\Application\Staff\StaffAccessService;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Tests\Support\Concurrency\HeldTransaction;

// HR.16B (R-HR15-1): one School-access change or one separation completion in
// a GENUINELY separate OS process, so SeparationAccessCertificationTest can
// race real PostgreSQL transactions (HeldTransaction holds the holder's
// change uncommitted until the parent has SEEN the contender blocked).
//
// Usage: php staff-access-certification-op.php <op> <schoolId> <actorId> <arg1> <arg2>
//   grant | revoke   <membershipId> <roleKey>
//   complete         <caseId> <version>
//   hold-lock        (Identity's lock alone, so the School row stays free to probe)
// Prints "ok:<result>", "refused:<code>" or "error:<class>:<message>".

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $op, $schoolId, $actorId, $arg1, $arg2] = array_pad($argv, 6, null);

$context = $app->make(TenantContext::class);
$school = School::query()->findOrFail($schoolId);
$actor = User::query()->findOrFail($actorId);

try {
    echo HeldTransaction::run(fn () => match ($op) {
        'grant' => (function () use ($app, $school, $actor, $arg1, $arg2) {
            $app->make(StaffAccessService::class)->grantRole($school, $actor, (string) $arg1, (string) $arg2);

            return 'ok:granted';
        })(),
        'revoke' => (function () use ($app, $school, $actor, $arg1, $arg2) {
            $app->make(StaffAccessService::class)->revokeRole($school, $actor, (string) $arg1, (string) $arg2);

            return 'ok:revoked';
        })(),
        'hold-lock' => (function () use ($school) {
            SchoolAccessLock::hold($school->id);

            return 'ok:held';
        })(),
        'complete' => 'ok:'.$app->make(SeparationCaseService::class)->complete($school, (string) $arg1, (int) $arg2, $actor)->status,
        default => throw new InvalidArgumentException("unknown op {$op}"),
    });
} catch (SeparationException $e) {
    echo 'refused:'.$e->errorCode();
} catch (Throwable $e) {
    echo 'error:'.$e::class.':'.substr($e->getMessage(), 0, 200);
} finally {
    $context->clearAll();
}

<?php

use App\Domain\Platform\Application\Domains\SchoolDomainCheckService;
use App\Domain\Platform\Application\Domains\SchoolDomainException;
use App\Domain\Platform\Application\Domains\SchoolDomainService;
use App\Models\School;
use App\Models\SchoolDomain;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Tests\Support\Concurrency\HeldTransaction;

// Phase 0O.8A (ADR 0054 sections 3.5-3.6, 4): one custom-domain operation in
// a GENUINELY separate OS process against real PostgreSQL, for
// CustomDomainConcurrencyTest. HeldTransaction makes the holder keep its
// writes uncommitted until the parent has seen the contender blocked.
//
// Usage: php school-domain-op.php <op> <schoolId> <userId> <arg> [<arg2>]
//   claim <hostname> | set-primary <domainId> | revoke <domainId> [replacementId]
//   | regenerate <domainId> | expire <domainId> | check <domainId>
// Prints the resulting outcome: ok:<state> | refused:<code> | error:<class>.

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// The fake DNS answers the parent published live in the REAL Redis store.
config(['cache.default' => 'redis']);

[, $op, $schoolId, $userId, $arg] = $argv;
$arg2 = $argv[5] ?? null;

$school = School::query()->findOrFail($schoolId);
$user = User::query()->find($userId);
$domains = $app->make(SchoolDomainService::class);

try {
    $result = HeldTransaction::run(fn () => match ($op) {
        'claim' => $domains->claim($school, $user, $arg)->state->value,
        'set-primary' => $domains->setPrimary($school, $user, $arg) ?? 'done',
        'revoke' => $domains->revoke($school, $user, $arg, $arg2) ?? 'done',
        'regenerate' => $domains->regenerateChallenge($school, $user, $arg)->state->value,
        'expire' => $domains->expireOne($arg) ? 'expired' : 'unchanged',
        'check' => $app->make(SchoolDomainCheckService::class)->check(SchoolDomain::query()->findOrFail($arg))->value,
    });
    echo 'ok:'.$result;
} catch (SchoolDomainException $e) {
    echo 'refused:'.$e->refusal;
} catch (Throwable $e) {
    echo 'error:'.$e::class.':'.substr($e->getMessage(), 0, 200);
}

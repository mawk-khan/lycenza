<?php

use App\Domain\HR\Application\Exceptions\HrException;
use App\Domain\HR\Application\Separation\SeparationCancelReason;
use App\Domain\HR\Application\Separation\SeparationCaseService;
use App\Domain\HR\Application\Separation\SeparationException;
use App\Models\School;
use App\Models\User;
use App\Support\Auth\Mfa\FreshMfaProof;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;

// HR.12: one separation-case operation in a GENUINELY separate OS process, so
// SeparationConcurrencyTest can race real PostgreSQL transactions.
//
// Usage: php separation-race.php <op> <schoolId> <actorId> <caseId> <version> [itemId]
//   op: approve | finalize | cancel | clear
// Prints "ok:<case status>" on success, "refused:<machine code>" for a domain
// refusal, or "error:<class>" otherwise.

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $op, $schoolId, $actorId, $caseId, $version, $itemId] = array_pad($argv, 7, null);

$context = $app->make(TenantContext::class);
$school = School::query()->findOrFail($schoolId);
$actor = User::query()->findOrFail($actorId);
$cases = $app->make(SeparationCaseService::class);
$proof = new FreshMfaProof($actor->id, CarbonImmutable::now());

try {
    $result = match ($op) {
        'approve' => $cases->approve($school, $caseId, (int) $version, $actor, $proof)->status,
        'finalize' => $cases->finalize($school, $caseId, (int) $version, $actor, $proof)->status,
        'cancel' => $cases->cancel($school, $caseId, (int) $version, SeparationCancelReason::RECORDED_IN_ERROR, $actor, $proof)->status,
        'clear' => $cases->clearItem($school, $caseId, (string) $itemId, $actor)->status,
        default => throw new InvalidArgumentException("unknown op {$op}"),
    };
    echo 'ok:'.$result;
} catch (SeparationException $e) {
    echo 'refused:'.$e->errorCode();
} catch (HrException $e) {
    echo 'refused:'.$e->errorCode();
} catch (Throwable $e) {
    echo 'error:'.$e::class.':'.substr($e->getMessage(), 0, 200);
} finally {
    $context->clearAll();
}

<?php

use App\Domain\HR\Application\Exceptions\HrException;
use App\Domain\HR\Application\Separation\SeparationCancelReason;
use App\Domain\HR\Application\Separation\SeparationCaseService;
use App\Domain\HR\Application\Separation\SeparationException;
use App\Domain\HR\Application\Workforce\EmploymentTermsChangeReason;
use App\Domain\HR\Application\Workforce\EmploymentTermsService;
use App\Domain\HR\Application\Workforce\EmploymentType;
use App\Domain\HR\Application\Workforce\StaffType;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\HR\Infrastructure\EmploymentSeparationCase;
use App\Models\School;
use App\Models\User;
use App\Support\Auth\Mfa\FreshMfaProof;
use App\Support\Tenancy\SchoolTimezone;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Tests\Support\Concurrency\HeldTransaction;

// HR.12: one separation-case operation in a GENUINELY separate OS process, so
// SeparationConcurrencyTest can race real PostgreSQL transactions.
//
// Usage: php separation-race.php <op> <schoolId> <actorId> <caseId> <version> [itemId]
//   op: approve | finalize | cancel | clear | retain | complete | reclassify
//   (reclassify: the case's employment gets staff type [itemId] from the School's today, through EmploymentTermsService; HR.R1-C35 WO-21)
//   (retain prints the bound grant ids after the status: "ok:finalized:<id,id>"; HR.R1-C35 WO-20)
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
    // HR.R1-C35 WO-18: held open when the parent asks (CONCURRENCY_HOLD_DIR); otherwise runs as before.
    $result = HeldTransaction::run(fn () => match ($op) {
        'approve' => $cases->approve($school, $caseId, (int) $version, $actor, $proof)->status,
        'finalize' => $cases->finalize($school, $caseId, (int) $version, $actor, $proof)->status,
        'cancel' => $cases->cancel($school, $caseId, (int) $version, SeparationCancelReason::RECORDED_IN_ERROR, $actor, $proof)->status,
        'clear' => $cases->clearItem($school, $caseId, (string) $itemId, $actor)->status,
        'retain' => (fn ($case) => $case->status.':'.implode(',', $case->retainedGrantIds() ?? []))($cases->recordAccessRetained($school, $caseId, (int) $version, $actor)),
        'complete' => $cases->complete($school, $caseId, (int) $version, $actor)->status,
        'reclassify' => (function () use ($app, $context, $school, $caseId, $actor, $itemId): string {
            $employment = $context->withSchool($school, fn () => EmploymentRecord::query()->findOrFail(
                EmploymentSeparationCase::query()->findOrFail($caseId)->employment_record_id));

            return 'reclassified:'.$app->make(EmploymentTermsService::class)->change(
                $employment, SchoolTimezone::today($school), EmploymentType::PERMANENT,
                StaffType::from((string) $itemId), null,
                EmploymentTermsChangeReason::RECLASSIFICATION, $actor)->staff_type;
        })(),
        default => throw new InvalidArgumentException("unknown op {$op}"),
    });
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

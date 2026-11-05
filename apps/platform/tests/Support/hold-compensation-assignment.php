<?php

use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\Payroll\Application\CompensationService;
use App\Domain\Payroll\Application\FixedComponentValueInput;
use App\Domain\Payroll\Infrastructure\SalaryStructure;
use App\Models\School;
use App\Models\User;
use App\Support\Auth\Mfa\FreshMfaProof;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;
use Tests\Support\Concurrency\HeldTransaction;

// HR.R1-C1 (PAY-2): CompensationService::assign() in a GENUINELY separate OS
// process, held open inside HeldTransaction when it is the race holder, so a
// payroll calculation or approval can be observed racing a compensation write.
//
// Usage: php hold-compensation-assignment.php <schoolId> <employmentRecordId> <structureId> <structureComponentId> <amount> <effectiveFrom> <actorUserId>

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

[, $schoolId, $recordId, $structureId, $lineId, $amount, $effectiveFrom, $actorId] = $argv;

$context = $app->make(TenantContext::class);
$school = School::query()->findOrFail($schoolId);
$context->set($school);

try {
    $actor = User::query()->findOrFail($actorId);
    HeldTransaction::run(fn () => $app->make(CompensationService::class)->assign(
        $school, EmploymentRecord::query()->findOrFail($recordId), SalaryStructure::query()->findOrFail($structureId),
        Carbon::parse($effectiveFrom), [new FixedComponentValueInput($lineId, $amount)], $actor,
        new FreshMfaProof($actor->id, CarbonImmutable::now()),
    ));
    echo 'assigned';
} catch (Throwable $e) {
    echo 'rejected:'.$e::class;
} finally {
    $context->clearAll();
}

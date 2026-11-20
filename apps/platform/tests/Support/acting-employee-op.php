<?php

use App\Domain\HR\Application\ActingEmployeeResolver;
use App\Domain\HR\Application\EmployeeLifecycleService;
use App\Domain\HR\Application\EmployeeService;
use App\Domain\HR\Application\EmploymentService;
use App\Domain\HR\Application\Exceptions\ActingEmployeeUnavailableException;
use App\Domain\HR\Application\Exceptions\HrException;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Models\School;
use App\Models\User;
use App\Support\Auth\Mfa\FreshMfaProof;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\Support\Concurrency\HeldTransaction;

// Standalone bootstrap script for ActingEmployeeConcurrencyTest (TCH.1,
// ADR 0063 section 20): one identity operation in a GENUINELY separate OS
// process, so two real PHP processes race against real PostgreSQL.
// Mirrors staff-account-op.php.
//
// `hold` stands in for a future state-changing consumer: it verifies the
// ActingEmployee inside its own transaction (ActingEmployeeResolver::hold())
// -- the decision a protected write would be taken on.
//
// Usage:
//   php acting-employee-op.php hold    <schoolId> <userId> <asOf>
//   php acting-employee-op.php link    <schoolId> <actorId> <employeeId> <userId>
//   php acting-employee-op.php unlink  <schoolId> <actorId> <employeeId>
//   php acting-employee-op.php archive <schoolId> <actorId> <employeeId>
//   php acting-employee-op.php end-employment <schoolId> <actorId> <employmentId> <endsOn>
//   php acting-employee-op.php deceased <schoolId> <actorId> <employmentId> <endsOn>   (HR.R1-C35 WO-24: the direct record of a death)

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$args = array_slice($argv, 1);
$operation = array_shift($args);
$context = $app->make(TenantContext::class);

$school = fn (string $id): School => School::query()->findOrFail($id);
$user = fn (string $id): User => User::query()->findOrFail($id);
$employee = fn (School $s, string $id): Employee => $context->withSchool($s, fn () => Employee::query()->findOrFail($id));

try {
    echo HeldTransaction::run(function () use ($app, $operation, $args, $school, $user, $employee, $context): string {
        $hr = $app->make(EmployeeService::class);

        switch ($operation) {
            case 'hold':
                [$schoolId, $userId, $asOf] = $args;
                $acting = DB::transaction(fn () => $app->make(ActingEmployeeResolver::class)->hold($user($userId), $school($schoolId), $asOf));

                return 'acting:'.$acting->employeeId;
            case 'link':
                [$schoolId, $actorId, $employeeId, $userId] = $args;
                $s = $school($schoolId);
                $hr->linkUser($employee($s, $employeeId), $userId, $user($actorId));

                return 'linked';
            case 'unlink':
                [$schoolId, $actorId, $employeeId] = $args;
                $s = $school($schoolId);
                $hr->unlinkUser($employee($s, $employeeId), $user($actorId));

                return 'unlinked';
            case 'archive':
                [$schoolId, $actorId, $employeeId] = $args;
                $s = $school($schoolId);
                $hr->archive($employee($s, $employeeId), $user($actorId));

                return 'archived';
            case 'end-employment':
                [$schoolId, $actorId, $employmentId, $endsOn] = $args;
                $s = $school($schoolId);
                $record = $context->withSchool($s, fn () => EmploymentRecord::query()->findOrFail($employmentId));
                $app->make(EmploymentService::class)->end($record, $endsOn, $user($actorId));

                return 'ended';
            case 'deceased':
                [$schoolId, $actorId, $employmentId, $endsOn] = $args;
                $s = $school($schoolId);
                $record = $context->withSchool($s, fn () => EmploymentRecord::query()->findOrFail($employmentId));
                $actor = $user($actorId);
                $app->make(EmployeeLifecycleService::class)->separate($record, $endsOn, $actor, new FreshMfaProof($actor->id, CarbonImmutable::now()));

                return 'deceased';
        }

        return 'unknown';
    });
} catch (ActingEmployeeUnavailableException $e) {
    echo 'denied:'.$e->reason;
} catch (HrException $e) {
    echo 'rejected:'.$e->errorCode();
} catch (Throwable $e) {
    echo 'error:'.$e::class.':'.$e->getMessage();
} finally {
    $context->clearAll();
}

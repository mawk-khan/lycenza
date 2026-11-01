<?php

use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\Students\Application\Bulk\BulkPlacementOperation;
use App\Domain\Students\Application\Bulk\BulkPlacementRequest;
use App\Domain\Students\Application\Bulk\BulkStudentPlacementService;
use App\Domain\Students\Application\StudentEnrollmentService;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Models\School;
use App\Models\User;
use App\Support\Auth\Mfa\FreshMfaProof;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Tests\Support\Concurrency\HeldTransaction;

// Standalone bootstrap script for BulkStudentPlacementConcurrencyTest
// (SIS 11.13): one bulk execution or one single-Student transfer in a
// GENUINELY separate OS process (HeldTransaction holder or contender).
//
// Usage:
//   php bulk-placement-op.php bulk <schoolId> <userId> <operation> <sourceSectionId> <targetSectionId> <date> <issuedAt> <signature> <rowsJson>
//   php bulk-placement-op.php single <schoolId> <userId> <enrollmentId> <targetSectionId> <roll> <date>
// Prints `ok` or `refused:<ExceptionClass>:<message>`.

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $operation, $schoolId, $userId] = $argv;
$args = array_slice($argv, 4);

$context = $app->make(TenantContext::class);
$school = School::query()->findOrFail($schoolId);
$context->set($school);
$actor = User::query()->findOrFail($userId);

try {
    echo HeldTransaction::run(function () use ($app, $operation, $args, $school, $actor): string {
        switch ($operation) {
            case 'bulk':
                [$op, $source, $target, $date, $issuedAt, $signature, $rows] = $args;
                $request = new BulkPlacementRequest(BulkPlacementOperation::from($op), $source, $target, $date, json_decode($rows, true, flags: JSON_THROW_ON_ERROR));
                $app->make(BulkStudentPlacementService::class)->execute($school, $actor, $request, $issuedAt, $signature, new FreshMfaProof($actor->id, CarbonImmutable::now()));

                return 'ok';
            case 'single':
                [$enrollmentId, $target, $roll, $date] = $args;
                $app->make(StudentEnrollmentService::class)->transferPlacement(StudentEnrollment::query()->findOrFail($enrollmentId), Section::query()->findOrFail($target), $roll, $date, $actor);

                return 'ok';
            default:
                throw new InvalidArgumentException("Unknown operation {$operation}");
        }
    });
} catch (Throwable $e) {
    echo 'refused:'.class_basename($e).':'.$e->getMessage();
} finally {
    $context->clearAll();
}

<?php

use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\Examinations\Application\Exceptions\ExaminationException;
use App\Domain\Examinations\Application\Marks\StudentMarkEntry;
use App\Domain\Examinations\Application\Marks\StudentMarkService;
use App\Domain\Students\Application\StudentEnrollmentService;
use App\Domain\Students\Application\StudentProcessingAuthorizationService;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Domain\Students\Infrastructure\StudentProcessingAuthorization;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Tests\Support\Concurrency\HeldTransaction;

// RES.2 (ADR 0068 §6.2): a genuinely separate OS process for
// StudentMarkConcurrencyTest. HeldTransaction keeps the HOLDER's work
// uncommitted until released, so the CONTENDER is observed blocked on it
// (Tests\Concerns\ForcesConcurrentOverlap). Synthetic fixtures only.
//
// Usage:
//   php student-mark-op.php record <schoolId> <paperId> <actorId> <studentId> <status> <value|-> <expectedVersion|->
//   php student-mark-op.php withdraw-authorization <schoolId> <grantId> <actorId>
//   php student-mark-op.php transfer-placement <schoolId> <enrollmentId> <targetSectionId> <rollNumber> <effectiveDate>

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$op = $argv[1];
$school = School::query()->findOrFail($argv[2]);
$context = $app->make(TenantContext::class);
$context->set($school);

try {
    echo HeldTransaction::run(function () use ($app, $op, $school, $argv): string {
        if ($op === 'record') {
            $written = $app->make(StudentMarkService::class)->record($school, $argv[3], [new StudentMarkEntry(
                $argv[5], $argv[6], $argv[7] === '-' ? null : $argv[7], $argv[8] === '-' ? null : (int) $argv[8],
            )], User::query()->findOrFail($argv[4]));

            return 'recorded:v'.$written[0]['version'];
        }
        if ($op === 'withdraw-authorization') {
            $app->make(StudentProcessingAuthorizationService::class)->withdraw(
                $school, StudentProcessingAuthorization::query()->findOrFail($argv[3]), User::query()->findOrFail($argv[4]),
            );

            return 'withdrawn';
        }

        $app->make(StudentEnrollmentService::class)->transferPlacement(
            StudentEnrollment::query()->findOrFail($argv[3]), Section::query()->findOrFail($argv[4]), $argv[5], $argv[6],
        );

        return 'transferred';
    });
} catch (ExaminationException $e) {
    echo 'refused:'.$e->errorCode();
} catch (Throwable $e) {
    echo 'error:'.$e::class;
} finally {
    $context->clearAll();
}

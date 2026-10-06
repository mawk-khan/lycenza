<?php

use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\Students\Application\StudentEnrollmentService;
use App\Domain\Students\Application\StudentSubjectEnrollmentService;
use App\Domain\Students\Application\SubjectOfferingEligibilityReadService;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Domain\Students\Infrastructure\StudentSubjectEnrollment;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\Support\Concurrency\HeldTransaction;

// RES.1 (ADR 0068 §5.4): a genuinely separate OS process for
// SubjectOfferingEligibilityConcurrencyTest. As the HOLDER, the lock-capable
// P3 read keeps its FOR SHARE locks uncommitted until released; as the
// CONTENDER, a Students lifecycle write must wait for them
// (Tests\Concerns\ForcesConcurrentOverlap).
//
// Usage:
//   php subject-offering-eligibility-op.php lock-eligibility <schoolId> <studentId> <offeringId> <date>
//   php subject-offering-eligibility-op.php withdraw-elective <schoolId> <subjectEnrollmentId> <endedOn>
//   php subject-offering-eligibility-op.php transfer-placement <schoolId> <enrollmentId> <targetSectionId> <rollNumber> <effectiveDate>

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$op = $argv[1];
$school = School::query()->findOrFail($argv[2]);
$context = $app->make(TenantContext::class);
$context->set($school);

try {
    echo HeldTransaction::run(function () use ($app, $op, $school, $argv): string {
        if ($op === 'lock-eligibility') {
            $answer = DB::transaction(fn () => $app->make(SubjectOfferingEligibilityReadService::class)->lockEligibilityAsOf($school, $argv[3], $argv[4], $argv[5]));

            return $answer->eligible ? "eligible:{$answer->source}:{$answer->studentEnrollmentId}" : "not:{$answer->reason}";
        }
        if ($op === 'withdraw-elective') {
            $app->make(StudentSubjectEnrollmentService::class)->withdraw(StudentSubjectEnrollment::query()->findOrFail($argv[3]), $argv[4]);

            return 'withdrawn';
        }

        $app->make(StudentEnrollmentService::class)->transferPlacement(
            StudentEnrollment::query()->findOrFail($argv[3]), Section::query()->findOrFail($argv[4]), $argv[5], $argv[6],
        );

        return 'transferred';
    });
} catch (Throwable $e) {
    echo 'error:'.$e::class.':'.$e->getMessage();
} finally {
    $context->clearAll();
}

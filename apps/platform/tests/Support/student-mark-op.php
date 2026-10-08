<?php

use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\Examinations\Application\Exceptions\ExaminationException;
use App\Domain\Examinations\Application\Marks\StudentMarkCorrectionService;
use App\Domain\Examinations\Application\Marks\StudentMarkEntry;
use App\Domain\Examinations\Application\Marks\StudentMarkLockService;
use App\Domain\Examinations\Application\Marks\StudentMarkService;
use App\Domain\Examinations\Application\Marks\TeacherStudentMarkAccess;
use App\Domain\Students\Application\StudentEnrollmentService;
use App\Domain\Students\Application\StudentProcessingAuthorizationService;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Domain\Students\Infrastructure\StudentProcessingAuthorization;
use App\Domain\TeachingAssignments\Application\ElectiveTeachingAssignmentService;
use App\Domain\TeachingAssignments\Application\TeachingAssignmentService;
use App\Models\School;
use App\Models\User;
use App\Support\Observability\Metrics\MetricStore;
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
// RES.3 (ADR 0068 §21):
//   php student-mark-op.php lock <schoolId> <paperId> <actorId>
//   php student-mark-op.php request-correction <schoolId> <paperId> <markId> <actorId> <expectedVersion> <status> <value|-> <reasonCode>
//   php student-mark-op.php approve-correction <schoolId> <correctionId> <actorId>
//   php student-mark-op.php reject-correction <schoolId> <correctionId> <actorId>
// RES.4 (ADR 0068 §25):
//   php student-mark-op.php teacher-record <schoolId> <paperId> <teacherUserId> <studentId> <status> <value|-> <expectedVersion|->
//   php student-mark-op.php end-assignment <schoolId> <teachingAssignmentId> <endsOn> <actorId>
//   php student-mark-op.php end-elective-assignment <schoolId> <electiveTeachingAssignmentId> <endsOn> <actorId>

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
        if ($op === 'teacher-record') {
            $teacher = User::query()->findOrFail($argv[4]);
            $written = $app->make(StudentMarkService::class)->record($school, $argv[3], [new StudentMarkEntry(
                $argv[5], $argv[6], $argv[7] === '-' ? null : $argv[7], $argv[8] === '-' ? null : (int) $argv[8],
            )], $teacher, $app->make(TeacherStudentMarkAccess::class)->guard($teacher));

            return 'recorded:v'.$written[0]['version'];
        }
        if ($op === 'end-assignment' || $op === 'end-elective-assignment') {
            $service = $app->make($op === 'end-assignment' ? TeachingAssignmentService::class : ElectiveTeachingAssignmentService::class);

            return 'ended:'.$service->end($school, $argv[3], $argv[4], 'reassigned', User::query()->findOrFail($argv[5]))->ends_on->toDateString();
        }
        if ($op === 'lock') {
            return 'locked:'.$app->make(StudentMarkLockService::class)->lock($school, $argv[3], User::query()->findOrFail($argv[4]))->state;
        }
        if ($op === 'request-correction') {
            return 'requested:'.$app->make(StudentMarkCorrectionService::class)->request(
                $school, $argv[3], $argv[4], (int) $argv[6], $argv[7], $argv[8] === '-' ? null : $argv[8], $argv[9], User::query()->findOrFail($argv[5]),
            )->status;
        }
        if ($op === 'approve-correction' || $op === 'reject-correction') {
            $corrections = $app->make(StudentMarkCorrectionService::class);
            $actor = User::query()->findOrFail($argv[4]);

            return 'decided:'.($op === 'approve-correction' ? $corrections->approve($school, $argv[3], $actor) : $corrections->reject($school, $argv[3], $actor))->status;
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
    // S5 observability follow-up: a parent test can read this process's (array) metric store.
    $dump = getenv('METRICS_DUMP_FILE');
    if ($dump !== false && $dump !== '') {
        file_put_contents($dump, json_encode($app->make(MetricStore::class)->all()));
    }
}

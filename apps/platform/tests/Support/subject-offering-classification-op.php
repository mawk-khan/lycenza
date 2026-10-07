<?php

use App\Domain\AcademicStructure\Application\SubjectOfferingService;
use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use App\Domain\CurriculumDelivery\Application\CurriculumDeliveryService;
use App\Domain\Examinations\Application\ExaminationPaperService;
use App\Domain\Examinations\Application\Marks\StudentMarkEntry;
use App\Domain\Examinations\Application\Marks\StudentMarkService;
use App\Domain\Examinations\Infrastructure\Examination;
use App\Domain\Students\Application\StudentSubjectEnrollmentService;
use App\Domain\Students\Infrastructure\Student;
use App\Domain\TeachingAssignments\Application\TeachingAssignmentService;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Tests\Support\Concurrency\HeldTransaction;

// ADR 0069 (S1): a genuinely separate OS process for
// SubjectOfferingClassificationConcurrencyTest. HeldTransaction keeps the
// HOLDER's work uncommitted until released (Tests\Concerns\ForcesConcurrentOverlap).
//
// Usage:
//   php subject-offering-classification-op.php flip <schoolId> <offeringId> <required:1|0> <actorId>
//   php subject-offering-classification-op.php create-ta <schoolId> <employeeId> <sectionId> <offeringId> <actorId>
//   php subject-offering-classification-op.php enroll-elective <schoolId> <studentId> <offeringId>
//   php subject-offering-classification-op.php create-paper <schoolId> <examinationId> <offeringId> <scheduledOn> <actorId>
//   php subject-offering-classification-op.php start-delivery <schoolId> <offeringId> <sectionId> <unitId> <startedOn> <actorId>
//   php subject-offering-classification-op.php record-mark <schoolId> <paperId> <actorId> <studentId> <value> <expectedVersion|->

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$op = $argv[1];
$school = School::query()->findOrFail($argv[2]);
$context = $app->make(TenantContext::class);
$context->set($school);

try {
    echo HeldTransaction::run(function () use ($app, $op, $school, $argv): string {
        return match ($op) {
            'flip' => 'flipped:'.($app->make(SubjectOfferingService::class)->update($school, $argv[3], ['is_required' => $argv[4] === '1'], User::query()->findOrFail($argv[5]))->is_required ? 'required' : 'elective'),
            'create-ta' => 'assigned:'.$app->make(TeachingAssignmentService::class)->create($school, $argv[3], $argv[4], $argv[5], '2026-06-01', null, User::query()->findOrFail($argv[6]))->subject_offering_id,
            'enroll-elective' => 'enrolled:'.$app->make(StudentSubjectEnrollmentService::class)->enroll(Student::query()->findOrFail($argv[3]), SubjectOffering::query()->findOrFail($argv[4]), '2026-06-01')->status,
            'create-paper' => 'paper:'.$app->make(ExaminationPaperService::class)->create($school, Examination::query()->findOrFail($argv[3]), [
                'subject_offering_id' => $argv[4], 'scheduled_on' => $argv[5], 'starts_at' => '09:00', 'ends_at' => '10:00', 'max_marks' => '20.00',
            ], User::query()->findOrFail($argv[6]))->status,
            'start-delivery' => 'delivery:'.$app->make(CurriculumDeliveryService::class)->start($school, $argv[3], $argv[4], $argv[5], $argv[6], User::query()->findOrFail($argv[7]))->status,
            'record-mark' => 'recorded:v'.$app->make(StudentMarkService::class)->record($school, $argv[3], [new StudentMarkEntry($argv[5], 'present', $argv[6], $argv[7] === '-' ? null : (int) $argv[7])], User::query()->findOrFail($argv[4]))[0]['version'],
            default => throw new InvalidArgumentException("unknown op {$op}"),
        };
    });
} catch (QueryException $e) {
    echo preg_match('/subject_offering_classification_(locked|mismatch)/', $e->getMessage(), $m) === 1 ? 'db:'.$m[0] : 'error:'.$e::class;
} catch (Throwable $e) {
    echo method_exists($e, 'errorCode') ? 'refused:'.$e->errorCode() : 'error:'.$e::class;
} finally {
    $context->clearAll();
}

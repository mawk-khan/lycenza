<?php

use App\Domain\Guardians\Application\StudentGuardianRelationshipService;
use App\Domain\Guardians\Infrastructure\StudentGuardianRelationship;
use App\Domain\Students\Application\StudentProcessingAuthorizationService;
use App\Domain\Students\Domain\ProcessingAuthorizationPurpose;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantRls;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\Support\Concurrency\HeldTransaction;

// S5 (ADR 0038 §lock-order amendment; ADR 0068 §27.11): separate OS processes
// for GuardianProcessingAuthorizationLockOrderTest.
//
//   php guardian-lock-order-op.php unlink <schoolId> <relationshipId> <actorId>
//   php guardian-lock-order-op.php set-primary <schoolId> <relationshipId> <actorId>
//   php guardian-lock-order-op.php record-consent <schoolId> <relationshipId> <actorId>   (academic_records Guardian consent)
//
// Stepped holders (env CONCURRENCY_HOLD_DIR): take the first locks, touch
// `acted`, wait for `step2`, take the next locks, touch `step2done`, wait for
// `release`, commit. They replay a real path's statements in its real order,
// so a lock-order cycle with a blocked contender shows up as a PostgreSQL
// deadlock instead of hiding in timing.
//
//   seam-steps <schoolId> <studentId> <grantIdsCsv> <phase1RelationshipIdsCsv|-> <phase2RelationshipIdsCsv>
//       StudentProcessingAuthorizationReadService::lockQualifyingAuthorizationIdForProcessing(), step by step:
//       Student FOR UPDATE, its grants FOR UPDATE (newest first), phase-1 relationships | step2 | phase-2 relationships.
//   paper-cycle <schoolId> <studentId> <paperId>
//       Student FOR UPDATE | step2 | the paper FOR UPDATE -- a true deadlock against a StudentMark write that holds
//       the paper FOR SHARE and waits for the Student (deadlock_timeout 10s here, so the StudentMark side is the victim).

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$op = $argv[1];
$school = School::query()->findOrFail($argv[2]);
$context = $app->make(TenantContext::class);
$context->set($school);

$waitFor = function (string $file): void {
    $dir = (string) getenv('CONCURRENCY_HOLD_DIR');
    $deadline = microtime(true) + 120;
    while (! file_exists("{$dir}/{$file}")) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException("never got {$file}");
        }
        usleep(2_000);
    }
};
$touch = fn (string $file) => touch(getenv('CONCURRENCY_HOLD_DIR')."/{$file}");
$outcome = fn (Throwable $e): string => str_contains($e->getMessage(), 'deadlock detected') ? 'deadlock'
    : (str_contains($e->getMessage(), 'foreign key') ? 'refused:fk' : (method_exists($e, 'errorCode') ? 'refused:'.$e->errorCode() : 'error:'.$e::class));

try {
    if ($op === 'seam-steps' || $op === 'paper-cycle') {
        // paper-cycle raises its own deadlock_timeout (a superuser setting) so the StudentMark side is the victim.
        $db = DB::connection($op === 'paper-cycle' ? 'pgsql_admin' : null);
        $db->beginTransaction();
        $db->select('select set_config(?, ?, true)', [TenantRls::SESSION_VAR, $school->id]);
        $result = 'ok';
        if ($op === 'seam-steps') {
            $db->select('select id from students where id = ? for update', [$argv[3]]);
            $db->select('select id from student_processing_authorizations where id = any(?::uuid[]) order by recorded_at desc, id desc for update', ['{'.$argv[4].'}']);
            foreach ($argv[5] === '-' ? [] : explode(',', $argv[5]) as $relationshipId) {
                $db->select('select id from student_guardian_relationships where id = ? for update', [$relationshipId]);
            }
        } else {
            $db->statement("set local deadlock_timeout = '10s'");
            $db->select('select id from students where id = ? for update', [$argv[3]]);
        }
        $touch('acted');
        $waitFor('step2');
        try {
            if ($op === 'seam-steps') {
                foreach (explode(',', $argv[6]) as $relationshipId) {
                    $db->select('select id from student_guardian_relationships where id = ? for update', [$relationshipId]);
                }
            } else {
                $db->select('select id from examination_papers where id = ? for update', [$argv[4]]);
            }
        } catch (Throwable $e) {
            $result = $outcome($e);
        }
        $touch('step2done');
        $waitFor('release');
        $result === 'ok' ? $db->commit() : $db->rollBack();
        echo $result;
    } else {
        echo HeldTransaction::run(function () use ($app, $op, $argv): string {
            $service = $app->make(StudentGuardianRelationshipService::class);
            $relationship = StudentGuardianRelationship::query()->findOrFail($argv[3]);
            $actor = User::query()->findOrFail($argv[4]);
            if ($op === 'record-consent') {
                $grant = $app->make(StudentProcessingAuthorizationService::class)->recordGuardianConsent(
                    $relationship->school, $relationship->student, ProcessingAuthorizationPurpose::AcademicRecords, $relationship, $actor,
                );

                return 'consented:'.$grant->status->value;
            }
            if ($op === 'unlink') {
                $service->unlink($relationship, $actor);

                return 'unlinked';
            }

            return 'primary:'.($service->setPrimary($relationship, $actor)->is_primary ? 'yes' : 'no');
        });
    }
} catch (Throwable $e) {
    echo $outcome($e);
} finally {
    $context->clearAll();
}

<?php

use App\Domain\Attendance\Application\AttendanceCorrectionService;
use App\Domain\Attendance\Application\AttendanceSubmissionService;
use App\Domain\Attendance\Application\Exceptions\AttendanceException;
use App\Domain\Attendance\Application\TeacherAttendanceAccess;
use App\Domain\HR\Application\Exceptions\ActingEmployeeUnavailableException;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Tests\Support\Concurrency\HeldTransaction;

// Standalone bootstrap script for TeacherAttendanceConcurrencyTest (TCH.4,
// ADR 0063 section 20): one OWNED teacher Attendance write in a GENUINELY
// separate OS process -- AttendanceSubmissionService /
// AttendanceCorrectionService with the TeacherAttendanceGuard, exactly as
// the /my/ endpoints call them. Mirrors teacher-delivery-op.php; the
// ineligibility side reuses acting-employee-op.php, staff-account-op.php
// and teaching-assignment-op.php.
//
// Usage:
//   php teacher-attendance-op.php submit  <schoolId> <userId> <timetableEntryId> <date> <enrollmentId...>
//   php teacher-attendance-op.php correct <schoolId> <userId> <recordId> <expectedStatus> <newStatus>

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$args = array_slice($argv, 1);
$operation = array_shift($args);
$context = $app->make(TenantContext::class);

try {
    echo HeldTransaction::run(function () use ($app, $context, $operation, $args): string {
        $access = $app->make(TeacherAttendanceAccess::class);

        switch ($operation) {
            case 'submit':
                $schoolId = array_shift($args);
                $userId = array_shift($args);
                $entryId = array_shift($args);
                $date = array_shift($args);
                $school = School::query()->findOrFail($schoolId);
                $user = User::query()->findOrFail($userId);
                $records = array_map(fn (string $id) => ['student_enrollment_id' => $id, 'status' => 'present'], $args);
                $service = $app->make(AttendanceSubmissionService::class);

                // The caller-owned transaction the /my/ endpoints open (a
                // savepoint inside HeldTransaction's when this is the holder).
                $session = $context->withSchool($school, fn () => $service->guarded(fn () => DB::transaction(
                    fn () => $service->submit($school, $entryId, $date, $records, $user, $access->guard($user)),
                )));

                return 'submitted:'.$session->id;
            case 'correct':
                [$schoolId, $userId, $recordId, $expected, $new] = $args;
                $school = School::query()->findOrFail($schoolId);
                $user = User::query()->findOrFail($userId);
                $app->make(AttendanceCorrectionService::class)->correct($school, $recordId, $expected, $new, $user, $access->guard($user));

                return 'corrected';
        }

        return 'unknown';
    });
} catch (ActingEmployeeUnavailableException $e) {
    echo 'denied:'.$e->reason;
} catch (AttendanceException $e) {
    echo 'rejected:'.$e->errorCode();
} catch (ModelNotFoundException) {
    echo 'not_found';
} catch (Throwable $e) {
    echo 'error:'.$e::class.':'.$e->getMessage();
} finally {
    $context->clearAll();
}

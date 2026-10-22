<?php

namespace Database\Factories;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\Students\Infrastructure\Student;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Domain\Students\Infrastructure\StudentLifecycleEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use WeakMap;

/**
 * ORG.8G (ADR 0076 §65): factories never insert a Student or placement in an
 * ended state -- the database refuses it (`*_born_active`). A factory asked
 * for `inactive` / `withdrawn` / `cancelled` / `completed` / `transferred`
 * inserts the row `active` and then makes the transition the way the
 * database contract requires: the lifecycle event first (for the
 * evidenced transitions), then the status change. Fixture data only; the
 * acting User is a fresh fixture User, never a real one.
 */
final class StudentLifecycleFixture
{
    /** @var WeakMap<object, array{status: string, ends_on: mixed}>|null */
    private static ?WeakMap $pending = null;

    /** @return WeakMap<object, array{status: string, ends_on: mixed}> */
    private static function pending(): WeakMap
    {
        return self::$pending ??= new WeakMap;
    }

    public static function deferStudent(Student $student): void
    {
        if ($student->status !== null && $student->status !== 'active') {
            self::pending()[$student] = ['status' => $student->status, 'ends_on' => null];
            $student->status = 'active';
        }
    }

    public static function completeStudent(Student $student): void
    {
        $target = self::pending()[$student] ?? null;
        if ($target === null) {
            return;
        }
        unset(self::pending()[$student]);
        $actorId = User::factory()->create()->id;
        // The event and the transition share one transaction (the evidence is `occurred_at = now()`).
        DB::transaction(function () use ($student, $target, $actorId) {
            StudentLifecycleEvent::query()->create([
                'school_id' => $student->school_id, 'student_id' => $student->id,
                'action' => $target['status'] === 'inactive' ? 'student_deactivated' : 'student_reactivated',
                'reason_code' => $target['status'] === 'inactive' ? 'left_school' : 'returned_to_school',
                'acted_by_user_id' => $actorId,
            ]);
            Student::query()->whereKey($student->id)->update(['status' => $target['status']]);
        });
        $student->status = $target['status'];
    }

    public static function deferPlacement(StudentEnrollment $placement): void
    {
        if ($placement->status !== null && $placement->status !== 'active') {
            // An ended placement has an end date: the requested one, else its year's
            // last day for a completion and its start day otherwise.
            self::pending()[$placement] = ['status' => $placement->status, 'ends_on' => $placement->ends_on
                ?? ($placement->status === 'completed' ? AcademicYear::query()->findOrFail($placement->academic_year_id)->ends_on : $placement->starts_on)];
            $placement->status = 'active';
            $placement->ends_on = null;
        }
    }

    public static function completePlacement(StudentEnrollment $placement): void
    {
        $target = self::pending()[$placement] ?? null;
        if ($target === null) {
            return;
        }
        unset(self::pending()[$placement]);
        $evidenced = in_array($target['status'], ['withdrawn', 'cancelled'], true);
        $actorId = $evidenced ? User::factory()->create()->id : null;
        DB::transaction(function () use ($placement, $target, $evidenced, $actorId) {
            if ($evidenced) {
                StudentLifecycleEvent::query()->create([
                    'school_id' => $placement->school_id, 'student_id' => $placement->student_id, 'student_enrollment_id' => $placement->id,
                    'action' => $target['status'] === 'withdrawn' ? 'enrollment_withdrawn' : 'enrollment_cancelled',
                    'reason_code' => $target['status'] === 'withdrawn' ? 'left_school' : 'entered_in_error',
                    'acted_by_user_id' => $actorId,
                ]);
            }
            StudentEnrollment::query()->whereKey($placement->id)->update(['status' => $target['status'], 'ends_on' => $target['ends_on']]);
        });
        $placement->refresh();
    }
}

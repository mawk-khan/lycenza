<?php

namespace App\Domain\Examinations\Application\Marks;

use App\Domain\Examinations\Application\Exceptions\StudentMarksUnavailableException;

/**
 * RES.5 (ADR 0068 §27) -- the production block for ALL StudentMark
 * processing, administrative and teacher alike. RES.2 / RES.3 were authorised
 * for development only and production waits for RES-L1 (E36); until RES.5
 * that rule was enforced by process only, while the seeder grants the
 * administrative marks keys to `school_admin` / `principal` everywhere. Now
 * every marks read and write runs only when `APP_ENV` is `local` or
 * `testing`.
 *
 * Deliberately not configurable (no variable or flag opens it). Lifting it is
 * a reviewed code change made once RES-L1 (and RES-L8 for retention) permit
 * production StudentMark. Teacher marks stay additionally behind
 * TeacherStudentMarkAvailability (RES-L2, the teacher RES-L0 re-review).
 *
 * Checked first by every marks Application entry point (entry, grid, lock,
 * correction request / approve / reject, the teacher scope) and again by the
 * `marks-development-only` route middleware.
 */
final class StudentMarkAvailability
{
    /** @var list<string> */
    public const array ENVIRONMENTS = ['local', 'testing'];

    public static function isAvailable(): bool
    {
        return app()->environment(self::ENVIRONMENTS);
    }

    /** @throws StudentMarksUnavailableException */
    public static function assertAvailable(): void
    {
        if (! self::isAvailable()) {
            throw new StudentMarksUnavailableException;
        }
    }
}

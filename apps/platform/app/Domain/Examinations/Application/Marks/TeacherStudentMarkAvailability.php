<?php

namespace App\Domain\Examinations\Application\Marks;

use App\Domain\Examinations\Application\Exceptions\TeacherStudentMarksUnavailableException;

/**
 * RES.4 (ADR 0068 §25.3) -- the INTERIM production block for teacher
 * StudentMark processing. The product owner authorised RES.4 engineering
 * development (7 October 2026); that is not a legal or privacy determination,
 * and RES-L2 (E37) and the teacher-scope RES-L0 re-review (E35) remain
 * undetermined. Until they are answered, teacher marks run in `local` and
 * `testing` only.
 *
 * Deliberately not configurable: there is no environment variable or config
 * flag that opens it, so neither a misconfigured deployment nor an accidental
 * `examinations.marks.teacher` grant (to `teacher` or any other role) makes it
 * production-effective. Lifting it is a reviewed code change made only once
 * the determinations (and RES-L1) allow it.
 *
 * Checked by every teacher marks path in the Application layer (read and
 * write) and again by the route middleware
 * (App\Http\Middleware\EnsureTeacherStudentMarksDevelopmentOnly).
 */
final class TeacherStudentMarkAvailability
{
    /** @var list<string> */
    public const array ENVIRONMENTS = ['local', 'testing'];

    public static function isAvailable(): bool
    {
        return app()->environment(self::ENVIRONMENTS);
    }

    /** @throws TeacherStudentMarksUnavailableException */
    public static function assertAvailable(): void
    {
        if (! self::isAvailable()) {
            throw new TeacherStudentMarksUnavailableException;
        }
    }
}

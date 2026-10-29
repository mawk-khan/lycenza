<?php

namespace App\Domain\Students\Application;

use App\Domain\Students\Infrastructure\Student;

/**
 * SIS.EXP-1 (SIS.EXP-0 finding L-2): the optimistic-concurrency token of a
 * Student's editable identity -- a digest of exactly the fields the two edit
 * paths write (StudentService::update(), StudentNameEditService::edit()).
 *
 * - The edit form carries it; the save recomputes it under the Student row
 *   lock and refuses a mismatch (StudentProfileChangedException), so an old
 *   form can never overwrite a newer change.
 * - A digest of the values, not `updated_at`: the column has second
 *   precision, and a status change elsewhere must not make an identity form
 *   stale. No schema change.
 * - KEYED (HMAC, a purpose key derived from the application key): the
 *   digest covers the date of birth, and a Campus name editor -- who must
 *   never see it (rule 101) -- sees every other input. An unkeyed hash
 *   would let them recover the date by trying the few thousand candidate
 *   days (SIS.EXP-1 security review H-1).
 */
final class StudentProfileVersion
{
    public static function of(Student $student): string
    {
        return hash_hmac('sha256', implode("\x1f", [
            $student->id,
            $student->student_number,
            $student->first_name,
            $student->middle_name ?? '',
            $student->last_name ?? '',
            $student->date_of_birth->toDateString(),
        ]), self::key());
    }

    private static function key(): string
    {
        return hash('sha256', 'lycenza:student-profile-version:v1|'.(string) config('app.key'), true);
    }

    public static function matches(Student $student, string $expected): bool
    {
        return hash_equals(self::of($student), $expected);
    }
}

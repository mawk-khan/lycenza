<?php

namespace App\Domain\Students\Application;

use App\Domain\Students\Infrastructure\Student;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;

/**
 * ORG.7F-2 (ADR 0076 §46, owner decisions F2-D5 to F2-D7): THE server-side
 * Student-number allocator. A generated number is the plain decimal
 * representation of the School's next sequence value: School-wide, stable,
 * never Campus- or academic-year-coded, never chosen by a user.
 *
 * - One School only: the counter row (`student_number_sequences`) is locked
 *   FOR UPDATE, so concurrent allocations in a School serialize; different
 *   Schools never contend.
 * - Transactional: allocation runs inside the caller's write transaction
 *   and is committed with the Student it numbers. A rolled-back onboarding
 *   leaves the counter where it was, so its number was never held by any
 *   Student and is simply issued to the next onboarding -- no duplicate, no
 *   gap. A number that was committed is never issued again (the counter only
 *   moves forward, and the database trigger moves it past every numeric
 *   number written by any other path).
 * - Defence in depth: a candidate already held by a Student (a race with a
 *   manual entry the trigger has not yet seen) is skipped, never shared;
 *   the `(school_id, student_number)` unique index is the final backstop.
 * - Never exposed: no route, no "next number" preview, no reservation.
 */
final class StudentNumberAllocator
{
    private const MAX_SKIPS = 1000;

    public function __construct(private readonly TenantContext $context) {}

    public function allocate(School $school): string
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('StudentNumberAllocator::allocate() must run inside the creating write transaction.');
        }

        return $this->context->withSchool($school, function () use ($school): string {
            DB::insert('INSERT INTO student_number_sequences (school_id, next_value, created_at, updated_at) VALUES (?, 1, now(), now()) ON CONFLICT (school_id) DO NOTHING', [$school->id]);
            $next = (int) DB::selectOne('SELECT next_value FROM student_number_sequences WHERE school_id = ? FOR UPDATE', [$school->id])->next_value;

            for ($skips = 0; $skips < self::MAX_SKIPS; $skips++, $next++) {
                $candidate = (string) $next;
                if (! Student::query()->where('school_id', $school->id)->where('student_number', $candidate)->exists()) {
                    DB::update('UPDATE student_number_sequences SET next_value = ?, last_allocated_value = ?, updated_at = now() WHERE school_id = ?', [$next + 1, $next, $school->id]);

                    return $candidate;
                }
            }

            throw new RuntimeException('No free Student number found near the School\'s sequence.');
        });
    }
}

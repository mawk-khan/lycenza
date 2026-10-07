<?php

namespace App\Domain\Students\Application;

use App\Domain\Students\Infrastructure\Student;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * S5 (ADR 0038 lock-order amendment, 2026-10-07): the ONE canonical order for
 * every path touching a Student's processing authorizations or guardian
 * relationships:
 *
 *   Student FOR UPDATE -> its processing-authorization grants -> its guardian relationships
 *
 * StudentProcessingAuthorizationReadService::lockQualifyingAuthorizationIdForProcessing()
 * (the ADR 0038 seam StudentMark uses) and StudentProcessingAuthorizationService's
 * withdraw / revoke / supersede already start at the Student. Guardian
 * relationship writers that change a relationship an existing grant may
 * depend on -- unlink, setPrimary, update -- call holdStudent() first, so they
 * serialize with the seam at the Student instead of forming a cycle with it
 * (unlink's RESTRICT check reaches the grants after the relationship;
 * setPrimary locks two relationships in its own order). One row, per Student:
 * unrelated Students never wait on each other.
 */
class StudentLockOrder
{
    public function __construct(private readonly TenantContext $context) {}

    /** Inside the caller's transaction: the Student row FOR UPDATE (a Student outside the School locks nothing). */
    public function holdStudent(School $school, string $studentId): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('StudentLockOrder::holdStudent() must run inside a database transaction.');
        }

        $this->context->withSchool($school, fn () => Student::query()->where('school_id', $school->id)->whereKey($studentId)->lockForUpdate()->first());
    }
}

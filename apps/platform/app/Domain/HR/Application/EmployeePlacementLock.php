<?php

namespace App\Domain\HR\Application;

use App\Domain\HR\Infrastructure\Employee;
use App\Models\School;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * ORG.7E-1 (ADR 0076 §43): THE lock on an Employee's operational placement
 * -- the one row every writer of placement and every Campus-scoped write
 * whose authority depends on placement serializes on: the `employees` row.
 *
 *   forPlacementChange()  FOR SHARE         every writer that can change the
 *                                           Employee's current Campus set
 *                                           (assignment create/end/primary,
 *                                           employment end, archive/restore)
 *   forEmployeeWrite()    FOR NO KEY UPDATE a write of the Employee row itself
 *                                           (identity edit, archive,
 *                                           restore) and every Campus-scoped
 *                                           decision that needs EVERY current
 *                                           Campus of the Employee in scope
 *                                           (identity edit, new assignment)
 *
 * The two conflict, so a Campus-scoped decision and a placement change never
 * interleave: whichever locks first commits first, and the other then reads
 * the committed placement. `EmploymentService` creates employment under the
 * stronger FOR UPDATE it always took, which conflicts with both. NO KEY
 * UPDATE, never FOR UPDATE, for forEmployeeWrite(): it must not block the
 * foreign-key KEY SHARE locks of rows that merely reference the Employee
 * (CLAUDE.md rule 101, the ORG.7D-4 deadlock lesson).
 *
 * Lock order (every HR placement writer): this Employee lock FIRST ->
 * EmploymentRecord -> EmployeeAssignment rows (sorted id) -> Campus rows FOR
 * SHARE (ActiveCampus, sorted id). The account link keeps its own order,
 * membership -> User -> Employee (EmployeeService::link(),
 * ActingEmployeeResolver::hold()); no HR writer takes a membership or User
 * lock after this Employee lock. Called inside the caller's transaction and
 * the School's TenantContext only.
 */
final class EmployeePlacementLock
{
    public function forPlacementChange(School $school, string $employeeId): Employee
    {
        return $this->lock($school, $employeeId, 'share');
    }

    public function forEmployeeWrite(School $school, string $employeeId): Employee
    {
        return $this->lock($school, $employeeId, 'no key update');
    }

    private function lock(School $school, string $employeeId, string $mode): Employee
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('The Employee placement lock is taken only inside the writing transaction.');
        }

        $query = Employee::query()->where('school_id', $school->id)->whereKey($employeeId);
        $locked = $mode === 'share' ? $query->sharedLock()->first() : $query->lock('for no key update')->first();

        return $locked ?? throw (new ModelNotFoundException)->setModel(Employee::class, [$employeeId]);
    }
}

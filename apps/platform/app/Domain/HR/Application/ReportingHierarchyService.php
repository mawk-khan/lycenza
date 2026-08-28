<?php

namespace App\Domain\HR\Application;

use App\Domain\HR\Application\Exceptions\AssignmentManagerMismatchException;
use App\Domain\HR\Application\Exceptions\ReportingHierarchyCycleException;
use App\Domain\HR\Application\Exceptions\SameEmployeeReportingException;
use App\Domain\HR\Application\Exceptions\SelfReportingException;
use App\Domain\HR\Events\EmployeeManagerChanged;
use App\Domain\HR\Infrastructure\EmployeeAssignment;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * The only sanctioned write path for `employee_assignments.manager_assignment_id`
 * (docs/modules/HR.md "Reporting hierarchy strategy") -- never assign
 * it directly outside a test. `setManager()` is the single operation
 * for setting, changing, and clearing a manager (accepting `null`
 * clears it) -- there is no separate `changeManager()`, since "change"
 * is just "set" called again on an Assignment that already has a
 * manager; splitting it into two methods would duplicate the same
 * validation for no benefit.
 *
 * Concurrency: a naive "check current hierarchy, then write" is not
 * safe against two concurrent opposite-direction changes (Transaction
 * 1: A's manager -> B; Transaction 2: B's manager -> A) -- both could
 * read the pre-change state and both pass their own cycle check before
 * either commits, producing a real A<->B cycle. `setManager()` closes
 * this by locking BOTH involved Assignment rows, one at a time, via
 * SEPARATE sequential `WHERE id = ? FOR UPDATE` statements issued in
 * sorted-`id` order -- deliberately NOT a single multi-row
 * `whereIn(...)->orderBy('id')->lockForUpdate()` query, because
 * Postgres does not guarantee that a multi-row locking `SELECT`
 * acquires its row locks in the `ORDER BY` sequence (`ORDER BY` only
 * governs the returned result set, not lock-acquisition order during
 * the scan) -- only genuinely sequential statements, issued in that
 * exact order by the application itself, guarantee the first row is
 * locked before the second is even requested. Because both concurrent
 * callers lock in the SAME sorted order regardless of which one calls
 * first, the second transaction always blocks on the shared row until
 * the first commits, then re-reads the now-current state (via the
 * cycle check below, run only after both locks are held) and correctly
 * detects the cycle the first transaction's change created. Proven
 * with two genuinely separate OS processes
 * (tests/Support/set-assignment-manager.php), mirroring
 * AcademicYearActivationConcurrencyTest's/EmployeeNumberConcurrencyTest's
 * established real-process pattern -- not just asserted.
 */
class ReportingHierarchyService
{
    use AuthorizesCapability;

    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
    ) {}

    public function setManager(EmployeeAssignment $subordinate, ?EmployeeAssignment $manager, User $actor): EmployeeAssignment
    {
        $school = $subordinate->school;
        $this->authorizeCapabilityFor($actor, 'hr.employees.assignments.manage', $school);

        return $this->context->withSchool($school, function () use ($school, $subordinate, $manager, $actor) {
            if ($manager !== null) {
                $this->assertSameSchool($school->id, $manager);
                $this->assertNotSelf($subordinate, $manager);
                $this->assertNotSameEmployee($subordinate, $manager);
            }

            return DB::transaction(function () use ($school, $subordinate, $manager, $actor) {
                $lockIds = $manager !== null ? [$subordinate->id, $manager->id] : [$subordinate->id];
                sort($lockIds);

                // Sequential, single-row SELECT ... FOR UPDATE statements, in
                // sorted order -- NOT a single whereIn()->orderBy()->lockForUpdate()
                // query. Postgres does not guarantee that a multi-row locking
                // SELECT acquires its row locks in the ORDER BY sequence (the
                // ORDER BY only governs the result set, not lock-acquisition
                // order during the scan); only genuinely sequential statements,
                // issued in this exact order by the application itself,
                // guarantee the first row is locked before the second is even
                // requested. This is what makes the deterministic-lock-order
                // scheme actually deadlock-and-race-free under concurrency.
                foreach ($lockIds as $id) {
                    EmployeeAssignment::query()->where('id', $id)->lockForUpdate()->firstOrFail();
                }

                if ($manager !== null) {
                    $this->assertNoCycle($subordinate, $manager);
                }

                $previousManagerId = $subordinate->manager_assignment_id;
                $subordinate->update(['manager_assignment_id' => $manager?->id]);

                $employeeId = $subordinate->employmentRecord->employee_id;

                $this->audit->school($school, 'hr.assignment.manager_changed', actor: $actor, subject: $subordinate, metadata: [
                    // Phase 8A.11: employeeId added, purely additive -- this
                    // event belongs to the SUBORDINATE's Employee Activity
                    // Timeline (whose reporting line changed), not the
                    // manager's.
                    'employeeId' => $employeeId,
                    'subordinateAssignmentId' => $subordinate->id,
                    'previousManagerAssignmentId' => $previousManagerId,
                    'newManagerAssignmentId' => $manager?->id,
                ]);

                event(new EmployeeManagerChanged($school->id, $employeeId, $subordinate->id, $previousManagerId, $manager?->id));

                return $subordinate->fresh();
            });
        });
    }

    private function assertSameSchool(string $schoolId, EmployeeAssignment $manager): void
    {
        if ($manager->school_id !== $schoolId) {
            throw new AssignmentManagerMismatchException($manager->id, $schoolId, $manager->school_id);
        }
    }

    private function assertNotSelf(EmployeeAssignment $subordinate, EmployeeAssignment $manager): void
    {
        if ($subordinate->id === $manager->id) {
            throw new SelfReportingException($subordinate->id);
        }
    }

    /**
     * docs/modules/HR.md 8A.5: self-management through another
     * Position is normally semantically invalid and can create
     * confusing hierarchy loops.
     */
    private function assertNotSameEmployee(EmployeeAssignment $subordinate, EmployeeAssignment $manager): void
    {
        $subordinateEmployeeId = $subordinate->employmentRecord->employee_id;
        $managerEmployeeId = $manager->employmentRecord->employee_id;

        if ($subordinateEmployeeId === $managerEmployeeId) {
            throw new SameEmployeeReportingException($subordinate->id, $manager->id);
        }
    }

    /**
     * Deterministic ancestry walk, not a generic graph engine -- each
     * Assignment has at most one manager, so this is a simple bounded
     * chain (mirrors DepartmentService::assertNoCycle()'s identical
     * shape for Department hierarchy).
     */
    private function assertNoCycle(EmployeeAssignment $subordinate, EmployeeAssignment $proposedManager): void
    {
        // Deliberately re-fetches from the database on EVERY hop, including
        // the very first -- $proposedManager is the caller-supplied object,
        // which may have been read before this transaction even attempted
        // its locks (e.g. by a concurrent process that fetched it, then
        // blocked waiting for the lock). Using its in-memory attributes
        // directly would silently walk stale pre-lock data even though the
        // row itself is correctly locked; a fresh read is what actually
        // observes whatever the lock-holder committed.
        $currentId = $proposedManager->id;
        $visited = [];

        while ($currentId !== null) {
            if ($currentId === $subordinate->id) {
                throw new ReportingHierarchyCycleException($subordinate->id, $proposedManager->id);
            }

            if (in_array($currentId, $visited, true)) {
                break; // defensive: an existing cycle would already have been rejected when it was created
            }

            $visited[] = $currentId;
            $currentId = EmployeeAssignment::query()->find($currentId)?->manager_assignment_id;
        }
    }
}

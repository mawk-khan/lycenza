<?php

namespace App\Domain\HR\Application\Exceptions;

/**
 * Thrown when an address/emergency-contact/qualification/experience/
 * certification/document/note service method (or, since the Phase 8A
 * closure correction, an HTTP controller resolving a nested route --
 * `EmploymentController`/`EmployeeAssignmentController`) is asked to
 * update/remove/promote a child record that does not actually belong
 * to the parent the caller supplied via the route (an Employee, or an
 * EmploymentRecord). School OS rules 19/24: a route/request parameter
 * naming a parent record is never trusted at face value -- ownership is
 * re-verified server-side against the child record's own foreign key,
 * exactly like `SchoolSwitchController` re-verifies a real
 * `SchoolMembership` rather than trusting a client-supplied `school_id`.
 * This is what keeps a caller from reaching one parent's child record
 * through a DIFFERENT parent's nested route (an IDOR-shaped bug) merely
 * by supplying two different, individually-valid ids in the same
 * request. Despite the class name (kept for the many existing call
 * sites already using it for Employee-owned children), `expectedEmployeeId`/
 * `actualEmployeeId` denote whatever parent id is actually being
 * checked at that call site -- an Employee id for every Employee-nested
 * service, or an EmploymentRecord id for `EmployeeAssignmentController`'s
 * Employment -> Assignment ownership check.
 *
 * Phase 8A closure correction (item 3): maps to 404, and the message
 * deliberately does NOT include `$actualEmployeeId` -- now that
 * mutation endpoints exist, this exception is the FIRST thing standing
 * between an attacker probing a real child-record id under the wrong
 * parent and learning which parent actually owns it (a cross-tenant/
 * cross-record existence oracle, the identical concern the *Mismatch
 * exceptions' message redaction addresses for cross-School references).
 * 404 -- not 422/403 -- so a mismatched-ownership record and a
 * genuinely nonexistent one are indistinguishable to the caller,
 * matching `EmployeeProfileController`'s established "no oracle"
 * pattern. `recordId`/`expectedEmployeeId`/`actualEmployeeId` remain
 * available as public properties for tests/internal diagnostics, never
 * rendered into the HTTP-facing message.
 */
class EmployeeOwnershipMismatchException extends HrException
{
    public function __construct(
        public readonly string $recordId,
        public readonly string $expectedEmployeeId,
        public readonly string $actualEmployeeId,
    ) {
        parent::__construct(404, 'HR_NESTED_RECORD_NOT_FOUND', 'The requested record was not found in this context.');
    }
}

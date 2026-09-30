<?php

namespace App\Domain\HR\Application;

use App\Domain\HR\Application\Exceptions\AssignmentCampusMismatchException;
use App\Domain\HR\Application\Exceptions\AssignmentDepartmentCampusScopeMismatchException;
use App\Domain\HR\Application\Exceptions\AssignmentDepartmentMismatchException;
use App\Domain\HR\Application\Exceptions\AssignmentInactiveDepartmentException;
use App\Domain\HR\Application\Exceptions\AssignmentInactivePositionException;
use App\Domain\HR\Application\Exceptions\AssignmentOutsideEmploymentRangeException;
use App\Domain\HR\Application\Exceptions\AssignmentPositionMismatchException;
use App\Domain\HR\Application\Exceptions\EmployeeImportReferenceNotFoundException;
use App\Domain\HR\Application\Exceptions\EmployeeImportUnknownFieldException;
use App\Domain\HR\Application\Exceptions\EmployeeImportValidationException;
use App\Domain\HR\Application\Exceptions\EmploymentOverlapException;
use App\Domain\HR\Application\Exceptions\UnrelatedUserLinkageException;
use App\Domain\HR\Application\Exceptions\UserAlreadyLinkedException;
use App\Domain\HR\Infrastructure\Department;
use App\Domain\HR\Infrastructure\Position;
use App\Models\Campus;
use App\Models\School;
use App\Models\User;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Phase 8A.12 -- the Employee bulk-import ORCHESTRATOR, never a bypass.
 * Every accepted row flows through the exact same authoritative
 * Application services an interactive caller would use
 * (`EmployeeService`, `EmployeePersonalDetailService`,
 * `EmploymentService`, `EmployeeAssignmentService`) -- this class
 * contains NO direct `Employee::insert()`/`DB::table('employees')`/
 * mass-write of any HR table. That is what preserves, for free, every
 * invariant those services already enforce: employee-number
 * allocation, User-linkage validation, Employment overlap, Assignment/
 * primary invariants, Department/Campus compatibility, and
 * `AuditRecorder` emission -- none of it is reimplemented or bypassed
 * here.
 *
 * IMPORT SCOPE (checkpoint brief section 3, the smallest approved
 * schema): Employee core identity, optional User linkage, optional
 * Restricted personal/contact fields (8A.2), optional CURRENT
 * Employment (8A.4), optional CURRENT primary Assignment (8A.4) by
 * Position/Department/Campus CODE. Deliberately excluded:
 * Qualifications/Experience/Certifications (8A.6), EmployeeDocuments
 * (8A.7, and Highly Sensitive data unconditionally -- see
 * `EmployeeImportRow`'s fixed allow-list), reporting-manager
 * assignment (8A.5), historical Employment/Assignment rows, and any
 * caller-forged `record_status`/lifecycle `status`.
 *
 * CREATE-ONLY. An exact or potential duplicate is reported, never
 * merged, overwritten, or silently skipped-with-mutation (checkpoint
 * brief sections 19/24/49) -- no existing Employee's Restricted data is
 * ever touched by this class.
 *
 * NO NEW CAPABILITY. Reuses `hr.employees.manage` (always, since every
 * accepted row calls `EmployeeService::create()`),
 * `hr.employees.personal.manage` (only when the row carries personal
 * data), and `hr.employees.assignments.manage` (only when the row
 * carries Employment/Assignment data) -- exactly the capabilities an
 * equivalent interactive multi-step creation would already require.
 * Authorization for the FULL planned row is checked before any write
 * (checkpoint brief section 42/43): a row an actor is only partially
 * authorized for fails cleanly with no employee created, rather than
 * creating the Employee and silently dropping the unauthorized
 * section.
 */
class EmployeeImportService
{
    public const int MAX_ROWS_PER_BATCH = 1000;

    public function __construct(
        private readonly TenantContext $context,
        private readonly CapabilityResolver $capabilities,
        private readonly EmployeeDuplicateDetector $duplicates,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $rows  Raw associative
     *                                                  rows, in the order they should be processed and reported.
     *                                                  Any caller-supplied `school_id` or ownership field a row
     *                                                  might contain is rejected by `EmployeeImportRow::fromArray()`
     *                                                  as an unknown field -- the AUTHORITATIVE School is always
     *                                                  the trusted `$school` argument, never row content
     *                                                  (checkpoint brief section 61/62).
     */
    public function import(School $school, User $actor, array $rows): EmployeeImportResult
    {
        if (count($rows) > self::MAX_ROWS_PER_BATCH) {
            throw new EmployeeImportValidationException(null, 'Batch exceeds the maximum of '.self::MAX_ROWS_PER_BATCH.' rows.');
        }

        $results = [];
        $created = $exact = $potential = $failed = 0;

        foreach (array_values($rows) as $index => $rawRow) {
            $result = $this->importRow($school, $actor, $index + 1, $rawRow);
            $results[] = $result;

            match ($result->status) {
                'created' => $created++,
                'duplicate_exact' => $exact++,
                'duplicate_potential' => $potential++,
                default => $failed++,
            };
        }

        return new EmployeeImportResult(count($rows), $created, $exact, $potential, $failed, $results);
    }

    /**
     * @param  array<string, mixed>  $rawRow
     */
    private function importRow(School $school, User $actor, int $rowNumber, array $rawRow): EmployeeImportRowResult
    {
        try {
            $row = EmployeeImportRow::fromArray($rawRow);
        } catch (EmployeeImportUnknownFieldException $e) {
            return EmployeeImportRowResult::failed($rowNumber, [['field' => $e->field, 'code' => 'validation', 'message' => "Unsupported field: {$e->field}."]]);
        } catch (EmployeeImportValidationException $e) {
            return EmployeeImportRowResult::failed($rowNumber, [['field' => $e->field, 'code' => 'validation', 'message' => $e->getMessage()]]);
        }

        $missingCapability = $this->firstMissingCapability($school, $actor, $row);
        if ($missingCapability !== null) {
            return EmployeeImportRowResult::failed($rowNumber, [['field' => null, 'code' => 'authorization', 'message' => "Actor lacks the required capability: {$missingCapability}."]]);
        }

        $duplicate = $this->duplicates->detect($school, $row);

        if ($duplicate->status === 'exact') {
            return EmployeeImportRowResult::duplicateExact($rowNumber, $duplicate->matchedEmployeeId, $duplicate->matchedEmployeeNumber);
        }

        if ($duplicate->status === 'potential') {
            return EmployeeImportRowResult::duplicatePotential($rowNumber);
        }

        return $this->context->withSchool($school, function () use ($school, $actor, $rowNumber, $row) {
            try {
                return DB::transaction(function () use ($school, $actor, $rowNumber, $row) {
                    $employee = app(EmployeeService::class)->create($school, [
                        'full_name' => $row->fullName,
                        'user_id' => $row->userId,
                    ], $actor);

                    if ($row->hasPersonalData()) {
                        app(EmployeePersonalDetailService::class)->setDetails($employee, $row->personalAttributes(), $actor);
                    }

                    if ($row->hasEmploymentData()) {
                        $employment = app(EmploymentService::class)->create($employee, $row->employmentAttributes(), $actor);

                        if ($row->hasAssignmentData()) {
                            $position = $this->resolvePosition($school, $row->positionCode);
                            $department = $row->departmentCode !== null ? $this->resolveDepartment($school, $row->departmentCode) : null;
                            $campus = $row->campusCode !== null ? $this->resolveCampus($school, $row->campusCode) : null;

                            $assignment = app(EmployeeAssignmentService::class)->create(
                                $employment,
                                ['starts_on' => $row->assignmentStartsOn()],
                                $position,
                                $actor,
                                campus: $campus,
                                department: $department,
                            );

                            app(EmployeeAssignmentService::class)->setPrimary($assignment, $actor);
                        }
                    }

                    return EmployeeImportRowResult::created($rowNumber, $employee->id, $employee->employee_number);
                });
            } catch (UniqueConstraintViolationException|UserAlreadyLinkedException) {
                // Race: a concurrent import won between our duplicate
                // check and this transaction's insert. Re-detect
                // deterministically rather than leaking the raw SQLSTATE
                // (checkpoint brief section 66). Since TCH.1 the User link
                // goes through EmployeeService's link primitive, which
                // reports the same-User unique violation as
                // UserAlreadyLinkedException.
                $raceResult = $this->duplicates->detect($school, $row);

                return EmployeeImportRowResult::duplicateExact($rowNumber, $raceResult->matchedEmployeeId, $raceResult->matchedEmployeeNumber);
            } catch (Throwable $e) {
                [$field, $code, $message] = $this->describeFailure($e);

                return EmployeeImportRowResult::failed($rowNumber, [['field' => $field, 'code' => $code, 'message' => $message]]);
            }
        });
    }

    private function firstMissingCapability(School $school, User $actor, EmployeeImportRow $row): ?string
    {
        $required = ['hr.employees.manage'];

        if ($row->hasPersonalData()) {
            $required[] = 'hr.employees.personal.manage';
        }

        if ($row->hasEmploymentData()) {
            $required[] = 'hr.employees.assignments.manage';
        }

        foreach ($required as $capability) {
            if (! $this->capabilities->canInSchool($actor, $capability, $school)) {
                return $capability;
            }
        }

        return null;
    }

    private function resolvePosition(School $school, string $code): Position
    {
        $position = Position::query()->where('school_id', $school->id)->where('code', strtoupper(trim($code)))->first();

        if ($position === null) {
            throw new EmployeeImportReferenceNotFoundException('Position', $code);
        }

        return $position;
    }

    private function resolveDepartment(School $school, string $code): Department
    {
        $department = Department::query()->where('school_id', $school->id)->where('code', strtoupper(trim($code)))->first();

        if ($department === null) {
            throw new EmployeeImportReferenceNotFoundException('Department', $code);
        }

        return $department;
    }

    private function resolveCampus(School $school, string $code): Campus
    {
        $campus = Campus::query()->where('school_id', $school->id)->where('code', strtoupper(trim($code)))->first();

        if ($campus === null) {
            throw new EmployeeImportReferenceNotFoundException('Campus', $code);
        }

        return $campus;
    }

    /**
     * Translates a domain exception into a SAFE `{field, code, message}`
     * triple -- never the raw exception class/message, which could
     * carry ids or other internal detail not meant for a user-facing
     * import report (checkpoint brief section 63).
     *
     * @return array{0: ?string, 1: string, 2: string}
     */
    private function describeFailure(Throwable $e): array
    {
        return match (true) {
            $e instanceof EmployeeImportReferenceNotFoundException => ["{$e->referenceType}_code", 'reference_not_found', "No matching {$e->referenceType} found for the supplied code in this School."],
            $e instanceof UnrelatedUserLinkageException => ['user_id', 'validation', 'The specified user does not have an active membership at this School.'],
            $e instanceof EmploymentOverlapException => ['employment_starts_on', 'employment_conflict', 'This Employee already has an overlapping Employment record.'],
            $e instanceof AssignmentInactivePositionException => ['position_code', 'reference_not_found', 'The referenced Position is not active.'],
            $e instanceof AssignmentInactiveDepartmentException => ['department_code', 'reference_not_found', 'The referenced Department is not active.'],
            $e instanceof AssignmentOutsideEmploymentRangeException => ['assignment_starts_on', 'assignment_conflict', 'The Assignment dates fall outside the Employment date range.'],
            $e instanceof AssignmentDepartmentCampusScopeMismatchException => ['department_code', 'assignment_conflict', 'The referenced Department is not compatible with the referenced Campus.'],
            $e instanceof AssignmentCampusMismatchException,
            $e instanceof AssignmentDepartmentMismatchException,
            $e instanceof AssignmentPositionMismatchException => ['position_code', 'reference_not_found', 'A referenced Department/Position/Campus is not valid for this Assignment.'],
            default => [null, 'validation', 'This row could not be processed.'],
        };
    }
}

<?php

namespace App\Domain\HR\Application\Workforce;

use App\Domain\HR\Application\EmployeePlacementLock;
use App\Domain\HR\Application\Exceptions\EmployeeCategoryNotFoundException;
use App\Domain\HR\Application\Exceptions\EmploymentTermsConflictException;
use App\Domain\HR\Application\Exceptions\InvalidEmploymentTermsException;
use App\Domain\HR\Application\HrEmployeeAccess;
use App\Domain\HR\Application\HrSelfAdministrationGuard;
use App\Domain\HR\Infrastructure\EmployeeCategory;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\HR\Infrastructure\EmploymentTerm;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Tenancy\SchoolOperationalGuard;
use App\Support\Tenancy\SchoolTimezone;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * HR.1A (X-3): the ONE writer of a change to an employment's terms --
 * employment type, staff type and School category. A change APPENDS a dated,
 * reasoned term; it never overwrites earlier terms, so "what were the terms
 * on date X" is answered by WorkforceAsOfReader::termsOn() for ever. The
 * database projects the latest term onto `employment_records` and refuses
 * any other write of those fields.
 *
 * Rules (refused before anything is written):
 * - whole-School `hr.employees.assignments.manage` (employment
 *   administration, ADR 0076 §43), never one's own employment (rule 95);
 * - the employment has not ended;
 * - the effective date is on or before the School-local today and strictly
 *   after the latest terms' effective date (history before it is never
 *   rewritten; `trg_employment_terms_append_guard` is the backstop);
 * - at least one field actually changes; the category, when given, is an
 *   active category of the School.
 *
 * Lock order: the Employee placement lock (FOR SHARE), then the employment
 * row (FOR NO KEY UPDATE -- an end() waits, or committed first and is seen).
 */
final class EmploymentTermsService
{
    public function __construct(
        private readonly SchoolOperationalGuard $operational,
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
        private readonly HrSelfAdministrationGuard $selfAdministration,
        private readonly HrEmployeeAccess $access,
        private readonly EmployeePlacementLock $placement,
    ) {}

    public function change(
        EmploymentRecord $employment,
        string $effectiveFrom,
        EmploymentType $employmentType,
        StaffType $staffType,
        ?string $employeeCategoryId,
        EmploymentTermsChangeReason $reason,
        User $actor,
    ): EmploymentTerm {
        $school = $employment->school;
        $this->access->requireWholeSchool($actor, 'hr.employees.assignments.manage', $school);

        $effectiveFrom = CarbonImmutable::parse($effectiveFrom)->toDateString();
        if ($effectiveFrom > SchoolTimezone::today($school)) {
            throw new InvalidEmploymentTermsException('future_dated', 'Employment terms change on today or an earlier date.');
        }

        return $this->context->withSchool($school, fn (): EmploymentTerm => DB::transaction(function () use ($school, $employment, $effectiveFrom, $employmentType, $staffType, $employeeCategoryId, $reason, $actor): EmploymentTerm {
            $this->operational->requireOperational($school->id);
            $this->placement->forPlacementChange($school, $employment->employee_id);
            $this->selfAdministration->refuseOwnEmployee($actor, $employment->employee_id, 'employment.terms');

            $locked = EmploymentRecord::query()->where('school_id', $school->id)->whereKey($employment->id)->lock('for no key update')->firstOrFail();
            if ($locked->ends_on !== null) {
                throw new EmploymentTermsConflictException('employment_ended', 'An ended employment keeps its terms.');
            }

            /** @var EmploymentTerm $latest */
            $latest = EmploymentTerm::query()->where('school_id', $school->id)->where('employment_record_id', $locked->id)
                ->orderByDesc('effective_from')->firstOrFail();
            if ($effectiveFrom <= $latest->effective_from->toDateString()) {
                throw new EmploymentTermsConflictException('not_after_latest', 'A terms change takes effect after the latest recorded terms.');
            }

            if ($employeeCategoryId !== null
                && ! EmployeeCategory::query()->where('school_id', $school->id)->whereKey($employeeCategoryId)->where('status', 'active')->exists()) {
                throw new EmployeeCategoryNotFoundException($employeeCategoryId);
            }

            $changed = array_keys(array_filter([
                'employment_type' => $latest->employment_type !== $employmentType->value,
                'staff_type' => $latest->staff_type !== $staffType->value,
                'employee_category_id' => $latest->employee_category_id !== $employeeCategoryId,
            ]));
            if ($changed === []) {
                throw new InvalidEmploymentTermsException('no_change', 'The new terms are the same as the current terms.');
            }

            $term = EmploymentTerm::query()->create([
                'school_id' => $school->id,
                'employment_record_id' => $locked->id,
                'effective_from' => $effectiveFrom,
                'employment_type' => $employmentType->value,
                'staff_type' => $staffType->value,
                'employee_category_id' => $employeeCategoryId,
                'reason_code' => $reason->value,
                'recorded_by_user_id' => $actor->id,
            ]);

            // Ids, closed codes and changed field names only (never a value of a free field).
            $this->audit->school($school, 'hr.employment_terms.changed', actor: $actor, subject: $locked, metadata: [
                'employeeId' => $locked->employee_id,
                'employmentRecordId' => $locked->id,
                'employmentTermId' => $term->id,
                'effectiveFrom' => $effectiveFrom,
                'reason' => $reason->value,
                'fields' => $changed,
                'employmentType' => $employmentType->value,
                'staffType' => $staffType->value,
                'previousEmploymentType' => $latest->employment_type,
                'previousStaffType' => $latest->staff_type,
            ]);

            return $term;
        }));
    }
}

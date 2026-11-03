<?php

namespace App\Domain\HR\Application;

use App\Domain\HR\Application\Exceptions\InvalidReportingChangeException;
use App\Domain\HR\Application\Reporting\ReportingChangeReason;
use App\Domain\HR\Application\Reporting\ReportingRelationshipService;
use App\Domain\HR\Infrastructure\EmployeeAssignment;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\HR\Infrastructure\ReportingRelationship;
use App\Models\User;
use App\Support\Tenancy\SchoolTimezone;
use App\Support\Tenancy\TenantContext;

/**
 * HR.3A: the assignment-addressed entry to reporting lines, kept for the
 * Profile's manager picker and the API's `.../assignments/{assignment}/manager`
 * route. Since HR.3A a reporting line joins EMPLOYMENTS
 * (`reporting_relationships`), so this adapter only translates: the
 * subordinate assignment names its employment, the chosen manager
 * assignment names the manager's employment, and
 * App\Domain\HR\Application\Reporting\ReportingRelationshipService decides
 * everything (authorization, Campus scope, self-administration, locks,
 * loops, dates, audit). `null` removes the line from the effective date.
 */
class ReportingHierarchyService
{
    public function __construct(
        private readonly ReportingRelationshipService $relationships,
        private readonly TenantContext $context,
    ) {}

    /**
     * @param  string|null  $effectiveOn  default: the School-local today
     * @param  ReportingChangeReason|null  $reason  default: organisational change (HTTP callers always name one)
     */
    public function setManager(EmployeeAssignment $subordinate, ?EmployeeAssignment $manager, User $actor, ?ReportingChangeReason $reason = null, ?string $effectiveOn = null): ReportingRelationship
    {
        $school = $subordinate->school;
        $reason ??= ReportingChangeReason::ORGANISATIONAL_CHANGE;
        $effectiveOn ??= SchoolTimezone::today($school);

        [$subordinateEmployment, $managerEmployment] = $this->context->withSchool($school, function () use ($school, $subordinate, $manager): array {
            if ($manager !== null && $manager->school_id !== $school->id) {
                throw new InvalidReportingChangeException('cross_school', 'That manager could not be found in this School.');
            }

            return [
                EmploymentRecord::query()->where('school_id', $school->id)->whereKey($subordinate->employment_record_id)->firstOrFail(),
                $manager === null ? null : EmploymentRecord::query()->where('school_id', $school->id)->whereKey($manager->employment_record_id)->firstOrFail(),
            ];
        });

        return $managerEmployment === null
            ? $this->relationships->remove($subordinateEmployment, $effectiveOn, $reason, $actor)
            : $this->relationships->assign($subordinateEmployment, $managerEmployment, $effectiveOn, $reason, $actor);
    }
}

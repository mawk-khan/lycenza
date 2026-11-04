<?php

namespace App\Domain\HR\Application;

use App\Domain\HR\Infrastructure\Employee;
use App\Models\School;
use App\Support\Tenancy\SchoolTimezone;
use App\Support\Tenancy\TenantContext;

/**
 * HR.15A (X-1, R-HR12-1; owner decision 2026-11-04: "employment only"): the
 * one HR answer behind every operational staff picker -- a Timetable teacher,
 * a Transport driver, a Visitor host. It lists only Employees who could be
 * given a NEW duty today: an active Employee with a planned or current
 * employment covering the School-local today (EmploymentCoverage, the exact
 * rule the write re-checks under lock with hold()). A separated Employee is
 * never offered; a notice-period Employee is, until finalized (HR.12).
 *
 * It only SUGGESTS and authorizes nothing: each consumer authorizes its own
 * domain capability first, and every save re-validates with
 * EmploymentCoverage::hold(). Directory tier only (id, name, employee
 * number): never contact, personal, pay or HR profile data. Bounded and
 * server-side (a search, LIMIT, stable order); HR depends on no consumer.
 */
final class DutyCandidateReader
{
    public const int MAX_LIMIT = 25;

    public function __construct(private readonly TenantContext $context) {}

    /** @return list<array{id: string, fullName: string, employeeNumber: string}> */
    public function search(School $school, ?string $term, int $limit = 20, bool $matchNumber = false): array
    {
        $limit = max(1, min($limit, self::MAX_LIMIT));
        $term = $term === null ? '' : trim($term);

        return $this->context->withSchool($school, function () use ($school, $term, $limit, $matchNumber): array {
            $query = EmploymentCoverage::constrainToCoveredOn(Employee::query()->where('employees.school_id', $school->id), SchoolTimezone::today($school));

            if ($term !== '') {
                $like = '%'.addcslashes($term, '%_\\').'%';
                $query->where(fn ($w) => $w->where('employees.full_name', 'ilike', $like)
                    ->when($matchNumber, fn ($n) => $n->orWhere('employees.employee_number', 'ilike', $like)));
            }

            return $query->orderBy('employees.full_name')->orderBy('employees.id')->limit($limit)
                ->get(['employees.id', 'employees.full_name', 'employees.employee_number'])
                ->map(fn (Employee $e) => ['id' => $e->id, 'fullName' => $e->full_name, 'employeeNumber' => $e->employee_number])
                ->all();
        });
    }
}

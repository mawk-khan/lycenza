<?php

namespace Tests\Feature\HR;

use App\Domain\HR\Application\EmployeeDirectoryEntry;
use App\Domain\HR\Application\EmployeeDirectoryQuery;
use App\Domain\HR\Application\EmployeeDirectoryService;
use App\Domain\HR\Application\ReportingHierarchyService;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Pagination\LengthAwarePaginator;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 8A.8: proves the Employee Directory read model is a genuine
 * disclosure boundary -- exact allow-listed shape, Restricted/Highly-
 * Sensitive data never leaking through under any circumstance, correct
 * "currently effective" temporal selection for Employment/Assignment,
 * rehire/multiple-assignment non-duplication, one-hop manager
 * projection, tenant-safe search/filter/sort/pagination.
 */
class EmployeeDirectoryServiceTest extends TestCase
{
    use CreatesTenancyFixtures;

    // --- Shape ------------------------------------------------------------

    #[Test]
    public function a_directory_entry_contains_exactly_the_approved_disclosure_fields(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);

        $entry = $this->searchOne($school, $employee->id);

        $this->assertSame([
            'employee_id',
            'employee_number',
            'display_name',
            'position_id',
            'position_name',
            'department_id',
            'department_name',
            'campus_id',
            'campus_name',
            'manager_employee_id',
            'manager_employee_number',
            'manager_display_name',
        ], array_keys($entry->toArray()));
    }

    #[Test]
    public function each_employee_appears_exactly_once(): void
    {
        $school = $this->createSchool();
        $this->createEmployee($school);
        $this->createEmployee($school);
        $this->createEmployee($school);

        $result = $this->search($school);

        $this->assertCount(3, $result->items());
        $this->assertCount(3, collect($result->items())->pluck('employeeId')->unique());
    }

    // --- Disclosure (negative test) ----------------------------------------

    #[Test]
    public function no_restricted_or_highly_sensitive_data_appears_anywhere_in_the_directory_result(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school, ['full_name' => 'Jordan Smith']);

        app(TenantContext::class)->withSchool($school, function () use ($employee) {
            $this->createEmployeePersonalDetail($employee, [
                'personal_email' => 'SENTINEL-EMAIL@example.com',
                'personal_phone' => 'SENTINEL-PHONE-12345',
                'date_of_birth' => '1985-05-05',
                'nationality' => 'SENTINEL-NATIONALITY',
            ]);
            $this->createEmployeeAddress($employee, ['address_line1' => 'SENTINEL-ADDRESS-LINE']);
            $this->createEmployeeEmergencyContact($employee, ['name' => 'SENTINEL-EMERGENCY-CONTACT']);
            $this->createEmployeeQualification($employee, ['institution' => 'SENTINEL-INSTITUTION', 'grade_or_result' => 'SENTINEL-GRADE']);
            $this->createEmployeeExperience($employee, ['organization' => 'SENTINEL-ORGANIZATION']);
            $this->createEmployeeCertification($employee, ['credential_number' => 'SENTINEL-CREDENTIAL-999']);
            $this->createEmployeeDocument($employee, ['original_filename' => 'SENTINEL-FILENAME.pdf', 'storage_path' => 'SENTINEL-STORAGE-PATH']);
        });

        $result = $this->search($school);
        $serialized = json_encode(array_map(fn (EmployeeDirectoryEntry $e) => $e->toArray(), $result->items()));

        foreach ([
            'SENTINEL-EMAIL', 'SENTINEL-PHONE', 'SENTINEL-NATIONALITY', 'SENTINEL-ADDRESS-LINE',
            'SENTINEL-EMERGENCY-CONTACT', 'SENTINEL-INSTITUTION', 'SENTINEL-GRADE', 'SENTINEL-ORGANIZATION',
            'SENTINEL-CREDENTIAL-999', 'SENTINEL-FILENAME', 'SENTINEL-STORAGE-PATH', '1985-05-05',
        ] as $sentinel) {
            $this->assertStringNotContainsString($sentinel, $serialized, "Restricted sentinel value [{$sentinel}] must never appear in the Directory result.");
        }
    }

    // --- Current organization ------------------------------------------

    #[Test]
    public function current_employment_and_primary_assignment_drive_organizational_fields(): void
    {
        $school = $this->createSchool();
        $campus = $this->createCampus($school);
        $department = $this->createDepartment($school, ['campus_id' => null]);
        $position = $this->createPosition($school);
        $employee = $this->createEmployee($school);
        $employment = $this->createEmploymentRecord($employee, ['starts_on' => '2020-01-01', 'ends_on' => null]);
        $this->createEmployeeAssignment($employment, $position, [
            'campus_id' => $campus->id,
            'department_id' => $department->id,
            'is_primary' => true,
            'starts_on' => '2020-01-01',
            'ends_on' => null,
        ]);

        $entry = $this->searchOne($school, $employee->id);

        $this->assertSame($position->id, $entry->positionId);
        $this->assertSame($position->name, $entry->positionName);
        $this->assertSame($department->id, $entry->departmentId);
        $this->assertSame($campus->id, $entry->campusId);
    }

    #[Test]
    public function a_past_employment_record_is_ignored_in_favor_of_none_when_no_current_one_exists(): void
    {
        $school = $this->createSchool();
        $position = $this->createPosition($school);
        $employee = $this->createEmployee($school);
        $pastEmployment = $this->createEmploymentRecord($employee, ['starts_on' => '2015-01-01', 'ends_on' => '2016-01-01']);
        $this->createEmployeeAssignment($pastEmployment, $position, ['is_primary' => true, 'starts_on' => '2015-01-01', 'ends_on' => '2016-01-01']);

        $entry = $this->searchOne($school, $employee->id);

        $this->assertNull($entry->positionId, 'A past, no-longer-effective Employment/Assignment must not drive current organizational fields.');
    }

    #[Test]
    public function a_future_employment_record_is_ignored(): void
    {
        $school = $this->createSchool();
        $position = $this->createPosition($school);
        $employee = $this->createEmployee($school);
        $futureEmployment = $this->createEmploymentRecord($employee, ['starts_on' => '2099-01-01', 'ends_on' => null]);
        $this->createEmployeeAssignment($futureEmployment, $position, ['is_primary' => true, 'starts_on' => '2099-01-01', 'ends_on' => null]);

        $entry = $this->searchOne($school, $employee->id);

        $this->assertNull($entry->positionId, 'A not-yet-effective future Employment must not drive current organizational fields.');
    }

    #[Test]
    public function a_future_open_ended_employment_is_not_mistaken_for_current_merely_because_ends_on_is_null(): void
    {
        $school = $this->createSchool();
        $position = $this->createPosition($school);
        $employee = $this->createEmployee($school);
        $futureOpenEnded = $this->createEmploymentRecord($employee, ['starts_on' => now()->addYear()->toDateString(), 'ends_on' => null]);
        $this->createEmployeeAssignment($futureOpenEnded, $position, ['is_primary' => true, 'starts_on' => now()->addYear()->toDateString(), 'ends_on' => null]);

        $entry = $this->searchOne($school, $employee->id);

        $this->assertNull($entry->positionId, 'ends_on IS NULL alone must never be treated as "current" -- starts_on must also already be effective.');
    }

    #[Test]
    public function a_past_assignment_under_a_current_employment_is_ignored(): void
    {
        $school = $this->createSchool();
        $positionOld = $this->createPosition($school, ['code' => 'OLD']);
        $positionNew = $this->createPosition($school, ['code' => 'NEW']);
        $employee = $this->createEmployee($school);
        $employment = $this->createEmploymentRecord($employee, ['starts_on' => '2018-01-01', 'ends_on' => null]);
        $this->createEmployeeAssignment($employment, $positionOld, ['is_primary' => true, 'starts_on' => '2018-01-01', 'ends_on' => '2020-01-01']);
        $this->createEmployeeAssignment($employment, $positionNew, ['is_primary' => true, 'starts_on' => '2020-01-02', 'ends_on' => null]);

        $entry = $this->searchOne($school, $employee->id);

        $this->assertSame($positionNew->id, $entry->positionId, 'The currently effective primary Assignment must drive the Directory, not a superseded one.');
    }

    // --- Rehire -----------------------------------------------------------

    #[Test]
    public function a_rehired_employee_appears_once_using_the_current_second_employment(): void
    {
        $school = $this->createSchool();
        $positionOld = $this->createPosition($school, ['code' => 'FIRST']);
        $positionNew = $this->createPosition($school, ['code' => 'SECOND']);
        $employee = $this->createEmployee($school);

        $firstEmployment = $this->createEmploymentRecord($employee, ['starts_on' => '2015-01-01', 'ends_on' => '2018-01-01']);
        $this->createEmployeeAssignment($firstEmployment, $positionOld, ['is_primary' => true, 'starts_on' => '2015-01-01', 'ends_on' => '2018-01-01']);

        $secondEmployment = $this->createEmploymentRecord($employee, ['starts_on' => '2020-01-01', 'ends_on' => null]);
        $this->createEmployeeAssignment($secondEmployment, $positionNew, ['is_primary' => true, 'starts_on' => '2020-01-01', 'ends_on' => null]);

        $result = $this->search($school);
        $entries = collect($result->items())->filter(fn (EmployeeDirectoryEntry $e) => $e->employeeId === $employee->id);

        $this->assertCount(1, $entries, 'A rehired Employee must appear exactly once in the Directory, not once per EmploymentRecord.');
        $this->assertSame($positionNew->id, $entries->first()->positionId);
    }

    // --- Multiple assignments -----------------------------------------

    #[Test]
    public function a_secondary_non_primary_assignment_does_not_duplicate_the_employee_row(): void
    {
        $school = $this->createSchool();
        $primaryPosition = $this->createPosition($school, ['code' => 'PRIMARY']);
        $secondaryPosition = $this->createPosition($school, ['code' => 'SECONDARY']);
        $employee = $this->createEmployee($school);
        $employment = $this->createEmploymentRecord($employee, ['starts_on' => '2020-01-01', 'ends_on' => null]);
        $this->createEmployeeAssignment($employment, $primaryPosition, ['is_primary' => true, 'starts_on' => '2020-01-01', 'ends_on' => null]);
        $this->createEmployeeAssignment($employment, $secondaryPosition, ['is_primary' => false, 'starts_on' => '2020-01-01', 'ends_on' => null]);

        $result = $this->search($school);
        $entries = collect($result->items())->filter(fn (EmployeeDirectoryEntry $e) => $e->employeeId === $employee->id);

        $this->assertCount(1, $entries);
        $this->assertSame($primaryPosition->id, $entries->first()->positionId, 'The primary Assignment must drive organizational fields, never a secondary one.');
    }

    // --- Manager ------------------------------------------------------

    #[Test]
    public function the_current_managers_directory_safe_identity_is_derived_one_hop_from_the_live_pointer(): void
    {
        $school = $this->createSchool();
        $position = $this->createPosition($school);

        $managerEmployee = $this->createEmployee($school, ['full_name' => 'Manager Person']);
        $managerEmployment = $this->createEmploymentRecord($managerEmployee, ['starts_on' => '2019-01-01', 'ends_on' => null]);
        $managerAssignment = $this->createEmployeeAssignment($managerEmployment, $position, ['is_primary' => true, 'starts_on' => '2019-01-01', 'ends_on' => null]);

        $subordinateEmployee = $this->createEmployee($school, ['full_name' => 'Subordinate Person']);
        $subordinateEmployment = $this->createEmploymentRecord($subordinateEmployee, ['starts_on' => '2020-01-01', 'ends_on' => null]);
        $subordinateAssignment = $this->createEmployeeAssignment($subordinateEmployment, $position, ['is_primary' => true, 'starts_on' => '2020-01-01', 'ends_on' => null]);

        $actor = $this->fullHrActor($school);
        app(TenantContext::class)->withSchool($school, function () use ($subordinateAssignment, $managerAssignment, $actor) {
            app(ReportingHierarchyService::class)->setManager($subordinateAssignment->fresh(), $managerAssignment->fresh(), $actor);
        });

        $entry = $this->searchOne($school, $subordinateEmployee->id);

        $this->assertSame($managerEmployee->id, $entry->managerEmployeeId);
        $this->assertSame($managerEmployee->employee_number, $entry->managerEmployeeNumber);
        $this->assertSame('Manager Person', $entry->managerDisplayName);
    }

    #[Test]
    public function no_manager_means_null_manager_fields(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);

        $entry = $this->searchOne($school, $employee->id);

        $this->assertNull($entry->managerEmployeeId);
        $this->assertNull($entry->managerEmployeeNumber);
        $this->assertNull($entry->managerDisplayName);
    }

    // --- Search ---------------------------------------------------------

    #[Test]
    public function search_matches_by_employee_number_prefix(): void
    {
        $school = $this->createSchool();
        $target = $this->createEmployee($school, ['employee_number' => 'EMP-000042']);
        $this->createEmployee($school, ['employee_number' => 'EMP-000099']);

        $result = $this->search($school, new EmployeeDirectoryQuery(search: 'EMP-000042'));

        $this->assertCount(1, $result->items());
        $this->assertSame($target->id, $result->items()[0]->employeeId);
    }

    #[Test]
    public function search_matches_by_full_name_substring(): void
    {
        $school = $this->createSchool();
        $target = $this->createEmployee($school, ['full_name' => 'Alexandra Fontaine']);
        $this->createEmployee($school, ['full_name' => 'Someone Else']);

        $result = $this->search($school, new EmployeeDirectoryQuery(search: 'fontaine'));

        $this->assertCount(1, $result->items());
        $this->assertSame($target->id, $result->items()[0]->employeeId);
    }

    #[Test]
    public function search_does_not_match_restricted_personal_email(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school, ['full_name' => 'Someone']);
        app(TenantContext::class)->withSchool($school, function () use ($employee) {
            $this->createEmployeePersonalDetail($employee, ['personal_email' => 'findme@example.com']);
        });

        $result = $this->search($school, new EmployeeDirectoryQuery(search: 'findme@example.com'));

        $this->assertCount(0, $result->items(), 'Directory search must never reach into Restricted personal-contact data.');
    }

    #[Test]
    public function search_wildcard_characters_are_treated_literally_not_as_like_wildcards(): void
    {
        $school = $this->createSchool();
        $this->createEmployee($school, ['full_name' => 'Anyone At All']);
        $this->createEmployee($school, ['full_name' => 'Someone Else']);

        $result = $this->search($school, new EmployeeDirectoryQuery(search: '%'));

        $this->assertCount(0, $result->items(), 'A literal % in search input must not act as a SQL LIKE wildcard matching every row.');
    }

    // --- Filters ----------------------------------------------------------

    #[Test]
    public function filtering_by_department_returns_only_employees_currently_in_that_department(): void
    {
        $school = $this->createSchool();
        $position = $this->createPosition($school);
        $departmentA = $this->createDepartment($school, ['code' => 'DEPTA']);
        $departmentB = $this->createDepartment($school, ['code' => 'DEPTB']);

        $employeeA = $this->createEmployee($school);
        $employmentA = $this->createEmploymentRecord($employeeA, ['starts_on' => '2020-01-01', 'ends_on' => null]);
        $this->createEmployeeAssignment($employmentA, $position, ['department_id' => $departmentA->id, 'is_primary' => true, 'starts_on' => '2020-01-01', 'ends_on' => null]);

        $employeeB = $this->createEmployee($school);
        $employmentB = $this->createEmploymentRecord($employeeB, ['starts_on' => '2020-01-01', 'ends_on' => null]);
        $this->createEmployeeAssignment($employmentB, $position, ['department_id' => $departmentB->id, 'is_primary' => true, 'starts_on' => '2020-01-01', 'ends_on' => null]);

        $result = $this->search($school, new EmployeeDirectoryQuery(departmentId: $departmentA->id));

        $this->assertCount(1, $result->items());
        $this->assertSame($employeeA->id, $result->items()[0]->employeeId);
    }

    #[Test]
    public function filtering_by_another_schools_department_id_returns_no_results_not_an_error(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $this->createEmployee($schoolA);
        $departmentB = $this->createDepartment($schoolB);

        $result = $this->search($schoolA, new EmployeeDirectoryQuery(departmentId: $departmentB->id));

        $this->assertCount(0, $result->items(), 'A filter UUID belonging to another School must simply match nothing, never error or leak existence.');
    }

    // --- Sort -------------------------------------------------------------

    #[Test]
    public function an_invalid_sort_value_falls_back_to_the_deterministic_default(): void
    {
        $school = $this->createSchool();
        $this->createEmployee($school, ['full_name' => 'Zed']);
        $this->createEmployee($school, ['full_name' => 'Anna']);

        $query = new EmployeeDirectoryQuery(sort: 'personal_email');

        $this->assertSame(EmployeeDirectoryQuery::DEFAULT_SORT, $query->sort, 'An invalid/unapproved sort column must be silently defaulted, never passed through.');

        $result = $this->search($school, $query);

        $this->assertSame('Anna', $result->items()[0]->displayName);
    }

    #[Test]
    public function sorting_by_full_name_is_deterministic(): void
    {
        $school = $this->createSchool();
        $this->createEmployee($school, ['full_name' => 'Charlie']);
        $this->createEmployee($school, ['full_name' => 'Alice']);
        $this->createEmployee($school, ['full_name' => 'Bob']);

        $result = $this->search($school, new EmployeeDirectoryQuery(sort: 'full_name', direction: 'asc'));

        $this->assertSame(['Alice', 'Bob', 'Charlie'], collect($result->items())->pluck('displayName')->all());
    }

    // --- Pagination ---------------------------------------------------

    #[Test]
    public function pagination_is_deterministic_and_bounded(): void
    {
        $school = $this->createSchool();
        for ($i = 0; $i < 5; $i++) {
            $this->createEmployee($school, ['employee_number' => sprintf('EMP-%06d', $i + 1)]);
        }

        $result = $this->search($school, new EmployeeDirectoryQuery(perPage: 2, page: 2));

        $this->assertCount(2, $result->items());
        $this->assertSame(5, $result->total());
        $this->assertSame(3, $result->lastPage());
    }

    #[Test]
    public function a_requested_per_page_above_the_maximum_is_clamped(): void
    {
        $query = new EmployeeDirectoryQuery(perPage: 999999);

        $this->assertSame(EmployeeDirectoryService::MAX_PER_PAGE, $query->perPage);
    }

    // --- Tenant isolation -----------------------------------------------

    #[Test]
    public function school_a_cannot_discover_school_bs_employees_via_default_search(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $this->createEmployee($schoolA);
        $this->createEmployee($schoolB);
        $this->createEmployee($schoolB);

        $result = $this->search($schoolA);

        $this->assertCount(1, $result->items());
    }

    #[Test]
    public function identical_employee_numbers_in_two_schools_never_collide(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $employeeA = $this->createEmployee($schoolA, ['employee_number' => 'EMP-000001']);
        $employeeB = $this->createEmployee($schoolB, ['employee_number' => 'EMP-000001']);

        $resultA = $this->search($schoolA, new EmployeeDirectoryQuery(search: 'EMP-000001'));
        $resultB = $this->search($schoolB, new EmployeeDirectoryQuery(search: 'EMP-000001'));

        $this->assertCount(1, $resultA->items());
        $this->assertSame($employeeA->id, $resultA->items()[0]->employeeId);
        $this->assertCount(1, $resultB->items());
        $this->assertSame($employeeB->id, $resultB->items()[0]->employeeId);
    }

    #[Test]
    public function search_scopes_correctly_even_when_ambient_tenant_context_is_a_different_school(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $employeeA = $this->createEmployee($schoolA);
        $this->createEmployee($schoolB);

        app(TenantContext::class)->set($schoolB);

        $result = app(EmployeeDirectoryService::class)->search($schoolA, new EmployeeDirectoryQuery, $this->fullHrActor($schoolA));

        $this->assertCount(1, $result->items());
        $this->assertSame($employeeA->id, $result->items()[0]->employeeId);
    }

    #[Test]
    public function archived_employees_are_excluded_by_default_and_included_only_when_explicitly_requested(): void
    {
        $school = $this->createSchool();
        $this->createEmployee($school, ['record_status' => 'active']);
        $this->createEmployee($school, ['record_status' => 'archived']);

        $default = $this->search($school);
        $this->assertCount(1, $default->items());

        $includingArchived = $this->search($school, new EmployeeDirectoryQuery(includeArchived: true));
        $this->assertCount(2, $includingArchived->items());
    }

    // --- Helpers ----------------------------------------------------------

    private function search(School $school, ?EmployeeDirectoryQuery $query = null): LengthAwarePaginator
    {
        return app(EmployeeDirectoryService::class)->search($school, $query ?? new EmployeeDirectoryQuery, $this->fullHrActor($school));
    }

    private function searchOne(School $school, string $employeeId): EmployeeDirectoryEntry
    {
        $result = $this->search($school);
        $entry = collect($result->items())->firstWhere('employeeId', $employeeId);

        $this->assertNotNull($entry, "Expected employee {$employeeId} to appear in the Directory result.");

        return $entry;
    }
}

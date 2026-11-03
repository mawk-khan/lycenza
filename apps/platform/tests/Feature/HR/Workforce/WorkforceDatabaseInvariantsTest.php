<?php

namespace Tests\Feature\HR\Workforce;

use App\Domain\HR\Infrastructure\EmployeeAssignment;
use App\Domain\HR\Infrastructure\EmployeePlacementChange;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\HR\Infrastructure\EmploymentTerm;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * HR.1A: the database backstops of the workforce model, proven with RAW
 * writes as the runtime role (no service in the way). Ranges are INCLUSIVE:
 * ..2026-06-30 and 2026-07-01.. are adjacent; ..2026-06-30 and 2026-06-30..
 * overlap on 2026-06-30.
 */
class WorkforceDatabaseInvariantsTest extends TestCase
{
    use CreatesTenancyFixtures;

    private School $school;

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = $this->createSchool();
    }

    /** Runs $write in a savepoint and returns the SQLSTATE it raised (or null). */
    private function sqlstate(callable $write): ?string
    {
        try {
            DB::transaction(fn () => app(TenantContext::class)->withSchool($this->school, $write));

            return null;
        } catch (QueryException $e) {
            return (string) $e->getCode();
        }
    }

    private function inSchool(callable $fn): mixed
    {
        return app(TenantContext::class)->withSchool($this->school, $fn);
    }

    #[Test]
    public function inclusive_employment_ranges_adjacent_is_valid_and_a_shared_day_is_refused(): void
    {
        $employee = $this->createEmployee($this->school);
        $this->createEmploymentRecord($employee, ['starts_on' => '2026-01-01', 'ends_on' => '2026-06-30', 'status' => 'separated']);

        $this->assertNull($this->sqlstate(fn () => $this->createEmploymentRecord($employee, ['starts_on' => '2026-07-01', 'ends_on' => null])), 'adjacent');

        $other = $this->createEmployee($this->school);
        $this->createEmploymentRecord($other, ['starts_on' => '2026-01-01', 'ends_on' => '2026-06-30', 'status' => 'separated']);
        $this->assertSame('23P01', $this->sqlstate(fn () => $this->createEmploymentRecord($other, ['starts_on' => '2026-06-30', 'ends_on' => null])), 'one shared day');
    }

    #[Test]
    public function an_open_employment_refuses_a_concurrent_second_one(): void
    {
        $employee = $this->createEmployee($this->school);
        $this->createEmploymentRecord($employee, ['starts_on' => '2026-04-01', 'ends_on' => null]);

        $this->assertSame('23P01', $this->sqlstate(fn () => $this->createEmploymentRecord($employee, ['starts_on' => '2026-09-01', 'ends_on' => null])));
        $this->assertSame(1, $this->inSchool(fn () => EmploymentRecord::query()->where('employee_id', $employee->id)->count()));
    }

    #[Test]
    public function primary_assignments_never_share_a_day_while_secondaries_may(): void
    {
        $employment = $this->createEmploymentRecord($this->createEmployee($this->school), ['starts_on' => '2026-01-01', 'ends_on' => null]);
        $position = $this->createPosition($this->school);
        $this->createEmployeeAssignment($employment, $position, ['is_primary' => true, 'starts_on' => '2026-04-01', 'ends_on' => '2026-10-31']);

        // Transfer shape: the next primary starts the day after.
        $this->assertNull($this->sqlstate(fn () => $this->createEmployeeAssignment($employment, $position, ['is_primary' => true, 'starts_on' => '2026-11-01', 'ends_on' => null])));
        // Overlapping primary: refused (open-ended over the transfer).
        $this->assertSame('23P01', $this->sqlstate(fn () => $this->createEmployeeAssignment($employment, $position, ['is_primary' => true, 'starts_on' => '2026-10-15', 'ends_on' => null])));
        // A secondary may overlap, but cannot then become primary.
        $secondary = $this->createEmployeeAssignment($employment, $position, ['is_primary' => false, 'starts_on' => '2026-05-01', 'ends_on' => null]);
        $this->assertSame('23P01', $this->sqlstate(fn () => EmployeeAssignment::query()->whereKey($secondary->id)->update(['is_primary' => true])));
    }

    #[Test]
    public function a_primary_of_another_employment_never_conflicts(): void
    {
        $position = $this->createPosition($this->school);
        $a = $this->createEmploymentRecord($this->createEmployee($this->school), ['starts_on' => '2026-01-01']);
        $b = $this->createEmploymentRecord($this->createEmployee($this->school), ['starts_on' => '2026-01-01']);
        $this->createEmployeeAssignment($a, $position, ['is_primary' => true, 'starts_on' => '2026-01-01']);

        $this->assertNull($this->sqlstate(fn () => $this->createEmployeeAssignment($b, $position, ['is_primary' => true, 'starts_on' => '2026-01-01'])));
    }

    #[Test]
    public function every_employment_gets_its_first_terms_and_the_projection_is_never_written_directly(): void
    {
        $category = $this->createEmployeeCategory($this->school);
        $employment = $this->createEmploymentRecord($this->createEmployee($this->school), [
            'starts_on' => '2026-02-01', 'employment_type' => 'contract', 'staff_type' => 'outsourced', 'employee_category_id' => $category->id,
        ]);

        $terms = $this->inSchool(fn () => EmploymentTerm::query()->where('employment_record_id', $employment->id)->get());
        $this->assertCount(1, $terms);
        $this->assertSame(['2026-02-01', 'contract', 'outsourced', $category->id, 'initial'], [
            $terms[0]->effective_from->toDateString(), $terms[0]->employment_type, $terms[0]->staff_type, $terms[0]->employee_category_id, $terms[0]->reason_code,
        ]);

        // X-3: no destructive overwrite of the terms projection.
        foreach (['employment_type' => 'permanent', 'staff_type' => 'teaching', 'employee_category_id' => null] as $field => $value) {
            $this->assertSame('23514', $this->sqlstate(fn () => EmploymentRecord::query()->whereKey($employment->id)->update([$field => $value])), $field);
        }
        // The closed catalogues.
        $this->assertSame('23514', $this->sqlstate(fn () => $this->createEmploymentRecord($this->createEmployee($this->school), ['employment_type' => 'full_time'])));
        $this->assertSame('23514', $this->sqlstate(fn () => $this->createEmploymentRecord($this->createEmployee($this->school), ['staff_type' => 'visiting'])));
    }

    #[Test]
    public function terms_only_append_after_the_latest_and_the_projection_follows(): void
    {
        $employment = $this->createEmploymentRecord($this->createEmployee($this->school), ['starts_on' => '2026-02-01', 'employment_type' => 'probationary', 'staff_type' => 'teaching']);
        $actor = $this->createUser();
        $term = fn (string $from, string $reason = 'reclassification') => EmploymentTerm::query()->create([
            'school_id' => $this->school->id, 'employment_record_id' => $employment->id, 'effective_from' => $from,
            'employment_type' => 'permanent', 'staff_type' => 'teaching', 'reason_code' => $reason, 'recorded_by_user_id' => $actor->id,
        ]);

        $this->assertSame('23514', $this->sqlstate(fn () => $term('2026-02-01')), 'not after the latest');
        $this->assertSame('23514', $this->sqlstate(fn () => $term('2026-05-01', 'initial')), 'initial only first');
        $this->assertNull($this->sqlstate(fn () => $term('2026-05-01')));
        $this->assertSame('23514', $this->sqlstate(fn () => $term('2026-04-01')), 'never inserted before the latest');

        $this->assertSame('permanent', $this->inSchool(fn () => EmploymentRecord::query()->whereKey($employment->id)->value('employment_type')));
        $this->assertSame('23514', $this->sqlstate(fn () => EmploymentTerm::query()->create([
            'school_id' => $this->school->id, 'employment_record_id' => $employment->id, 'effective_from' => '2026-06-01',
            'employment_type' => 'permanent', 'staff_type' => null, 'reason_code' => 'correction', 'recorded_by_user_id' => $actor->id,
        ])), 'a recorded change always classifies the staff type');
    }

    #[Test]
    public function history_rows_cannot_be_rewritten_or_deleted_by_the_runtime_role(): void
    {
        $employment = $this->createEmploymentRecord($this->createEmployee($this->school), ['starts_on' => '2026-02-01']);

        $this->assertSame('42501', $this->sqlstate(fn () => EmploymentTerm::query()->where('employment_record_id', $employment->id)->update(['employment_type' => 'contract'])));
        $this->assertSame('42501', $this->sqlstate(fn () => EmploymentTerm::query()->where('employment_record_id', $employment->id)->delete()));
        $this->assertSame('42501', $this->sqlstate(fn () => EmployeePlacementChange::query()->where('school_id', $this->school->id)->delete()));
        $this->assertSame('42501', $this->sqlstate(fn () => EmployeePlacementChange::query()->where('school_id', $this->school->id)->update(['reason_code' => 'restructuring'])));
    }

    #[Test]
    public function a_placement_change_is_bound_to_its_own_employment_and_dates(): void
    {
        $position = $this->createPosition($this->school);
        $actor = $this->createUser();
        $employment = $this->createEmploymentRecord($this->createEmployee($this->school), ['starts_on' => '2026-01-01']);
        $from = $this->createEmployeeAssignment($employment, $position, ['is_primary' => true, 'starts_on' => '2026-01-01', 'ends_on' => '2026-10-31']);
        $to = $this->createEmployeeAssignment($employment, $position, ['is_primary' => true, 'starts_on' => '2026-11-01']);
        $foreign = $this->createEmployeeAssignment($this->createEmploymentRecord($this->createEmployee($this->school), ['starts_on' => '2026-01-01']), $position, ['starts_on' => '2026-11-01']);
        $change = fn (array $over) => EmployeePlacementChange::query()->create([
            'school_id' => $this->school->id, 'employment_record_id' => $employment->id, 'change_kind' => 'transfer',
            'from_assignment_id' => $from->id, 'to_assignment_id' => $to->id, 'effective_on' => '2026-11-01',
            'reason_code' => 'staffing_requirement', 'recorded_by_user_id' => $actor->id, ...$over,
        ]);

        $this->assertSame('23514', $this->sqlstate(fn () => $change(['to_assignment_id' => $foreign->id])), "another employment's assignment");
        $this->assertSame('23514', $this->sqlstate(fn () => $change(['effective_on' => '2026-10-15'])), 'the new placement starts on the effective date');
        $this->assertSame('23514', $this->sqlstate(fn () => $change(['change_kind' => 'initial_placement'])), 'initial has no source');
        $this->assertSame('23514', $this->sqlstate(fn () => $change(['reason_code' => 'performance'])), 'closed reasons');
        $this->assertNull($this->sqlstate(fn () => $change([])));
    }

    #[Test]
    public function an_employee_number_and_school_are_immutable_in_the_database(): void
    {
        $employee = $this->createEmployee($this->school);

        $this->assertSame('23514', $this->sqlstate(fn () => DB::table('employees')->where('id', $employee->id)->update(['employee_number' => 'EMP-999999'])));
    }

    #[Test]
    public function a_department_cycle_is_refused_by_the_database(): void
    {
        $a = $this->createDepartment($this->school);
        $b = $this->createDepartment($this->school);
        $c = $this->createDepartment($this->school);
        $this->inSchool(fn () => DB::table('hr_departments')->where('id', $b->id)->update(['parent_department_id' => $a->id]));
        $this->inSchool(fn () => DB::table('hr_departments')->where('id', $c->id)->update(['parent_department_id' => $b->id]));

        $this->assertSame('23514', $this->sqlstate(fn () => DB::table('hr_departments')->where('id', $a->id)->update(['parent_department_id' => $c->id])), 'A -> B -> C -> A');
    }

    #[Test]
    public function the_history_tables_are_tenant_isolated(): void
    {
        $employment = $this->createEmploymentRecord($this->createEmployee($this->school), ['starts_on' => '2026-02-01']);
        $other = $this->createSchool();

        $this->assertSame(0, app(TenantContext::class)->withSchool($other, fn () => (int) DB::selectOne('select count(*) as c from employment_terms')->c));
        $this->assertSame(1, $this->inSchool(fn () => (int) DB::selectOne('select count(*) as c from employment_terms')->c));
        // A term cannot be written for another School's employment.
        $this->assertSame('23503', (function () use ($other, $employment): ?string {
            try {
                DB::transaction(fn () => app(TenantContext::class)->withSchool($other, fn () => DB::table('employment_terms')->insert([
                    'id' => (string) Str::uuid(), 'school_id' => $other->id, 'employment_record_id' => $employment->id, 'effective_from' => '2026-03-01',
                    'employment_type' => 'permanent', 'staff_type' => 'teaching', 'reason_code' => 'correction', 'recorded_by_user_id' => $this->createUser()->id,
                ])));

                return null;
            } catch (QueryException $e) {
                return (string) $e->getCode();
            }
        })());
    }
}

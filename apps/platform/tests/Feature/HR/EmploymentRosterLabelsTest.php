<?php

namespace Tests\Feature\HR;

use App\Domain\HR\Application\EmploymentRoster;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * HR.R1-C10 (LVA-8/TRR-4, rule 141): every id asked for gets its label. `rows()`
 * caps one query at 2,000, so a Payroll run above that lost its names.
 */
class EmploymentRosterLabelsTest extends TestCase
{
    use CreatesTenancyFixtures;

    #[Test]
    public function a_school_above_the_query_cap_gets_every_label_in_order(): void
    {
        $school = $this->createSchool();
        $employees = [];
        $employments = [];
        foreach (range(1, 2100) as $i) {
            $employee = (string) Str::uuid7();
            $employees[] = ['id' => $employee, 'school_id' => $school->id, 'employee_number' => 'L'.$i, 'full_name' => sprintf('Staff %05d', 2101 - $i), 'record_status' => 'active', 'created_at' => now(), 'updated_at' => now()];
            $employments[] = ['id' => (string) Str::uuid7(), 'school_id' => $school->id, 'employee_id' => $employee, 'employment_type' => 'permanent', 'staff_type' => 'teaching', 'starts_on' => '2026-01-01', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()];
        }
        $ids = array_column($employments, 'id');
        $this->inSchoolContext($school, function () use ($employees, $employments): void {
            foreach ([['employees', $employees], ['employment_records', $employments]] as [$table, $rows]) {
                foreach (array_chunk($rows, 500) as $chunk) {
                    DB::table($table)->insert($chunk);
                }
            }
        });

        $labels = app(EmploymentRoster::class)->labels($school, [...$ids, $ids[0]]);

        $this->assertCount(2100, $labels, 'none lost above the cap, and a repeated id answers once');
        $this->assertSame('Staff 00001', $labels[0]['fullName']);
        $this->assertSame('Staff 02100', $labels[2099]['fullName'], 'one sorted list, never sorted chunks');
        $this->assertSame([], app(EmploymentRoster::class)->labels($school, []));
    }

    private function inSchoolContext(School $school, callable $work): void
    {
        app(TenantContext::class)->withSchool($school, $work);
    }
}

<?php

namespace Tests\Feature\HR;

use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\HR\Infrastructure\Position;
use App\Models\Campus;
use App\Models\School;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * ORG.7E-1 (ADR 0076 §43, §55): the Campus-scoped HR reads are bounded SQL --
 * the Campus restriction is one EXISTS predicate and the in-scope placement
 * one batched query, so the query count of a page never grows with its rows
 * (no per-Employee current-assignment query, no per-row authorization).
 * Counts are compared across row growth, never pinned absolutely.
 */
class HrCampusPerformanceTest extends TestCase
{
    use CreatesTenancyFixtures;

    private School $school;

    private Campus $a;

    private Campus $b;

    private Position $position;

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = $this->createSchool();
        $this->a = $this->createCampus($this->school);
        $this->b = $this->createCampus($this->school);
        $this->position = $this->createPosition($this->school, ['status' => 'active']);
    }

    private function seedStaff(int $count): EmploymentRecord
    {
        $last = null;
        for ($i = 0; $i < $count; $i++) {
            $employee = $this->createEmployee($this->school);
            $last = $this->createEmploymentRecord($employee, ['starts_on' => '2025-01-01', 'ends_on' => null, 'status' => 'active']);
            $manager = $this->createEmployeeAssignment($last, $this->position, ['campus_id' => $this->b->id, 'is_primary' => true, 'starts_on' => '2025-01-01', 'ends_on' => null]);
            $this->createEmployeeAssignment($last, $this->position, ['campus_id' => $this->a->id, 'starts_on' => '2025-01-01', 'ends_on' => null, 'manager_assignment_id' => $manager->id]);
        }

        return $last;
    }

    private function queries(callable $request): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $request();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    private function hrA(): User
    {
        $user = $this->createUser();
        $this->assignSchoolRole($this->createMembership($user, $this->school), 'hr_officer', $this->a->id);

        return $user;
    }

    #[Test]
    public function the_campus_directory_query_count_does_not_grow_with_its_rows(): void
    {
        $user = $this->hrA();
        $token = $user->createToken('test-device')->plainTextToken;
        $get = fn () => $this->withHeader('Authorization', 'Bearer '.$token)->getJson("/api/v1/schools/{$this->school->id}/employees?per_page=100")->assertOk();

        $this->seedStaff(2);
        $small = $this->queries($get);
        $this->seedStaff(20);
        $large = $this->queries($get);

        $this->assertLessThanOrEqual(2, $large - $small, "Campus directory queries grew by {$small}->{$large} with 20 more Employees: a per-row query.");
    }

    #[Test]
    public function the_overview_query_count_does_not_grow_with_assignments(): void
    {
        $user = $this->hrA();
        $record = $this->seedStaff(1);
        $get = fn () => $this->actingAs($user)->get("/app/hr/employees/{$record->employee_id}/overview");
        $this->actingAs($user)->post("/app/schools/{$this->school->id}/activate");

        $small = $this->queries($get);
        foreach (range(1, 8) as $i) {
            $this->createEmployeeAssignment($record, $this->position, ['campus_id' => $this->a->id, 'starts_on' => '2025-01-0'.min($i + 1, 9), 'ends_on' => null]);
        }
        $large = $this->queries($get);

        $this->assertLessThanOrEqual(2, $large - $small, "overview queries grew by {$small}->{$large} with 8 more assignments: a per-assignment query.");
    }
}

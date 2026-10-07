<?php

namespace Tests\Feature\Postgres;

use App\Domain\TeachingAssignments\Infrastructure\ElectiveTeachingAssignment;
use App\Support\Tenancy\TenantRls;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\AssertsTenantRlsIsolation;
use Tests\Concerns\CreatesTeachingAssignmentFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * TCH-E (ADR 0063 section 45; CLAUDE.md rule 28) at the raw PostgreSQL layer,
 * as the runtime role: forced RLS on `elective_teaching_assignments`; no
 * runtime DELETE; the database refuses a required Offering, a cross-School
 * reference, a bad interval and any change but one shortening end.
 */
class ElectiveTeachingAssignmentsRlsIsolationTest extends TestCase
{
    use AssertsTenantRlsIsolation, CreatesTeachingAssignmentFixtures, CreatesTenancyFixtures;

    private function setSchool(string $schoolId): void
    {
        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolId]);
    }

    private function refused(callable $op, string $fragment): void
    {
        try {
            DB::connection('pgsql')->transaction($op);
            $this->fail("expected a refusal containing '{$fragment}'");
        } catch (QueryException $e) {
            $this->assertStringContainsString($fragment, $e->getMessage());
        }
    }

    #[Test]
    public function the_table_is_tenant_isolated_at_the_raw_sql_layer(): void
    {
        $w = $this->teachingWorld();
        $this->assignElective($w, $this->electiveOffering($w));
        $other = $this->createSchool();

        $this->assertTenantRlsIsolation(['elective_teaching_assignments' => ElectiveTeachingAssignment::class], $w['school']->id, $other->id);
    }

    #[Test]
    public function the_database_refuses_required_offerings_cross_school_pins_and_any_rewrite(): void
    {
        $w = $this->teachingWorld();
        $elective = $this->electiveOffering($w);
        $a = $this->assignElective($w, $elective, '2026-06-01');
        $other = $this->teachingWorld();
        $otherEmployee = $other['employee'];
        $anotherElective = $this->electiveOffering($w);
        $this->setSchool($w['school']->id); // after every service call: withSchool() leaves the GUC empty

        $row = collect((array) DB::table('elective_teaching_assignments')->where('id', $a->id)->first())->except(['retention_recorded_at', 'id'])->all();
        $insert = fn (array $overrides) => fn () => DB::table('elective_teaching_assignments')->insert([...$row, 'id' => (string) Str::uuid7(), 'starts_on' => '2027-01-01', 'ends_on' => null, ...$overrides]);

        $this->refused($insert(['subject_offering_id' => $w['offering']->id]), 'not an elective');
        $this->refused($insert(['employee_id' => $otherEmployee->id]), 'elective_teaching_assignments_employee_fk');
        $this->refused($insert(['grade_level_id' => $other['grade']->id]), 'elective_teaching_assignments_subject_offering_fk');
        $this->refused($insert(['starts_on' => '2026-07-01', 'ends_on' => '2026-06-30']), 'elective_teaching_assignments_date_range_check');
        $this->refused($insert(['ended_at' => now(), 'ended_by_user_id' => $w['admin']->id, 'end_reason' => 'completed', 'ends_on' => '2027-01-31']), 'cannot be created already ended');

        $this->refused(fn () => DB::table('elective_teaching_assignments')->where('id', $a->id)->update(['starts_on' => '2026-05-01']), 'the only permitted change is ending');
        $this->refused(fn () => DB::table('elective_teaching_assignments')->where('id', $a->id)->update(['subject_offering_id' => $anotherElective->id]), 'the only permitted change is ending');
        $this->refused(fn () => DB::table('elective_teaching_assignments')->where('id', $a->id)->delete(), 'permission denied');

        // One end, then immutable.
        DB::connection('pgsql')->transaction(fn () => DB::table('elective_teaching_assignments')->where('id', $a->id)
            ->update(['ends_on' => '2026-09-30', 'ended_at' => now(), 'ended_by_user_id' => $w['admin']->id, 'end_reason' => 'completed']));
        $this->refused(fn () => DB::table('elective_teaching_assignments')->where('id', $a->id)->update(['ends_on' => '2026-08-31']), 'an ended assignment is immutable');
    }
}

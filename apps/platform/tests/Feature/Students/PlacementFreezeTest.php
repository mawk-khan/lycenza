<?php

namespace Tests\Feature\Students;

use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * ORG.7F-3 (ADR 0076 §47, owner decisions F1-D6, F3-D1): a placement's year,
 * Campus, Grade and Section are fixed once the row exists -- refused by the
 * database (student_enrollments_freeze_placement) for the runtime role, raw
 * SQL included. The named lifecycle still ends a placement (status,
 * ends_on); transfer is "end + insert", never an UPDATE.
 */
class PlacementFreezeTest extends TestCase
{
    use CreatesTenancyFixtures;

    private School $school;

    private StudentEnrollment $placement;

    /** @var array<string, string> */
    private array $other = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = $this->createSchool();
        $a = $this->createCampus($this->school);
        $b = $this->createCampus($this->school);
        $grade = $this->createGradeLevel($this->school, ['code' => 'G5', 'sequence' => 5]);
        $grade6 = $this->createGradeLevel($this->school, ['code' => 'G6', 'sequence' => 6]);
        $year = $this->createAcademicYear($this->school, ['code' => 'Y1', 'status' => 'active', 'starts_on' => '2026-06-01', 'ends_on' => '2027-03-31']);
        $next = $this->createAcademicYear($this->school, ['code' => 'Y2', 'status' => 'draft', 'starts_on' => '2027-06-01', 'ends_on' => '2028-03-31']);
        $section = $this->createSection($year, $a, $grade, ['code' => 'A', 'status' => 'active']);
        $this->other = [
            'campus_id' => $b->id,
            'section_id' => $this->createSection($year, $a, $grade, ['code' => 'A2', 'status' => 'active'])->id,
            'grade_level_id' => $grade6->id,
            'academic_year_id' => $next->id,
        ];
        $this->placement = $this->createStudentEnrollment($this->createStudent($this->school), $section, ['status' => 'active', 'roll_number' => '1', 'starts_on' => '2026-06-02', 'ends_on' => null]);
    }

    private function runtime(string $sql, array $bindings): void
    {
        app(TenantContext::class)->withSchool($this->school, fn () => DB::transaction(fn () => DB::connection('pgsql')->update($sql, $bindings)));
    }

    #[Test]
    public function the_runtime_role_cannot_rewrite_any_placement_context_column(): void
    {
        $this->assertSame('school_os_app', DB::connection('pgsql')->selectOne('select current_user as u')->u);
        foreach ($this->other as $column => $value) {
            try {
                $this->runtime("UPDATE student_enrollments SET {$column} = ? WHERE id = ?", [$value, $this->placement->id]);
                $this->fail("{$column} was rewritten");
            } catch (QueryException $e) {
                $this->assertStringContainsString('student_enrollments_freeze_placement', $e->getMessage(), $column);
            }
        }
        $row = app(TenantContext::class)->withSchool($this->school, fn () => DB::table('student_enrollments')->where('id', $this->placement->id)->first());
        foreach (array_keys($this->other) as $column) {
            $this->assertSame($this->placement->{$column}, $row->{$column}, "{$column} unchanged");
        }
    }

    #[Test]
    public function the_named_lifecycle_still_ends_a_placement_and_roll_number_is_not_frozen(): void
    {
        // ORG.8E: a withdrawal needs its lifecycle event in the same transaction.
        $this->runtime("INSERT INTO student_lifecycle_events (id, school_id, student_id, student_enrollment_id, action, reason_code, acted_by_user_id) VALUES (?, ?, ?, ?, 'enrollment_withdrawn', 'left_school', ?)",
            [(string) Str::uuid7(), $this->school->id, $this->placement->student_id, $this->placement->id, $this->lifecycleAdmin($this->school)->id]);
        $this->runtime("UPDATE student_enrollments SET status = 'withdrawn', ends_on = '2026-09-01' WHERE id = ?", [$this->placement->id]);
        $this->runtime("UPDATE student_enrollments SET roll_number = '2' WHERE id = ?", [$this->placement->id]);

        $row = app(TenantContext::class)->withSchool($this->school, fn () => DB::table('student_enrollments')->where('id', $this->placement->id)->first());
        $this->assertSame(['withdrawn', '2026-09-01', '2'], [$row->status, (string) $row->ends_on, $row->roll_number]);
    }

    #[Test]
    public function the_trigger_rls_and_section_context_key_are_in_place(): void
    {
        $trigger = DB::connection('pgsql_admin')->selectOne("select tgtype from pg_trigger where tgname = 'student_enrollments_freeze_placement'");
        $this->assertNotNull($trigger);
        $this->assertSame(19, (int) $trigger->tgtype, 'BEFORE UPDATE, FOR EACH ROW');
        $rls = DB::connection('pgsql_admin')->selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'student_enrollments'");
        $this->assertTrue($rls->relrowsecurity && $rls->relforcerowsecurity);

        // A new placement whose Campus differs from its Section's is refused by
        // the ORG.7D-0 composite key -- insert time, raw SQL.
        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/student_enrollments_section_context_fk/');
        app(TenantContext::class)->withSchool($this->school, fn () => DB::transaction(fn () => DB::connection('pgsql')->insert(
            'INSERT INTO student_enrollments (id, school_id, student_id, academic_year_id, campus_id, grade_level_id, section_id, roll_number, status, starts_on, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, now(), now())',
            [(string) Str::uuid7(), $this->school->id, $this->createStudent($this->school)->id, $this->placement->academic_year_id, $this->other['campus_id'], $this->placement->grade_level_id, $this->placement->section_id, '9', 'active', '2026-06-03'],
        )));
    }
}

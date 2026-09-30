<?php

namespace Tests\Feature\Postgres;

use App\Domain\TeachingAssignments\Infrastructure\TeachingAssignment;
use App\Support\Tenancy\TenantRls;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTeachingAssignmentFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * TCH.2 (ADR 0063 sections 9, 19; CLAUDE.md rules 18, 28, 70): what the
 * DATABASE guarantees for `teaching_assignments`, proven with raw SQL on
 * the runtime connection, independent of the service -- forced RLS, the
 * composite same-School/same-context foreign keys, the history trigger,
 * no runtime DELETE, the CHECK shapes, and the absence of the rejected
 * "one open row" index.
 */
class TeachingAssignmentDatabaseInvariantsTest extends TestCase
{
    use CreatesTeachingAssignmentFixtures, CreatesTenancyFixtures;

    private function inSchool(string $schoolId): void
    {
        DB::select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolId]);
    }

    private function assertRefused(callable $statement, string $fragment): void
    {
        try {
            DB::transaction(fn () => $statement());
        } catch (QueryException $e) {
            $this->assertStringContainsString($fragment, $e->getMessage());

            return;
        }

        $this->fail("PostgreSQL accepted a statement it must refuse ({$fragment}).");
    }

    /** @return array<string, mixed> */
    private function row(array $w, array $overrides = []): array
    {
        return array_merge([
            'id' => (string) Str::uuid7(),
            'school_id' => $w['school']->id,
            'employee_id' => $w['employee']->id,
            'academic_year_id' => $w['year']->id,
            'campus_id' => $w['campus']->id,
            'grade_level_id' => $w['grade']->id,
            'section_id' => $w['section']->id,
            'subject_offering_id' => $w['offering']->id,
            'starts_on' => '2026-06-01',
            'ends_on' => null,
            'created_by_user_id' => $w['admin']->id,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides);
    }

    #[Test]
    public function the_table_forces_rls_and_the_runtime_role_cannot_delete(): void
    {
        $row = DB::connection('pgsql_admin')->selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'teaching_assignments' and relnamespace = 'public'::regnamespace");
        $this->assertTrue($row->relrowsecurity);
        $this->assertTrue($row->relforcerowsecurity);

        $privileges = DB::connection('pgsql_admin')->selectOne("select has_table_privilege('school_os_app', 'teaching_assignments', 'DELETE') as can_delete, has_table_privilege('school_os_app', 'teaching_assignments', 'UPDATE') as can_update");
        $this->assertFalse($privileges->can_delete);
        $this->assertTrue($privileges->can_update, 'Ending is an UPDATE; the trigger narrows it.');

        $w = $this->teachingWorld();
        $a = $this->assign($w);
        $this->inSchool($w['school']->id);
        $this->assertRefused(fn () => DB::table('teaching_assignments')->where('id', $a->id)->delete(), 'permission denied for table teaching_assignments');
    }

    #[Test]
    public function a_school_context_sees_only_its_own_assignments_and_none_without_context(): void
    {
        $a = $this->teachingWorld();
        $b = $this->teachingWorld();
        $rowA = $this->assign($a);
        $rowB = $this->assign($b);

        $this->inSchool($a['school']->id);
        $this->assertSame([$rowA->id], DB::table('teaching_assignments')->pluck('id')->all());
        $this->assertSame(0, DB::table('teaching_assignments')->where('id', $rowB->id)->update(['updated_at' => now()]));

        DB::statement('RESET '.TenantRls::SESSION_VAR);
        $this->assertSame(0, DB::table('teaching_assignments')->count(), 'Missing tenant context fails closed.');

        $this->inSchool($a['school']->id);
        $this->assertRefused(fn () => DB::table('teaching_assignments')->insert($this->row($b)), 'row-level security');
    }

    #[Test]
    public function another_schools_employee_section_or_offering_is_refused_by_the_composite_keys(): void
    {
        $w = $this->teachingWorld();
        $other = $this->teachingWorld();
        $this->inSchool($w['school']->id);

        $this->assertRefused(fn () => DB::table('teaching_assignments')->insert($this->row($w, ['employee_id' => $other['employee']->id])), 'teaching_assignments_employee_fk');
        $this->assertRefused(fn () => DB::table('teaching_assignments')->insert($this->row($w, ['section_id' => $other['section']->id])), 'teaching_assignments_section_fk');
        $this->assertRefused(fn () => DB::table('teaching_assignments')->insert($this->row($w, ['subject_offering_id' => $other['offering']->id])), 'teaching_assignments_subject_offering_fk');
    }

    #[Test]
    public function a_section_and_offering_of_different_contexts_cannot_be_paired_even_with_forged_context_columns(): void
    {
        $w = $this->teachingWorld();
        $otherGrade = $this->createGradeLevel($w['school']);
        $otherSection = $this->createSection($w['year'], $w['campus'], $otherGrade, ['code' => 'MISMATCH']);
        $this->inSchool($w['school']->id);

        // Context columns matching the Offering: the Section key fails.
        $this->assertRefused(fn () => DB::table('teaching_assignments')->insert($this->row($w, ['section_id' => $otherSection->id])), 'teaching_assignments_section_fk');
        // Context columns forged to match the Section: the Offering key fails.
        $this->assertRefused(fn () => DB::table('teaching_assignments')->insert($this->row($w, ['section_id' => $otherSection->id, 'grade_level_id' => $otherGrade->id])), 'teaching_assignments_subject_offering_fk');
    }

    #[Test]
    public function identity_columns_can_never_be_rewritten(): void
    {
        $w = $this->teachingWorld();
        $a = $this->assign($w);
        // A second, draft year in the SAME School: every replacement value
        // is a valid same-School reference, so only the trigger refuses.
        $other = $this->teachingWorld($w['school'], 'draft');
        $this->inSchool($w['school']->id);

        $changes = [
            'employee_id' => $other['employee']->id,
            'section_id' => $other['section']->id,
            'subject_offering_id' => $other['offering']->id,
            'academic_year_id' => $other['year']->id,
            'campus_id' => $other['campus']->id,
            'grade_level_id' => $other['grade']->id,
            'starts_on' => '2026-07-01',
            'created_by_user_id' => $other['admin']->id,
        ];

        foreach ($changes as $column => $value) {
            $this->assertRefused(
                fn () => DB::table('teaching_assignments')->where('id', $a->id)->update([$column => $value, 'ends_on' => '2026-09-30', 'ended_at' => now(), 'ended_by_user_id' => $w['admin']->id, 'end_reason' => 'completed']),
                'the only permitted change is ending the assignment',
            );
            $this->assertRefused(fn () => DB::table('teaching_assignments')->where('id', $a->id)->update([$column => $value]), 'the only permitted change is ending the assignment');
        }

        $this->assertRefused(fn () => DB::table('teaching_assignments')->where('id', $a->id)->update(['ends_on' => '2026-09-30']), 'the only permitted change is ending the assignment');
    }

    #[Test]
    public function the_one_permitted_change_is_a_single_end_that_may_only_shorten(): void
    {
        $w = $this->teachingWorld();
        $a = $this->assign($w, '2026-06-01', '2026-12-31');
        $this->inSchool($w['school']->id);
        $end = ['ended_at' => now(), 'ended_by_user_id' => $w['admin']->id, 'end_reason' => 'completed'];

        $this->assertRefused(fn () => DB::table('teaching_assignments')->where('id', $a->id)->update(['ends_on' => '2027-01-31'] + $end), 'never extend it');
        $this->assertRefused(fn () => DB::table('teaching_assignments')->where('id', $a->id)->update(['ends_on' => '2026-05-31'] + $end), 'teaching_assignments_date_range_check');
        $this->assertRefused(fn () => DB::table('teaching_assignments')->where('id', $a->id)->update(['ends_on' => '2026-09-30', 'end_reason' => 'fired'] + array_diff_key($end, ['end_reason' => 1])), 'teaching_assignments_end_shape_check');
        $this->assertRefused(fn () => DB::table('teaching_assignments')->where('id', $a->id)->update(['ends_on' => '2026-09-30', 'ended_at' => now(), 'end_reason' => 'completed']), 'teaching_assignments_end_shape_check');

        DB::table('teaching_assignments')->where('id', $a->id)->update(['ends_on' => '2026-09-30'] + $end);

        foreach ([['ended_at' => null, 'ended_by_user_id' => null, 'end_reason' => null], ['end_reason' => 'reassigned'], ['ends_on' => '2026-08-31'], ['updated_at' => now()]] as $change) {
            $this->assertRefused(fn () => DB::table('teaching_assignments')->where('id', $a->id)->update($change), 'an ended assignment is immutable');
        }
    }

    #[Test]
    public function a_row_cannot_be_inserted_already_ended_or_with_an_inverted_range(): void
    {
        $w = $this->teachingWorld();
        $this->inSchool($w['school']->id);

        $this->assertRefused(fn () => DB::table('teaching_assignments')->insert($this->row($w, ['ends_on' => '2026-06-30', 'ended_at' => now(), 'ended_by_user_id' => $w['admin']->id, 'end_reason' => 'completed'])), 'cannot be created already ended');
        $this->assertRefused(fn () => DB::table('teaching_assignments')->insert($this->row($w, ['ends_on' => '2026-05-31'])), 'teaching_assignments_date_range_check');
    }

    #[Test]
    public function there_is_no_open_row_partial_unique_index_and_co_teaching_rows_coexist(): void
    {
        $indexes = DB::connection('pgsql_admin')->select("select indexname, indexdef from pg_indexes where tablename = 'teaching_assignments'");

        foreach ($indexes as $index) {
            $this->assertStringNotContainsString('WHERE', $index->indexdef, "No partial index ({$index->indexname}): overlap is the service's advisory-locked check.");
            if (str_contains($index->indexdef, 'UNIQUE')) {
                $this->assertContains($index->indexname, ['teaching_assignments_pkey', 'teaching_assignments_id_school_id_unique']);
            }
        }

        $w = $this->teachingWorld();
        $co = $this->employedTeacher($w['school']);
        $this->inSchool($w['school']->id);
        DB::table('teaching_assignments')->insert($this->row($w));
        DB::table('teaching_assignments')->insert($this->row($w, ['employee_id' => $co->id]));
        $this->assertSame(2, DB::table('teaching_assignments')->where('section_id', $w['section']->id)->count());
    }

    #[Test]
    public function the_model_exposes_no_mass_assignable_column(): void
    {
        $this->assertSame([], (new TeachingAssignment)->getFillable());
        $this->assertSame(['completed', 'reassigned', 'employment_ended'], TeachingAssignment::END_REASONS);
    }
}

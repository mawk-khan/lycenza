<?php

namespace Tests\Feature\Postgres;

use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use App\Domain\Syllabus\Infrastructure\SyllabusUnit;
use App\Models\School;
use App\Support\Tenancy\TenantRls;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\UuidV7;
use Tests\Feature\CurriculumDelivery\Concerns\CreatesCurriculumDeliveryFixtures;
use Tests\TestCase;

/**
 * Phase 0H.3B, mandatory per root CLAUDE.md rule 28. Every test here
 * writes RAW SQL, deliberately bypassing the service and all
 * application validation, to prove the DATABASE -- not the application
 * -- enforces tenant isolation, all three composite parent pins, the
 * one-row-per-Section-per-Unit invariant, the status vocabulary, the
 * completion biconditional, date ordering and historical delete
 * protection.
 *
 * The cross-parent tests are the point of this file: composite FK (3)
 * is what makes it impossible for a Section teaching Mathematics to
 * record delivery against a Science SyllabusUnit, and no amount of
 * tenant isolation would catch that.
 *
 * Mirrors SyllabusUnitsRlsIsolationTest/AttendanceRecordsContextIntegrityTest,
 * including the SAVEPOINT-based rejection helper: a constraint
 * violation aborts the current (sub)transaction, so without a savepoint
 * to roll back to, every later statement in this test's enclosing
 * DatabaseTransactions transaction would fail with SQLSTATE 25P02
 * instead of its own real error.
 */
class CurriculumDeliveriesRlsIsolationTest extends TestCase
{
    use CreatesCurriculumDeliveryFixtures;

    private function setSchool(string $schoolId): void
    {
        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolId]);
    }

    private function insertDelivery(School $school, Section $section, SyllabusUnit $unit, SubjectOffering $offering, array $overrides = []): string
    {
        $id = (string) new UuidV7;
        DB::connection('pgsql')->table('curriculum_deliveries')->insert(array_merge([
            'id' => $id,
            'school_id' => $school->id,
            'section_id' => $section->id,
            'syllabus_unit_id' => $unit->id,
            'subject_offering_id' => $offering->id,
            'academic_year_id' => $offering->academic_year_id,
            'campus_id' => $offering->campus_id,
            'grade_level_id' => $offering->grade_level_id,
            'started_on' => $this->today()->subDays(10)->toDateString(),
            'completed_on' => null,
            'status' => 'in_progress',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));

        return $id;
    }

    private function assertRejectedBy(string $constraint, callable $op, string $message): void
    {
        try {
            DB::connection('pgsql')->transaction($op);
            $this->fail($message);
        } catch (QueryException $e) {
            $this->assertStringContainsString($constraint, $e->getMessage(), $message);
        }
    }

    // --- RLS ---------------------------------------------------------

    #[Test]
    public function the_table_has_rls_enabled_and_forced(): void
    {
        $row = DB::connection('pgsql_admin')->selectOne(
            'select relrowsecurity, relforcerowsecurity from pg_class '.
            'where relname = ? and relnamespace = ?::regnamespace',
            ['curriculum_deliveries', 'public'],
        );

        $this->assertNotNull($row);
        $this->assertTrue($row->relrowsecurity, 'curriculum_deliveries must have RLS ENABLED');
        $this->assertTrue($row->relforcerowsecurity, 'curriculum_deliveries must have RLS FORCED');
    }

    #[Test]
    public function tenant_isolation_holds_at_the_raw_sql_layer(): void
    {
        $w = $this->deliveryWorld();
        $this->setSchool($w['school']->id);
        $this->insertDelivery($w['school'], $w['section'], $w['unit'], $w['offering']);

        $this->assertSame(1, DB::connection('pgsql')->table('curriculum_deliveries')->count());

        // School B sees none of School A's rows.
        $other = $this->deliveryWorld();
        $this->setSchool($other['school']->id);
        $this->assertSame(0, DB::connection('pgsql')->table('curriculum_deliveries')->count());

        // Cross-School UPDATE affects zero rows.
        $this->assertSame(0, DB::connection('pgsql')->table('curriculum_deliveries')
            ->where('school_id', $w['school']->id)->update(['status' => 'completed']));

        // Missing tenant context fails closed rather than exposing all.
        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, '']);
        $this->assertSame(0, DB::connection('pgsql')->table('curriculum_deliveries')->count());
    }

    #[Test]
    public function a_raw_insert_for_another_school_is_rejected_by_the_policy(): void
    {
        $w = $this->deliveryWorld();
        $other = $this->deliveryWorld();

        // Active context is School B; try to write a School A row.
        $this->setSchool($other['school']->id);

        $this->assertRejectedBy(
            'row-level security',
            fn () => $this->insertDelivery($w['school'], $w['section'], $w['unit'], $w['offering']),
            'A raw insert for a different School than the active context must be rejected by RLS.',
        );
    }

    // --- the three composite parent pins -----------------------------

    #[Test]
    public function a_section_from_another_context_is_rejected_by_the_composite_fk(): void
    {
        $w = $this->deliveryWorld();
        // A Section in the SAME School and year/campus but a DIFFERENT
        // GradeLevel -- so only the context FK can catch it.
        $otherGrade = $this->createGradeLevel($w['school'], ['code' => 'G9', 'sequence' => 9]);
        $foreignSection = $this->createSection($w['year'], $w['campus'], $otherGrade, ['code' => 'B']);

        $this->setSchool($w['school']->id);

        $this->assertRejectedBy(
            'curriculum_deliveries_section_fk',
            fn () => $this->insertDelivery($w['school'], $foreignSection, $w['unit'], $w['offering']),
            'A Section from a different GradeLevel than the row context must be rejected by the composite FK.',
        );
    }

    #[Test]
    public function a_cross_school_section_is_rejected_by_the_composite_fk(): void
    {
        $w = $this->deliveryWorld();
        $other = $this->deliveryWorld();
        $this->setSchool($w['school']->id);

        $this->assertRejectedBy(
            'curriculum_deliveries_section_fk',
            fn () => $this->insertDelivery($w['school'], $other['section'], $w['unit'], $w['offering']),
            'A CurriculumDelivery must not reference another School\'s Section.',
        );
    }

    #[Test]
    public function a_subject_offering_from_another_context_is_rejected_by_the_composite_fk(): void
    {
        $w = $this->deliveryWorld();

        // An Offering in the same School but a different GradeLevel.
        $otherGrade = $this->createGradeLevel($w['school'], ['code' => 'G9', 'sequence' => 9]);
        $foreignOffering = $this->createSubjectOffering(
            $w['year'], $w['campus'], $otherGrade, $this->createSubject($w['school'], ['code' => 'OTH']),
            ['is_required' => true, 'status' => 'active'],
        );
        $foreignUnit = $this->createSyllabusUnitFor($foreignOffering, ['code' => 'X1']);

        $this->setSchool($w['school']->id);

        // Row context copied from the Section's world, but naming the
        // out-of-context Offering (and its own unit, so FK 3 is
        // satisfied and only FK 2 can reject).
        $this->assertRejectedBy(
            'curriculum_deliveries_subject_offering_fk',
            fn () => $this->insertDelivery($w['school'], $w['section'], $foreignUnit, $foreignOffering, [
                'academic_year_id' => $w['offering']->academic_year_id,
                'campus_id' => $w['offering']->campus_id,
                'grade_level_id' => $w['offering']->grade_level_id,
            ]),
            'A SubjectOffering from a different GradeLevel than the row context must be rejected by the composite FK.',
        );
    }

    #[Test]
    public function a_syllabus_unit_from_another_subject_offering_is_rejected_by_the_composite_fk(): void
    {
        // THE central invariant of this checkpoint: a Section teaching
        // Mathematics must never be able to record delivery against a
        // Science unit -- same School, same AcademicYear, same Campus,
        // same GradeLevel, so tenant isolation and both context FKs are
        // fully satisfied and ONLY FK (3) can catch it.
        $w = $this->deliveryWorld();

        $scienceOffering = $this->createSubjectOffering(
            $w['year'], $w['campus'], $w['grade'], $this->createSubject($w['school'], ['code' => 'SCI']),
            ['is_required' => true, 'status' => 'active'],
        );
        $scienceUnit = $this->createSyllabusUnitFor($scienceOffering, ['code' => 'S1']);

        $this->setSchool($w['school']->id);

        $this->assertRejectedBy(
            'curriculum_deliveries_syllabus_unit_fk',
            fn () => $this->insertDelivery($w['school'], $w['section'], $scienceUnit, $w['offering']),
            'A SyllabusUnit belonging to a different SubjectOffering must be rejected by the composite FK, '.
            'even when every other column is in a perfectly valid same-School context.',
        );
    }

    #[Test]
    public function a_cross_school_syllabus_unit_is_rejected_by_the_composite_fk(): void
    {
        $w = $this->deliveryWorld();
        $other = $this->deliveryWorld();
        $this->setSchool($w['school']->id);

        $this->assertRejectedBy(
            'curriculum_deliveries_syllabus_unit_fk',
            fn () => $this->insertDelivery($w['school'], $w['section'], $other['unit'], $w['offering']),
            'A CurriculumDelivery must not reference another School\'s SyllabusUnit.',
        );
    }

    // --- uniqueness and CHECK constraints ----------------------------

    #[Test]
    public function a_duplicate_section_and_unit_is_rejected_by_the_unique_constraint(): void
    {
        $w = $this->deliveryWorld();
        $this->setSchool($w['school']->id);
        $this->insertDelivery($w['school'], $w['section'], $w['unit'], $w['offering']);

        $this->assertRejectedBy(
            'curriculum_deliveries_section_unit_unique',
            fn () => $this->insertDelivery($w['school'], $w['section'], $w['unit'], $w['offering']),
            'One Section may have only ONE delivery row per SyllabusUnit.',
        );

        // A DIFFERENT Section covering the same unit is legitimate --
        // that is exactly the per-Section variation this table exists
        // for.
        $sectionB = $this->createSection($w['year'], $w['campus'], $w['grade'], ['code' => 'B']);
        $this->setSchool($w['school']->id);
        $this->insertDelivery($w['school'], $sectionB, $w['unit'], $w['offering']);

        $this->assertSame(2, DB::connection('pgsql')->table('curriculum_deliveries')->count());
    }

    #[Test]
    public function an_invalid_status_is_rejected_by_the_check_constraint(): void
    {
        $w = $this->deliveryWorld();
        $this->setSchool($w['school']->id);

        // `not_started` is deliberately NOT a stored value -- absence of
        // a row is what "not started" means.
        $this->assertRejectedBy(
            'curriculum_deliveries_status_check',
            fn () => $this->insertDelivery($w['school'], $w['section'], $w['unit'], $w['offering'], ['status' => 'not_started']),
            'An out-of-vocabulary status must be rejected by the CHECK constraint.',
        );
    }

    #[Test]
    public function a_completed_row_without_a_completion_date_is_rejected(): void
    {
        $w = $this->deliveryWorld();
        $this->setSchool($w['school']->id);

        $this->assertRejectedBy(
            'curriculum_deliveries_completion_check',
            fn () => $this->insertDelivery($w['school'], $w['section'], $w['unit'], $w['offering'], [
                'status' => 'completed', 'completed_on' => null,
            ]),
            'A completed delivery must carry a completion date.',
        );
    }

    #[Test]
    public function an_in_progress_row_with_a_completion_date_is_rejected(): void
    {
        $w = $this->deliveryWorld();
        $this->setSchool($w['school']->id);

        $this->assertRejectedBy(
            'curriculum_deliveries_completion_check',
            fn () => $this->insertDelivery($w['school'], $w['section'], $w['unit'], $w['offering'], [
                'status' => 'in_progress', 'completed_on' => $this->today()->toDateString(),
            ]),
            'An in-progress delivery must not carry a completion date.',
        );
    }

    #[Test]
    public function a_completion_date_before_the_start_date_is_rejected(): void
    {
        $w = $this->deliveryWorld();
        $this->setSchool($w['school']->id);

        $this->assertRejectedBy(
            'curriculum_deliveries_date_order_check',
            fn () => $this->insertDelivery($w['school'], $w['section'], $w['unit'], $w['offering'], [
                'started_on' => $this->today()->subDays(5)->toDateString(),
                'status' => 'completed',
                'completed_on' => $this->today()->subDays(10)->toDateString(),
            ]),
            'A delivery cannot finish before it started.',
        );
    }

    // --- historical delete protection --------------------------------

    #[Test]
    public function a_referenced_parent_cannot_be_hard_deleted(): void
    {
        $w = $this->deliveryWorld();
        $this->setSchool($w['school']->id);
        $this->insertDelivery($w['school'], $w['section'], $w['unit'], $w['offering']);

        $this->assertRejectedBy(
            'curriculum_deliveries_section_fk',
            fn () => DB::connection('pgsql')->table('sections')->where('id', $w['section']->id)->delete(),
            'A Section with delivery history must not be hard-deletable.',
        );

        $this->assertRejectedBy(
            'curriculum_deliveries_syllabus_unit_fk',
            fn () => DB::connection('pgsql')->table('syllabus_units')->where('id', $w['unit']->id)->delete(),
            'A SyllabusUnit with delivery history must not be hard-deletable.',
        );

        // The Offering is protected by TWO restrict FKs at once: Phase
        // 0H.3A's `syllabus_units_subject_offering_fk` fires first
        // because the unit still references it. Asserting the generic
        // rejection keeps this test honest about which constraint
        // PostgreSQL actually reports, while
        // `the_designed_constraints_exist_with_the_exact_expected_shape`
        // proves this table's own FK is RESTRICT too.
        $this->assertRejectedBy(
            'violates foreign key constraint',
            fn () => DB::connection('pgsql')->table('subject_offerings')->where('id', $w['offering']->id)->delete(),
            'A SubjectOffering with delivery history must not be hard-deletable.',
        );
    }

    // --- designed shape ----------------------------------------------

    #[Test]
    public function the_designed_constraints_exist_with_the_exact_expected_shape(): void
    {
        $constraints = collect(DB::connection('pgsql_admin')->select(
            'select conname, pg_get_constraintdef(oid) as def from pg_constraint where conrelid = ?::regclass',
            ['curriculum_deliveries'],
        ))->pluck('def', 'conname')->all();

        $this->assertSame(
            'FOREIGN KEY (section_id, school_id, academic_year_id, campus_id, grade_level_id) '.
            'REFERENCES sections(id, school_id, academic_year_id, campus_id, grade_level_id) ON DELETE RESTRICT',
            $constraints['curriculum_deliveries_section_fk'] ?? null,
        );
        $this->assertSame(
            'FOREIGN KEY (subject_offering_id, school_id, academic_year_id, campus_id, grade_level_id) '.
            'REFERENCES subject_offerings(id, school_id, academic_year_id, campus_id, grade_level_id) ON DELETE RESTRICT',
            $constraints['curriculum_deliveries_subject_offering_fk'] ?? null,
        );
        $this->assertSame(
            'FOREIGN KEY (syllabus_unit_id, school_id, subject_offering_id) '.
            'REFERENCES syllabus_units(id, school_id, subject_offering_id) ON DELETE RESTRICT',
            $constraints['curriculum_deliveries_syllabus_unit_fk'] ?? null,
        );
        $this->assertSame(
            'UNIQUE (school_id, section_id, syllabus_unit_id)',
            $constraints['curriculum_deliveries_section_unit_unique'] ?? null,
        );
        $this->assertSame(
            'UNIQUE (id, school_id)',
            $constraints['curriculum_deliveries_id_school_id_unique'] ?? null,
        );
        $this->assertArrayHasKey('curriculum_deliveries_status_check', $constraints);
        $this->assertArrayHasKey('curriculum_deliveries_completion_check', $constraints);
        $this->assertArrayHasKey('curriculum_deliveries_date_order_check', $constraints);

        // Every composite-FK component must be NOT NULL: under
        // PostgreSQL's default MATCH SIMPLE a foreign-key check is
        // SKIPPED entirely when any referencing column is NULL, which
        // would silently disable all three pins above.
        $nullable = collect(DB::connection('pgsql_admin')->select(
            'select column_name from information_schema.columns '.
            'where table_name = ? and is_nullable = ?',
            ['curriculum_deliveries', 'YES'],
        ))->pluck('column_name')->sort()->values()->all();

        // `completed_on` is the only nullable BUSINESS column (Laravel's
        // own created_at/updated_at are conventionally nullable and
        // participate in nothing).
        $this->assertSame(['completed_on', 'created_at', 'updated_at'], $nullable,
            'Every composite-FK component must stay NOT NULL; completed_on is the only nullable business column.');
    }

    #[Test]
    public function the_syllabus_units_offering_context_key_exists(): void
    {
        // The additive Phase 0H.3B prerequisite that makes composite FK
        // (3) declarable at all. Asserted here (not only in the Syllabus
        // suite) because Curriculum Delivery's central invariant depends
        // on it directly.
        $constraints = collect(DB::connection('pgsql_admin')->select(
            'select conname, pg_get_constraintdef(oid) as def from pg_constraint where conrelid = ?::regclass',
            ['syllabus_units'],
        ))->pluck('def', 'conname')->all();

        $this->assertSame(
            'UNIQUE (id, school_id, subject_offering_id)',
            $constraints['syllabus_units_offering_context_unique'] ?? null,
        );

        // ...and Phase 0H.3A's own guarantees are untouched by it.
        $this->assertSame('UNIQUE (id, school_id)', $constraints['syllabus_units_id_school_id_unique'] ?? null);
        $this->assertArrayHasKey('syllabus_units_status_check', $constraints);
        $this->assertArrayHasKey('syllabus_units_subject_offering_fk', $constraints);
    }
}

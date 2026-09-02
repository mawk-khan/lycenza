<?php

namespace Tests\Feature\Postgres;

use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use App\Domain\Examinations\Infrastructure\Examination;
use App\Models\School;
use App\Support\Tenancy\TenantRls;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\UuidV7;
use Tests\Feature\Examinations\Concerns\CreatesExaminationPaperFixtures;
use Tests\TestCase;

/**
 * Phase 0H.4B, mandatory per root CLAUDE.md rule 28. Every test here
 * writes RAW SQL, deliberately bypassing the service and all application
 * validation, to prove the DATABASE -- not the application -- enforces
 * tenant isolation, both cross-parent composite FKs (and, critically,
 * that they cannot both be satisfied by a forged/mismatched
 * `academic_year_id`), the aggregate unique constraint, and every CHECK
 * constraint.
 *
 * Mirrors ExaminationsRlsIsolationTest, including the SAVEPOINT-based
 * rejection helper.
 *
 * THERE IS DELIBERATELY NO CONCURRENCY TEST IN THIS MODULE: there is no
 * multi-row overlap invariant (overlapping sittings across different
 * SubjectOfferings are explicitly permitted), no CAS transition, and no
 * Student-aware conflict detection -- the ONLY race is two concurrent
 * creates of the same (Examination, SubjectOffering) pair, settled by a
 * single PostgreSQL unique index, asserted directly below.
 */
class ExaminationPapersRlsIsolationTest extends TestCase
{
    use CreatesExaminationPaperFixtures;

    private function setSchool(string $schoolId): void
    {
        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolId]);
    }

    private function insertExaminationPaper(School $school, Examination $examination, SubjectOffering $offering, array $overrides = []): string
    {
        $id = (string) new UuidV7;
        DB::connection('pgsql')->table('examination_papers')->insert(array_merge([
            'id' => $id,
            'school_id' => $school->id,
            'examination_id' => $examination->id,
            'subject_offering_id' => $offering->id,
            'academic_year_id' => $examination->academic_year_id,
            'campus_id' => $offering->campus_id,
            'grade_level_id' => $offering->grade_level_id,
            'scheduled_on' => $this->today()->addDays(10)->toDateString(),
            'starts_at' => '09:00:00',
            'ends_at' => '11:00:00',
            'max_marks' => '100.00',
            'status' => 'active',
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

    // --- RLS ------------------------------------------------------------

    #[Test]
    public function the_table_has_rls_enabled_and_forced(): void
    {
        $row = DB::connection('pgsql_admin')->selectOne(
            'select relrowsecurity, relforcerowsecurity from pg_class '.
            'where relname = ? and relnamespace = ?::regnamespace',
            ['examination_papers', 'public'],
        );

        $this->assertNotNull($row);
        $this->assertTrue($row->relrowsecurity, 'examination_papers must have RLS ENABLED');
        $this->assertTrue($row->relforcerowsecurity, 'examination_papers must have RLS FORCED');
    }

    #[Test]
    public function tenant_isolation_holds_at_the_raw_sql_layer(): void
    {
        $w = $this->examinationPaperWorld();
        $this->setSchool($w['school']->id);
        $this->insertExaminationPaper($w['school'], $w['examination'], $w['subjectOffering']);

        $this->assertSame(1, DB::connection('pgsql')->table('examination_papers')->count());

        $other = $this->examinationPaperWorld();
        $this->setSchool($other['school']->id);
        $this->assertSame(0, DB::connection('pgsql')->table('examination_papers')->count());

        $this->assertSame(0, DB::connection('pgsql')->table('examination_papers')
            ->where('school_id', $w['school']->id)->update(['max_marks' => '1.00']));

        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, '']);
        $this->assertSame(0, DB::connection('pgsql')->table('examination_papers')->count());
    }

    #[Test]
    public function a_raw_insert_for_another_school_is_rejected_by_the_policy(): void
    {
        $w = $this->examinationPaperWorld();
        $other = $this->examinationPaperWorld();

        $this->setSchool($other['school']->id);

        $this->assertRejectedBy(
            'row-level security',
            fn () => $this->insertExaminationPaper($w['school'], $w['examination'], $w['subjectOffering']),
            'A raw insert for a different School than the active context must be rejected by RLS.',
        );
    }

    // --- tenant-pinned parents -------------------------------------------

    #[Test]
    public function a_cross_school_examination_is_rejected_by_its_composite_fk(): void
    {
        $w = $this->examinationPaperWorld();
        $other = $this->examinationPaperWorld();
        $this->setSchool($w['school']->id);

        $this->assertRejectedBy(
            'examination_papers_examination_fk',
            fn () => $this->insertExaminationPaper($w['school'], $other['examination'], $w['subjectOffering']),
            'An ExaminationPaper must not reference another School\'s Examination.',
        );
    }

    #[Test]
    public function a_cross_school_subject_offering_is_rejected_by_its_composite_fk(): void
    {
        $w = $this->examinationPaperWorld();
        $other = $this->examinationPaperWorld();
        $this->setSchool($w['school']->id);

        $this->assertRejectedBy(
            'examination_papers_subject_offering_fk',
            fn () => $this->insertExaminationPaper($w['school'], $w['examination'], $other['subjectOffering']),
            'An ExaminationPaper must not reference another School\'s SubjectOffering.',
        );
    }

    #[Test]
    public function a_cross_academic_year_mismatch_using_the_examinations_year_is_rejected(): void
    {
        // Same School, but the Examination and SubjectOffering belong to
        // DIFFERENT AcademicYears. Storing the Examination's own
        // academic_year_id satisfies the Examination FK but cannot
        // satisfy the SubjectOffering FK, because no subject_offerings
        // row exists for that (subject_offering_id, academic_year_id)
        // combination.
        $w = $this->examinationPaperWorld();
        $nextYear = $this->createAcademicYear($w['school'], [
            'code' => 'AY-NEXT', 'status' => 'draft',
            'starts_on' => $this->today()->addMonths(7)->toDateString(),
            'ends_on' => $this->today()->addMonths(18)->toDateString(),
        ]);
        $otherOffering = $this->createSubjectOffering($nextYear, $w['campus'], $w['gradeLevel'], $w['subject']);
        $this->setSchool($w['school']->id);

        $this->assertRejectedBy(
            'examination_papers_subject_offering_fk',
            fn () => $this->insertExaminationPaper($w['school'], $w['examination'], $otherOffering, [
                'academic_year_id' => $w['examination']->academic_year_id,
                'campus_id' => $otherOffering->campus_id,
                'grade_level_id' => $otherOffering->grade_level_id,
            ]),
            'A cross-AcademicYear Examination/SubjectOffering mismatch must be rejected even though the stored academic_year_id matches the Examination.',
        );
    }

    #[Test]
    public function a_cross_academic_year_mismatch_using_the_offerings_year_is_rejected(): void
    {
        // The mirror image: storing the SubjectOffering's own
        // academic_year_id satisfies the SubjectOffering FK but cannot
        // satisfy the Examination FK.
        $w = $this->examinationPaperWorld();
        $nextYear = $this->createAcademicYear($w['school'], [
            'code' => 'AY-NEXT2', 'status' => 'draft',
            'starts_on' => $this->today()->addMonths(7)->toDateString(),
            'ends_on' => $this->today()->addMonths(18)->toDateString(),
        ]);
        $otherOffering = $this->createSubjectOffering($nextYear, $w['campus'], $w['gradeLevel'], $w['subject']);
        $this->setSchool($w['school']->id);

        $this->assertRejectedBy(
            'examination_papers_examination_fk',
            fn () => $this->insertExaminationPaper($w['school'], $w['examination'], $otherOffering, [
                'academic_year_id' => $otherOffering->academic_year_id,
                'campus_id' => $otherOffering->campus_id,
                'grade_level_id' => $otherOffering->grade_level_id,
            ]),
            'No single stored academic_year_id can satisfy both composite FKs when the parents genuinely disagree.',
        );
    }

    #[Test]
    public function all_composite_fk_components_are_not_null(): void
    {
        $columns = collect(DB::connection('pgsql_admin')->select(
            'select column_name, is_nullable from information_schema.columns where table_name = ?',
            ['examination_papers'],
        ))->pluck('is_nullable', 'column_name');

        foreach (['school_id', 'examination_id', 'subject_offering_id', 'academic_year_id', 'campus_id', 'grade_level_id'] as $column) {
            $this->assertSame('NO', $columns[$column] ?? null, "`{$column}` must be NOT NULL.");
        }
    }

    #[Test]
    public function a_referenced_examination_cannot_be_hard_deleted(): void
    {
        $w = $this->examinationPaperWorld();
        $this->setSchool($w['school']->id);
        $this->insertExaminationPaper($w['school'], $w['examination'], $w['subjectOffering']);

        $this->assertRejectedBy(
            'examination_papers_examination_fk',
            fn () => DB::connection('pgsql')->table('examinations')->where('id', $w['examination']->id)->delete(),
            'An Examination carrying ExaminationPapers must not be hard-deletable.',
        );
    }

    #[Test]
    public function a_referenced_subject_offering_cannot_be_hard_deleted(): void
    {
        $w = $this->examinationPaperWorld();
        $this->setSchool($w['school']->id);
        $this->insertExaminationPaper($w['school'], $w['examination'], $w['subjectOffering']);

        $this->assertRejectedBy(
            'examination_papers_subject_offering_fk',
            fn () => DB::connection('pgsql')->table('subject_offerings')->where('id', $w['subjectOffering']->id)->delete(),
            'A SubjectOffering carrying ExaminationPapers must not be hard-deletable.',
        );
    }

    // --- aggregate uniqueness --------------------------------------------

    #[Test]
    public function a_duplicate_examination_offering_pair_is_rejected(): void
    {
        $w = $this->examinationPaperWorld();
        $this->setSchool($w['school']->id);
        $this->insertExaminationPaper($w['school'], $w['examination'], $w['subjectOffering']);

        $this->assertRejectedBy(
            'examination_papers_examination_offering_unique',
            fn () => $this->insertExaminationPaper($w['school'], $w['examination'], $w['subjectOffering'], [
                'scheduled_on' => $this->today()->addDays(11)->toDateString(),
            ]),
            'A second Paper for the same Examination and SubjectOffering must be rejected.',
        );
    }

    #[Test]
    public function an_inactive_paper_still_reserves_the_aggregate_pair(): void
    {
        $w = $this->examinationPaperWorld();
        $this->setSchool($w['school']->id);
        $this->insertExaminationPaper($w['school'], $w['examination'], $w['subjectOffering'], ['status' => 'inactive']);

        $this->assertRejectedBy(
            'examination_papers_examination_offering_unique',
            fn () => $this->insertExaminationPaper($w['school'], $w['examination'], $w['subjectOffering']),
            'An inactive Paper must continue to reserve its (Examination, SubjectOffering) pair.',
        );
    }

    #[Test]
    public function the_same_offering_under_a_different_examination_is_accepted(): void
    {
        $w = $this->examinationPaperWorld();
        $this->setSchool($w['school']->id);
        $this->insertExaminationPaper($w['school'], $w['examination'], $w['subjectOffering']);

        $secondExamination = $this->createExamination($w['year'], [
            'code' => 'FINAL1',
            'starts_on' => $this->today()->addDays(40)->toDateString(),
            'ends_on' => $this->today()->addDays(50)->toDateString(),
        ]);
        // createExamination() runs inside TenantContext::withSchool(),
        // which restores the RLS session var on exit -- re-set it before
        // the next raw insert, mirroring
        // ExaminationsRlsIsolationTest::the_same_code_in_a_different_academic_year_is_accepted's
        // identical precedent.
        $this->setSchool($w['school']->id);

        $this->insertExaminationPaper($w['school'], $secondExamination, $w['subjectOffering'], [
            'scheduled_on' => $this->today()->addDays(42)->toDateString(),
        ]);

        $this->assertSame(2, DB::connection('pgsql')->table('examination_papers')->count());
    }

    // --- CHECK constraints ------------------------------------------------

    #[Test]
    public function an_invalid_status_is_rejected(): void
    {
        $w = $this->examinationPaperWorld();
        $this->setSchool($w['school']->id);

        $this->assertRejectedBy(
            'examination_papers_status_check',
            fn () => $this->insertExaminationPaper($w['school'], $w['examination'], $w['subjectOffering'], ['status' => 'draft']),
            'An out-of-vocabulary status must be rejected by the CHECK constraint.',
        );
    }

    #[Test]
    public function an_end_time_not_after_the_start_time_is_rejected(): void
    {
        $w = $this->examinationPaperWorld();
        $this->setSchool($w['school']->id);

        $this->assertRejectedBy(
            'examination_papers_time_order_check',
            fn () => $this->insertExaminationPaper($w['school'], $w['examination'], $w['subjectOffering'], [
                'starts_at' => '11:00:00', 'ends_at' => '09:00:00',
            ]),
            'ends_at must be strictly after starts_at.',
        );

        $this->assertRejectedBy(
            'examination_papers_time_order_check',
            fn () => $this->insertExaminationPaper($w['school'], $w['examination'], $w['subjectOffering'], [
                'starts_at' => '09:00:00', 'ends_at' => '09:00:00',
            ]),
            'ends_at equal to starts_at must be rejected.',
        );
    }

    #[Test]
    public function a_non_positive_max_marks_is_rejected(): void
    {
        $w = $this->examinationPaperWorld();
        $this->setSchool($w['school']->id);

        $this->assertRejectedBy(
            'examination_papers_max_marks_check',
            fn () => $this->insertExaminationPaper($w['school'], $w['examination'], $w['subjectOffering'], ['max_marks' => '0.00']),
            'max_marks of zero must be rejected.',
        );

        $this->assertRejectedBy(
            'examination_papers_max_marks_check',
            fn () => $this->insertExaminationPaper($w['school'], $w['examination'], $w['subjectOffering'], ['max_marks' => '-5.00']),
            'A negative max_marks must be rejected.',
        );
    }

    #[Test]
    public function overlapping_sittings_across_different_offerings_are_accepted(): void
    {
        $w = $this->examinationPaperWorld();
        $secondOffering = $this->createSubjectOffering($w['year'], $w['campus'], $w['gradeLevel'], $this->createSubject($w['school']));
        $this->setSchool($w['school']->id);

        $this->insertExaminationPaper($w['school'], $w['examination'], $w['subjectOffering'], [
            'scheduled_on' => $this->today()->addDays(10)->toDateString(),
            'starts_at' => '09:00:00', 'ends_at' => '11:00:00',
        ]);
        $this->insertExaminationPaper($w['school'], $w['examination'], $secondOffering, [
            'scheduled_on' => $this->today()->addDays(10)->toDateString(),
            'starts_at' => '09:00:00', 'ends_at' => '11:00:00',
        ]);

        $this->assertSame(2, DB::connection('pgsql')->table('examination_papers')->count(),
            'Overlapping sittings across different SubjectOfferings must both be permitted.');
    }

    // --- designed shape -----------------------------------------------------

    #[Test]
    public function the_designed_constraints_exist_with_the_exact_expected_shape(): void
    {
        $constraints = collect(DB::connection('pgsql_admin')->select(
            'select conname, pg_get_constraintdef(oid) as def from pg_constraint where conrelid = ?::regclass',
            ['examination_papers'],
        ))->pluck('def', 'conname')->all();

        $this->assertSame(
            'FOREIGN KEY (examination_id, school_id, academic_year_id) REFERENCES examinations(id, school_id, academic_year_id) ON DELETE RESTRICT',
            $constraints['examination_papers_examination_fk'] ?? null,
        );
        $this->assertSame(
            'FOREIGN KEY (subject_offering_id, school_id, academic_year_id, campus_id, grade_level_id) REFERENCES subject_offerings(id, school_id, academic_year_id, campus_id, grade_level_id) ON DELETE RESTRICT',
            $constraints['examination_papers_subject_offering_fk'] ?? null,
        );
        $this->assertSame('UNIQUE (id, school_id)', $constraints['examination_papers_id_school_id_unique'] ?? null);
        $this->assertSame(
            'UNIQUE (school_id, examination_id, subject_offering_id)',
            $constraints['examination_papers_examination_offering_unique'] ?? null,
        );
        $this->assertArrayHasKey('examination_papers_status_check', $constraints);
        $this->assertArrayHasKey('examination_papers_time_order_check', $constraints);
        $this->assertArrayHasKey('examination_papers_max_marks_check', $constraints);

        $foreignKeys = collect(DB::connection('pgsql_admin')->select(
            'select conname from pg_constraint where conrelid = ?::regclass and contype = ?',
            ['examination_papers', 'f'],
        ))->pluck('conname')->sort()->values()->all();

        $this->assertSame(
            ['examination_papers_examination_fk', 'examination_papers_school_id_foreign', 'examination_papers_subject_offering_fk'],
            $foreignKeys,
            'ExaminationPaper has exactly three foreign keys: the tenant root, the Examination and the SubjectOffering.',
        );
    }

    #[Test]
    public function the_examinations_context_unique_key_exists(): void
    {
        $constraints = collect(DB::connection('pgsql_admin')->select(
            'select conname, pg_get_constraintdef(oid) as def from pg_constraint where conrelid = ?::regclass',
            ['examinations'],
        ))->pluck('def', 'conname')->all();

        $this->assertSame(
            'UNIQUE (id, school_id, academic_year_id)',
            $constraints['examinations_context_unique'] ?? null,
        );

        // The pre-existing 0H.4A design is otherwise untouched -- full
        // re-verification is ExaminationsRlsIsolationTest's own job
        // (rerun unchanged, CLAUDE.md rule 54).
        $this->assertArrayHasKey('examinations_academic_year_fk', $constraints);
        $this->assertArrayHasKey('examinations_id_school_id_unique', $constraints);
        $this->assertArrayHasKey('examinations_status_check', $constraints);
        $this->assertArrayHasKey('examinations_date_order_check', $constraints);
    }

    #[Test]
    public function the_subject_offerings_context_unique_key_remains_intact(): void
    {
        $constraints = collect(DB::connection('pgsql_admin')->select(
            'select conname, pg_get_constraintdef(oid) as def from pg_constraint where conrelid = ?::regclass',
            ['subject_offerings'],
        ))->pluck('def', 'conname')->all();

        $this->assertSame(
            'UNIQUE (id, school_id, academic_year_id, campus_id, grade_level_id)',
            $constraints['subject_offerings_context_unique'] ?? null,
        );
    }

    #[Test]
    public function the_table_carries_no_person_teacher_room_or_marks_column(): void
    {
        $columns = collect(DB::connection('pgsql_admin')->select(
            'select column_name from information_schema.columns where table_name = ?',
            ['examination_papers'],
        ))->pluck('column_name')->all();

        $this->assertSame([
            'id', 'school_id', 'examination_id', 'subject_offering_id',
            'academic_year_id', 'campus_id', 'grade_level_id',
            'scheduled_on', 'starts_at', 'ends_at', 'max_marks', 'status',
            'created_at', 'updated_at',
        ], $columns, 'The examination_papers column set is closed and reviewed; adding one is an architecture decision.');
    }
}

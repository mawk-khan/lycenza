<?php

namespace Tests\Feature\Postgres;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 0D section 36/82 (mandatory): every composite foreign key
 * protecting a Phase 0D table against a cross-School parent reference,
 * proven via a raw insert through `pgsql_admin` (bypassing RLS's own
 * WITH CHECK so the failure can only be the composite FK itself, the
 * same isolation technique WebhookRlsIsolationTest already uses).
 */
class AcademicStructureCrossRelationTest extends TestCase
{
    use CreatesTenancyFixtures;

    #[Test]
    public function a_section_cannot_reference_another_schools_academic_year(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();

        $campusA = $this->createCampus($schoolA);
        $gradeA = $this->createGradeLevel($schoolA);
        $yearB = $this->createAcademicYear($schoolB);

        $this->expectException(QueryException::class);

        DB::connection('pgsql_admin')->insert(
            'insert into sections (id, school_id, academic_year_id, campus_id, grade_level_id, name, code, created_at, updated_at) '.
            "values (?, ?, ?, ?, ?, 'A', 'A', now(), now())",
            [(string) Str::orderedUuid(), $schoolA->id, $yearB->id, $campusA->id, $gradeA->id],
        );
    }

    #[Test]
    public function a_section_cannot_reference_another_schools_campus(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();

        $yearA = $this->createAcademicYear($schoolA);
        $gradeA = $this->createGradeLevel($schoolA);
        $campusB = $this->createCampus($schoolB);

        $this->expectException(QueryException::class);

        DB::connection('pgsql_admin')->insert(
            'insert into sections (id, school_id, academic_year_id, campus_id, grade_level_id, name, code, created_at, updated_at) '.
            "values (?, ?, ?, ?, ?, 'A', 'A', now(), now())",
            [(string) Str::orderedUuid(), $schoolA->id, $yearA->id, $campusB->id, $gradeA->id],
        );
    }

    #[Test]
    public function a_section_cannot_reference_another_schools_grade_level(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();

        $yearA = $this->createAcademicYear($schoolA);
        $campusA = $this->createCampus($schoolA);
        $gradeB = $this->createGradeLevel($schoolB);

        $this->expectException(QueryException::class);

        DB::connection('pgsql_admin')->insert(
            'insert into sections (id, school_id, academic_year_id, campus_id, grade_level_id, name, code, created_at, updated_at) '.
            "values (?, ?, ?, ?, ?, 'A', 'A', now(), now())",
            [(string) Str::orderedUuid(), $schoolA->id, $yearA->id, $campusA->id, $gradeB->id],
        );
    }

    #[Test]
    public function a_room_cannot_reference_another_schools_campus(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();

        $campusB = $this->createCampus($schoolB);

        $this->expectException(QueryException::class);

        DB::connection('pgsql_admin')->insert(
            'insert into rooms (id, school_id, campus_id, name, code, created_at, updated_at) '.
            "values (?, ?, ?, 'Room 101', 'R101', now(), now())",
            [(string) Str::orderedUuid(), $schoolA->id, $campusB->id],
        );
    }

    #[Test]
    public function a_subject_offering_cannot_reference_another_schools_subject(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();

        $yearA = $this->createAcademicYear($schoolA);
        $campusA = $this->createCampus($schoolA);
        $gradeA = $this->createGradeLevel($schoolA);
        $subjectB = $this->createSubject($schoolB);

        $this->expectException(QueryException::class);

        DB::connection('pgsql_admin')->insert(
            'insert into subject_offerings (id, school_id, academic_year_id, campus_id, grade_level_id, subject_id, created_at, updated_at) '.
            'values (?, ?, ?, ?, ?, ?, now(), now())',
            [(string) Str::orderedUuid(), $schoolA->id, $yearA->id, $campusA->id, $gradeA->id, $subjectB->id],
        );
    }

    #[Test]
    public function a_subject_offering_cannot_reference_another_schools_grade_level(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();

        $yearA = $this->createAcademicYear($schoolA);
        $campusA = $this->createCampus($schoolA);
        $gradeB = $this->createGradeLevel($schoolB);
        $subjectA = $this->createSubject($schoolA);

        $this->expectException(QueryException::class);

        DB::connection('pgsql_admin')->insert(
            'insert into subject_offerings (id, school_id, academic_year_id, campus_id, grade_level_id, subject_id, created_at, updated_at) '.
            'values (?, ?, ?, ?, ?, ?, now(), now())',
            [(string) Str::orderedUuid(), $schoolA->id, $yearA->id, $campusA->id, $gradeB->id, $subjectA->id],
        );
    }

    #[Test]
    public function an_academic_term_cannot_reference_another_schools_academic_year(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();

        $yearB = $this->createAcademicYear($schoolB);

        $this->expectException(QueryException::class);

        DB::connection('pgsql_admin')->insert(
            'insert into academic_terms (id, school_id, academic_year_id, name, code, starts_on, ends_on, sequence, created_at, updated_at) '.
            "values (?, ?, ?, 'Term 1', 'T1', '2026-06-01', '2026-09-01', 1, now(), now())",
            [(string) Str::orderedUuid(), $schoolA->id, $yearB->id],
        );
    }

    #[Test]
    public function a_subject_cannot_reference_another_schools_academic_department(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();

        $departmentB = $this->createAcademicDepartment($schoolB);

        $this->expectException(QueryException::class);

        DB::connection('pgsql_admin')->insert(
            'insert into subjects (id, school_id, academic_department_id, name, code, subject_type, created_at, updated_at) '.
            "values (?, ?, ?, 'Math', 'MATH', 'core', now(), now())",
            [(string) Str::orderedUuid(), $schoolA->id, $departmentB->id],
        );
    }

    #[Test]
    public function same_campus_grade_and_subject_codes_are_allowed_across_different_schools(): void
    {
        // Positive control (section 81): reusing an identical CODE
        // across two different Schools must never collide -- codes are
        // School-scoped, never globally unique.
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();

        $campusA = $this->createCampus($schoolA, ['code' => 'MAIN']);
        $campusB = $this->createCampus($schoolB, ['code' => 'MAIN']);
        $this->assertSame('MAIN', $campusA->code);
        $this->assertSame('MAIN', $campusB->code);

        $gradeA = $this->createGradeLevel($schoolA, ['code' => 'G5', 'sequence' => 5]);
        $gradeB = $this->createGradeLevel($schoolB, ['code' => 'G5', 'sequence' => 5]);
        $this->assertSame('G5', $gradeA->code);
        $this->assertSame('G5', $gradeB->code);

        $subjectA = $this->createSubject($schoolA, ['code' => 'MATH']);
        $subjectB = $this->createSubject($schoolB, ['code' => 'MATH']);
        $this->assertSame('MATH', $subjectA->code);
        $this->assertSame('MATH', $subjectB->code);
    }
}

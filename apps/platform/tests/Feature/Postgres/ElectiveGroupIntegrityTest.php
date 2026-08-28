<?php

namespace Tests\Feature\Postgres;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\GradeLevel;
use App\Domain\AcademicStructure\Infrastructure\Subject;
use App\Models\Campus;
use App\Models\School;
use App\Support\Tenancy\TenantRls;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 1F.1 (mandatory, architecture doc §8/§9/§12-14): `elective_groups`
 * code uniqueness, same-School context FKs, and `subject_offerings`'
 * elective-group context FK / required-offering CHECK, proven at the
 * raw-SQL level against real PostgreSQL under the unprivileged
 * `school_os_app` runtime role -- mirrors
 * StudentSubjectEnrollmentIntegrityTest's pattern exactly. RLS coverage
 * for `elective_groups` itself lives in AcademicStructureRlsIsolationTest
 * (added to its shared table list), not duplicated here.
 *
 * Raw inserts use the `pgsql` connection with an explicit tenant
 * context set (`setSchool()`), not `pgsql_admin` -- `pgsql_admin` is a
 * genuinely separate PostgreSQL session from the one PHPUnit's
 * `DatabaseTransactions` wraps this test's fixtures in, so an
 * uncommitted fixture row (e.g. a foreign School's AcademicYear) would
 * be invisible to it, turning a genuine cross-context FK proof into an
 * accidental "row doesn't exist yet" pass for the wrong reason. See
 * StudentSubjectEnrollmentElectiveGroupIntegrityTest's identical
 * rationale.
 */
class ElectiveGroupIntegrityTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function setSchool(string $schoolId): void
    {
        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolId]);
    }

    /**
     * @return array{school: School, campus: Campus, year: AcademicYear, grade: GradeLevel, subject: Subject}
     */
    private function buildContext(): array
    {
        $school = $this->createSchool();
        $campus = $this->createCampus($school);
        $year = $this->createAcademicYear($school);
        $grade = $this->createGradeLevel($school);
        $subject = $this->createSubject($school);

        return compact('school', 'campus', 'year', 'grade', 'subject');
    }

    private function insertElectiveGroup(
        string $schoolId,
        string $academicYearId,
        string $campusId,
        string $gradeLevelId,
        string $code,
    ): void {
        $this->setSchool($schoolId);

        DB::connection('pgsql')->insert(
            'insert into elective_groups '.
            '(id, school_id, academic_year_id, campus_id, grade_level_id, name, code, created_at, updated_at) '.
            'values (?, ?, ?, ?, ?, ?, ?, now(), now())',
            [(string) Str::orderedUuid(), $schoolId, $academicYearId, $campusId, $gradeLevelId, 'Test Group', $code],
        );
    }

    #[Test]
    public function code_is_unique_within_the_same_academic_context(): void
    {
        ['year' => $year, 'campus' => $campus, 'grade' => $grade] = $this->buildContext();
        $this->createElectiveGroup($year, $campus, $grade, ['code' => 'LANG']);

        $this->expectException(UniqueConstraintViolationException::class);

        $this->createElectiveGroup($year, $campus, $grade, ['code' => 'LANG']);
    }

    #[Test]
    public function code_may_repeat_across_a_different_grade_level_in_the_same_school(): void
    {
        ['school' => $school, 'year' => $year, 'campus' => $campus, 'grade' => $gradeA] = $this->buildContext();
        $gradeB = $this->createGradeLevel($school);

        $this->createElectiveGroup($year, $campus, $gradeA, ['code' => 'LANG']);
        $groupB = $this->createElectiveGroup($year, $campus, $gradeB, ['code' => 'LANG']);

        $this->assertSame('LANG', $groupB->code);
    }

    #[Test]
    public function code_is_normalized_to_uppercase_for_case_insensitive_uniqueness(): void
    {
        ['year' => $year, 'campus' => $campus, 'grade' => $grade] = $this->buildContext();
        $this->createElectiveGroup($year, $campus, $grade, ['code' => 'lang']);

        $this->expectException(UniqueConstraintViolationException::class);

        $this->createElectiveGroup($year, $campus, $grade, ['code' => 'LANG']);
    }

    #[Test]
    public function elective_group_cannot_reference_another_schools_academic_year(): void
    {
        $schoolA = $this->createSchool();
        $campusA = $this->createCampus($schoolA);
        $gradeA = $this->createGradeLevel($schoolA);
        ['year' => $yearB] = $this->buildContext();

        $this->expectException(QueryException::class);

        $this->insertElectiveGroup($schoolA->id, $yearB->id, $campusA->id, $gradeA->id, 'X');
    }

    #[Test]
    public function elective_group_cannot_reference_another_schools_campus(): void
    {
        $schoolA = $this->createSchool();
        $yearA = $this->createAcademicYear($schoolA);
        $gradeA = $this->createGradeLevel($schoolA);
        ['campus' => $campusB] = $this->buildContext();

        $this->expectException(QueryException::class);

        $this->insertElectiveGroup($schoolA->id, $yearA->id, $campusB->id, $gradeA->id, 'X');
    }

    #[Test]
    public function elective_group_cannot_reference_another_schools_grade_level(): void
    {
        $schoolA = $this->createSchool();
        $campusA = $this->createCampus($schoolA);
        $yearA = $this->createAcademicYear($schoolA);
        ['grade' => $gradeB] = $this->buildContext();

        $this->expectException(QueryException::class);

        $this->insertElectiveGroup($schoolA->id, $yearA->id, $campusA->id, $gradeB->id, 'X');
    }

    #[Test]
    public function subject_offering_can_join_a_group_in_the_same_academic_context(): void
    {
        ['year' => $year, 'campus' => $campus, 'grade' => $grade, 'subject' => $subject] = $this->buildContext();
        $group = $this->createElectiveGroup($year, $campus, $grade);

        $offering = $this->createSubjectOffering($year, $campus, $grade, $subject, [
            'is_required' => false,
            'elective_group_id' => $group->id,
        ]);

        $this->assertSame($group->id, $offering->elective_group_id);
    }

    #[Test]
    public function subject_offering_cannot_join_a_group_from_a_foreign_school(): void
    {
        ['year' => $year, 'campus' => $campus, 'grade' => $grade, 'subject' => $subject] = $this->buildContext();
        $schoolB = $this->createSchool();
        $campusB = $this->createCampus($schoolB);
        $yearB = $this->createAcademicYear($schoolB);
        $gradeB = $this->createGradeLevel($schoolB);
        $groupB = $this->createElectiveGroup($yearB, $campusB, $gradeB);

        $this->expectException(QueryException::class);

        $this->createSubjectOffering($year, $campus, $grade, $subject, [
            'is_required' => false,
            'elective_group_id' => $groupB->id,
        ]);
    }

    #[Test]
    public function subject_offering_cannot_join_a_group_from_a_different_academic_year(): void
    {
        ['school' => $school, 'campus' => $campus, 'grade' => $grade, 'subject' => $subject] = $this->buildContext();
        $yearA = $this->createAcademicYear($school, ['code' => 'YEARA']);
        $otherYear = $this->createAcademicYear($school, ['code' => 'YEARB']);
        $group = $this->createElectiveGroup($otherYear, $campus, $grade);

        $this->expectException(QueryException::class);

        $this->createSubjectOffering($yearA, $campus, $grade, $subject, [
            'is_required' => false,
            'elective_group_id' => $group->id,
        ]);
    }

    #[Test]
    public function subject_offering_cannot_join_a_group_from_a_different_campus(): void
    {
        ['school' => $school, 'year' => $year, 'grade' => $grade, 'subject' => $subject] = $this->buildContext();
        $campusA = $this->createCampus($school);
        $otherCampus = $this->createCampus($school);
        $group = $this->createElectiveGroup($year, $otherCampus, $grade);

        $this->expectException(QueryException::class);

        $this->createSubjectOffering($year, $campusA, $grade, $subject, [
            'is_required' => false,
            'elective_group_id' => $group->id,
        ]);
    }

    #[Test]
    public function subject_offering_cannot_join_a_group_from_a_different_grade_level(): void
    {
        ['school' => $school, 'year' => $year, 'campus' => $campus, 'subject' => $subject] = $this->buildContext();
        $gradeA = $this->createGradeLevel($school);
        $otherGrade = $this->createGradeLevel($school);
        $group = $this->createElectiveGroup($year, $campus, $otherGrade);

        $this->expectException(QueryException::class);

        $this->createSubjectOffering($year, $campus, $gradeA, $subject, [
            'is_required' => false,
            'elective_group_id' => $group->id,
        ]);
    }

    #[Test]
    public function a_required_subject_offering_cannot_join_an_elective_group(): void
    {
        ['year' => $year, 'campus' => $campus, 'grade' => $grade, 'subject' => $subject] = $this->buildContext();
        $group = $this->createElectiveGroup($year, $campus, $grade);

        $this->expectException(QueryException::class);

        $this->createSubjectOffering($year, $campus, $grade, $subject, [
            'is_required' => true,
            'elective_group_id' => $group->id,
        ]);
    }

    #[Test]
    public function an_elective_subject_offering_may_remain_ungrouped(): void
    {
        ['year' => $year, 'campus' => $campus, 'grade' => $grade, 'subject' => $subject] = $this->buildContext();

        $offering = $this->createSubjectOffering($year, $campus, $grade, $subject, [
            'is_required' => false,
            'elective_group_id' => null,
        ]);

        $this->assertNull($offering->elective_group_id);
    }

    #[Test]
    public function a_required_subject_offering_with_no_group_is_unaffected(): void
    {
        ['year' => $year, 'campus' => $campus, 'grade' => $grade, 'subject' => $subject] = $this->buildContext();

        $offering = $this->createSubjectOffering($year, $campus, $grade, $subject, [
            'is_required' => true,
            'elective_group_id' => null,
        ]);

        $this->assertTrue($offering->is_required);
        $this->assertNull($offering->elective_group_id);
    }
}

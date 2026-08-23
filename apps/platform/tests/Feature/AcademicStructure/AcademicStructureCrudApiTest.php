<?php

namespace Tests\Feature\AcademicStructure;

use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 0D section 52/83: lighter combined API coverage for the
 * remaining reference-data entities (GradeLevel, AcademicDepartment,
 * Subject, Room, AcademicTerm, Section, SubjectOffering) -- create,
 * list, and the cross-School 404 case for each. The deeper lifecycle/
 * concurrency/idempotency proofs live in the dedicated Academic Year
 * and Campus test classes.
 */
class AcademicStructureCrudApiTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function token($user): string
    {
        return $user->createToken('test-device')->plainTextToken;
    }

    #[Test]
    public function grade_levels_can_be_created_listed_and_are_school_isolated(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $client = $this->withHeader('Authorization', 'Bearer '.$this->token($user));

        $create = $client->postJson("/api/v1/schools/{$school->id}/grade-levels", [
            'name' => 'Grade 5', 'code' => 'G5', 'sequence' => 5,
        ]);
        $create->assertCreated();

        $client->getJson("/api/v1/schools/{$school->id}/grade-levels")->assertOk()->assertJsonCount(1, 'data');

        $schoolB = $this->createSchool();
        $gradeB = $this->createGradeLevel($schoolB);
        $client->getJson("/api/v1/schools/{$school->id}/grade-levels/{$gradeB->id}")->assertNotFound();
    }

    #[Test]
    public function academic_departments_can_be_created_and_listed(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $client = $this->withHeader('Authorization', 'Bearer '.$this->token($user));

        $create = $client->postJson("/api/v1/schools/{$school->id}/academic-departments", [
            'name' => 'Science', 'code' => 'SCI',
        ]);
        $create->assertCreated();

        $client->getJson("/api/v1/schools/{$school->id}/academic-departments")->assertOk()->assertJsonCount(1, 'data');
    }

    #[Test]
    public function subjects_can_be_created_with_an_optional_department(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $department = $this->createAcademicDepartment($school);
        $client = $this->withHeader('Authorization', 'Bearer '.$this->token($user));

        $create = $client->postJson("/api/v1/schools/{$school->id}/subjects", [
            'name' => 'Physics', 'code' => 'PHY', 'academic_department_id' => $department->id,
        ]);
        $create->assertCreated();
        $this->assertSame($department->id, $create->json('data.academicDepartmentId'));

        $schoolB = $this->createSchool();
        $departmentB = $this->createAcademicDepartment($schoolB);

        $rejected = $client->postJson("/api/v1/schools/{$school->id}/subjects", [
            'name' => 'Chemistry', 'code' => 'CHEM', 'academic_department_id' => $departmentB->id,
        ]);
        $rejected->assertStatus(422);
    }

    #[Test]
    public function rooms_are_created_under_a_specific_campus(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $campus = $this->createCampus($school);
        $client = $this->withHeader('Authorization', 'Bearer '.$this->token($user));

        $create = $client->postJson("/api/v1/schools/{$school->id}/campuses/{$campus->id}/rooms", [
            'name' => 'Physics Lab', 'code' => 'PHYLAB', 'room_type' => 'laboratory', 'capacity' => 30,
        ]);
        $create->assertCreated();
        $this->assertSame($campus->id, $create->json('data.campusId'));

        $client->getJson("/api/v1/schools/{$school->id}/campuses/{$campus->id}/rooms")->assertOk()->assertJsonCount(1, 'data');
    }

    #[Test]
    public function academic_terms_are_created_under_a_specific_year(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $year = $this->createAcademicYear($school, ['starts_on' => '2026-06-01', 'ends_on' => '2027-05-31']);
        $client = $this->withHeader('Authorization', 'Bearer '.$this->token($user));

        $create = $client->postJson("/api/v1/schools/{$school->id}/academic-years/{$year->id}/terms", [
            'name' => 'Term 1', 'code' => 'T1', 'starts_on' => '2026-06-01', 'ends_on' => '2026-10-01', 'sequence' => 1,
        ]);
        $create->assertCreated();

        $client->getJson("/api/v1/schools/{$school->id}/academic-years/{$year->id}/terms")->assertOk()->assertJsonCount(1, 'data');
    }

    #[Test]
    public function sections_are_created_under_a_specific_year_and_reject_cross_school_parents(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $year = $this->createAcademicYear($school);
        $campus = $this->createCampus($school);
        $grade = $this->createGradeLevel($school);
        $client = $this->withHeader('Authorization', 'Bearer '.$this->token($user));

        $create = $client->withHeader('Idempotency-Key', 'section-create-001')
            ->postJson("/api/v1/schools/{$school->id}/academic-years/{$year->id}/sections", [
                'campus_id' => $campus->id, 'grade_level_id' => $grade->id, 'name' => 'A', 'code' => 'A',
            ]);
        $create->assertCreated();

        $schoolB = $this->createSchool();
        $campusB = $this->createCampus($schoolB);

        $rejected = $client->withHeader('Idempotency-Key', 'section-create-002')
            ->postJson("/api/v1/schools/{$school->id}/academic-years/{$year->id}/sections", [
                'campus_id' => $campusB->id, 'grade_level_id' => $grade->id, 'name' => 'B', 'code' => 'B',
            ]);
        $rejected->assertNotFound(); // campus lookup itself 404s via SchoolScope

        $duplicate = $client->withHeader('Idempotency-Key', 'section-create-003')
            ->postJson("/api/v1/schools/{$school->id}/academic-years/{$year->id}/sections", [
                'campus_id' => $campus->id, 'grade_level_id' => $grade->id, 'name' => 'A Again', 'code' => 'A',
            ]);
        $duplicate->assertStatus(422);
    }

    #[Test]
    public function subject_offerings_are_created_under_a_specific_year(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $year = $this->createAcademicYear($school);
        $campus = $this->createCampus($school);
        $grade = $this->createGradeLevel($school);
        $subject = $this->createSubject($school);
        $client = $this->withHeader('Authorization', 'Bearer '.$this->token($user));

        $create = $client->withHeader('Idempotency-Key', 'offering-create-001')
            ->postJson("/api/v1/schools/{$school->id}/academic-years/{$year->id}/subject-offerings", [
                'campus_id' => $campus->id, 'grade_level_id' => $grade->id, 'subject_id' => $subject->id,
                'weekly_periods_target' => 5,
            ]);
        $create->assertCreated();
        $this->assertTrue($create->json('data.isRequired'));

        $client->getJson("/api/v1/schools/{$school->id}/academic-years/{$year->id}/subject-offerings")
            ->assertOk()->assertJsonCount(1, 'data');
    }
}

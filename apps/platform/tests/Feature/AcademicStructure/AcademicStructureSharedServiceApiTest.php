<?php

namespace Tests\Feature\AcademicStructure;

use App\Domain\AcademicStructure\Application\Concerns\AdministersReferenceData;
use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use App\Models\Campus;
use App\Models\School;
use App\Models\SchoolAuditEvent;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * SWC.2 (ADR 0073 sections 4.2, 23): the `/api/v1` Academic Structure
 * writes now run through shared Application services. Their contract is
 * unchanged -- routes, capabilities, validation, status codes, bodies,
 * audit actions, tenancy -- except for the defects the extraction fixed, each
 * pinned here: a duplicate Section code on PATCH and a duplicate Subject
 * Offering on POST were 500s and are now 422 field errors, and a duplicate
 * that races past validation is the same 422, never a 500.
 */
class AcademicStructureSharedServiceApiTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function api(User $user, School $school): static
    {
        return $this->actingAs($user)->withHeader('X-School-Id', $school->id);
    }

    /** @return array{0: User, 1: School} */
    private function admin(): array
    {
        return $this->createSchoolAdmin('school_admin');
    }

    private function audits(School $school, string $type): int
    {
        return (int) app(TenantContext::class)->withSchool($school, fn () => SchoolAuditEvent::query()->where('event_type', $type)->count());
    }

    #[Test]
    public function every_patch_keeps_its_response_shape_and_audit_action(): void
    {
        [$user, $school] = $this->admin();
        $base = "/api/v1/schools/{$school->id}";
        $campus = $this->createCampus($school, ['code' => 'MAIN']);
        $year = $this->createAcademicYear($school);
        $grade = $this->createGradeLevel($school);
        $department = $this->createAcademicDepartment($school);
        $subject = $this->createSubject($school);
        $room = $this->createRoom($campus);
        $section = $this->createSection($year, $campus, $grade);
        $term = $this->createAcademicTerm($year);

        $this->api($user, $school)->patchJson("{$base}/campuses/{$campus->id}", ['name' => 'Renamed', 'phone' => '123', 'status' => 'inactive'])
            ->assertOk()->assertJsonPath('data.name', 'Renamed')->assertJsonPath('data.status', 'inactive')
            ->assertJsonStructure(['data' => ['id', 'name', 'code', 'status', 'phone', 'email', 'address']]);
        $this->api($user, $school)->patchJson("{$base}/grade-levels/{$grade->id}", ['education_stage' => 'primary', 'status' => 'inactive'])
            ->assertOk()->assertJsonPath('data.status', 'inactive');
        $this->api($user, $school)->patchJson("{$base}/academic-departments/{$department->id}", ['code' => 'sci'])
            ->assertOk()->assertJsonPath('data.code', 'SCI');
        $this->api($user, $school)->patchJson("{$base}/subjects/{$subject->id}", ['short_name' => 'Ma', 'academic_department_id' => $department->id])
            ->assertOk()->assertJsonPath('data.academicDepartmentId', $department->id);
        $this->api($user, $school)->patchJson("{$base}/rooms/{$room->id}", ['capacity' => 30, 'status' => 'inactive'])
            ->assertOk()->assertJsonPath('data.capacity', 30);
        $this->api($user, $school)->patchJson("{$base}/sections/{$section->id}", ['capacity' => 40])
            ->assertOk()->assertJsonPath('data.capacity', 40);
        $this->api($user, $school)->patchJson("{$base}/academic-terms/{$term->id}", ['name' => 'Term One'])
            ->assertOk()->assertJsonPath('data.name', 'Term One');
        $this->api($user, $school)->patchJson("{$base}/academic-years/{$year->id}", ['code' => 'ay-x'])
            ->assertOk()->assertJsonPath('data.code', 'AY-X');

        foreach (['campus', 'grade_level', 'academic_department', 'subject', 'room', 'section', 'academic_term', 'academic_year'] as $entity) {
            $this->assertSame(1, $this->audits($school, "{$entity}.updated"), $entity);
        }
    }

    #[Test]
    public function validation_codes_and_statuses_are_unchanged(): void
    {
        [$user, $school] = $this->admin();
        $base = "/api/v1/schools/{$school->id}";
        $this->createCampus($school, ['code' => 'MAIN']);

        $this->api($user, $school)->withHeader('Idempotency-Key', 'swc2-campus-dup-1')->postJson("{$base}/campuses", ['name' => 'Dup', 'code' => ' main '])->assertStatus(422)->assertJsonStructure(['error' => ['errors' => ['code']]]);
        $this->api($user, $school)->withHeader('Idempotency-Key', 'swc2-campus-noname')->postJson("{$base}/campuses", ['code' => 'X'])->assertStatus(422)->assertJsonStructure(['error' => ['errors' => ['name']]]);
        $this->api($user, $school)->postJson("{$base}/subjects", ['name' => 'S', 'code' => 'S', 'subject_type' => 'nonsense'])->assertStatus(422)->assertJsonStructure(['error' => ['errors' => ['subject_type']]]);

        $viewer = $this->createUserWithCapabilities($school, ['school.campuses.view', 'academics.structure.view']);
        $this->api($viewer, $school)->withHeader('Idempotency-Key', 'swc2-campus-viewer')->postJson("{$base}/campuses", ['name' => 'X', 'code' => 'Y'])->assertForbidden();
        $this->api($viewer, $school)->postJson("{$base}/academic-departments", ['name' => 'X', 'code' => 'Y'])->assertForbidden();
    }

    #[Test]
    public function a_duplicate_section_code_on_patch_is_a_422_not_a_500(): void
    {
        [$user, $school] = $this->admin();
        $campus = $this->createCampus($school);
        $year = $this->createAcademicYear($school);
        $grade = $this->createGradeLevel($school);
        $this->createSection($year, $campus, $grade, ['code' => 'A']);
        $other = $this->createSection($year, $campus, $grade, ['code' => 'B']);

        // The old failure: the database alone refuses the duplicate (the PATCH used to reach it unchecked).
        $this->assertThrows(fn () => app(TenantContext::class)->withSchool($school, fn () => DB::transaction(fn () => Section::query()->whereKey($other->id)->update(['code' => 'A']))), UniqueConstraintViolationException::class);

        $this->api($user, $school)->patchJson("/api/v1/schools/{$school->id}/sections/{$other->id}", ['code' => ' a '])
            ->assertStatus(422)->assertJsonStructure(['error' => ['errors' => ['code']]]);
        $this->api($user, $school)->patchJson("/api/v1/schools/{$school->id}/sections/{$other->id}", ['code' => 'b'])
            ->assertOk()->assertJsonPath('data.code', 'B');
    }

    #[Test]
    public function a_duplicate_subject_offering_is_a_422_not_a_500(): void
    {
        [$user, $school] = $this->admin();
        $campus = $this->createCampus($school);
        $year = $this->createAcademicYear($school);
        $grade = $this->createGradeLevel($school);
        $subject = $this->createSubject($school);
        $payload = ['campus_id' => $campus->id, 'grade_level_id' => $grade->id, 'subject_id' => $subject->id];
        $url = "/api/v1/schools/{$school->id}/academic-years/{$year->id}/subject-offerings";

        $this->api($user, $school)->withHeader('Idempotency-Key', 'dup-offer-001')->postJson($url, $payload)->assertCreated()->assertJsonPath('data.isRequired', true);
        $this->api($user, $school)->withHeader('Idempotency-Key', 'dup-offer-002')->postJson($url, $payload)->assertStatus(422)->assertJsonStructure(['error' => ['errors' => ['subject_id']]]);
        $this->assertSame(1, app(TenantContext::class)->withSchool($school, fn () => SubjectOffering::query()->count()));
    }

    #[Test]
    public function a_unique_violation_that_raced_past_validation_becomes_the_field_error(): void
    {
        // The mechanism every shared write runs through (AdministersReferenceData::writeUnique()):
        // a real unique-constraint violation is re-validated once the competing row is visible.
        $service = new class
        {
            use AdministersReferenceData;

            public function run(\Closure $write, \Closure $revalidate): mixed
            {
                return $this->writeUnique($write, $revalidate);
            }
        };
        [, $school] = $this->admin();
        $this->createCampus($school, ['code' => 'RACE']);
        $collide = fn () => app(TenantContext::class)->withSchool($school, fn () => DB::transaction(fn () => Campus::query()->create(['school_id' => $school->id, 'name' => 'Late', 'code' => 'race'])));

        $this->assertThrows(fn () => $service->run($collide, fn () => throw ValidationException::withMessages(['code' => ['The code has already been taken.']])), ValidationException::class);
        // A violation the re-validation cannot explain is never swallowed.
        $this->assertThrows(fn () => $service->run($collide, fn () => null), UniqueConstraintViolationException::class);
    }

    #[Test]
    public function every_shared_write_runs_through_the_race_translation(): void
    {
        foreach ([
            'Domain/Campuses/Application/CampusAdministrationService.php' => 2,
            'Domain/AcademicStructure/Application/GradeLevelAdministrationService.php' => 2,
            'Domain/AcademicStructure/Application/SubjectAdministrationService.php' => 2,
            'Domain/AcademicStructure/Application/AcademicDepartmentAdministrationService.php' => 2,
            'Domain/AcademicStructure/Application/RoomAdministrationService.php' => 2,
            'Domain/AcademicStructure/Application/SectionAdministrationService.php' => 2,
            'Domain/AcademicStructure/Application/AcademicYearService.php' => 2,
            'Domain/AcademicStructure/Application/AcademicTermService.php' => 2,
            'Domain/AcademicStructure/Application/SubjectOfferingService.php' => 1,
        ] as $file => $writes) {
            $this->assertSame($writes, substr_count((string) file_get_contents(app_path($file)), '$this->writeUnique('), $file);
        }
    }

    #[Test]
    public function another_schools_rows_and_parents_stay_404(): void
    {
        [$user, $school] = $this->admin();
        $other = $this->createSchool();
        $foreignCampus = $this->createCampus($other);
        $foreignYear = $this->createAcademicYear($other);
        $grade = $this->createGradeLevel($school);
        $year = $this->createAcademicYear($school);

        $this->api($user, $school)->patchJson("/api/v1/schools/{$school->id}/campuses/{$foreignCampus->id}", ['name' => 'X'])->assertNotFound();
        $this->api($user, $school)->postJson("/api/v1/schools/{$school->id}/campuses/{$foreignCampus->id}/rooms", ['name' => 'R', 'code' => 'R'])->assertNotFound();
        $this->api($user, $school)->withHeader('Idempotency-Key', 'dup-sec-001')->postJson("/api/v1/schools/{$school->id}/academic-years/{$foreignYear->id}/sections", ['campus_id' => $foreignCampus->id, 'grade_level_id' => $grade->id, 'name' => 'S', 'code' => 'S'])->assertNotFound();
        $this->api($user, $school)->withHeader('Idempotency-Key', 'dup-sec-002')->postJson("/api/v1/schools/{$school->id}/academic-years/{$year->id}/sections", ['campus_id' => $foreignCampus->id, 'grade_level_id' => $grade->id, 'name' => 'S', 'code' => 'S'])->assertNotFound();
    }
}

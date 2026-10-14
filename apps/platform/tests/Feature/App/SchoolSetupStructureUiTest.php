<?php

namespace Tests\Feature\App;

use App\Domain\AcademicStructure\Infrastructure\AcademicTerm;
use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\GradeLevel;
use App\Domain\AcademicStructure\Infrastructure\Room;
use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\AcademicStructure\Infrastructure\Subject;
use App\Models\Campus;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * SWC.2 (ADR 0073 section 10): the School Setup web surface is complete --
 * Campuses, Grade levels and Subjects gain edit and deactivate, and Terms,
 * Sections, Academic departments and Rooms get their screens. Every write
 * goes through the same Application service as `/api/v1`; the four Phase 0D
 * web defects (case-variant duplicate codes, an unrendered Academic Year
 * conflict) are now form errors, never a 500 and never a partial write.
 */
class SchoolSetupStructureUiTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function activate(User $user, School $school): void
    {
        $this->actingAs($user)->post("/app/schools/{$school->id}/activate");
    }

    private function memberWith(array $capabilities, School $school): User
    {
        $user = $this->createUserWithCapabilities($school, $capabilities);
        $this->activate($user, $school);

        return $user;
    }

    /** @return array{0: User, 1: School} */
    private function admin(): array
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $this->activate($user, $school);

        return [$user, $school];
    }

    private function inSchool(School $school, \Closure $fn): mixed
    {
        return app(TenantContext::class)->withSchool($school, $fn);
    }

    // --- Defect regressions (ADR 0073 section 2.5) -------------------------

    #[Test]
    public function a_case_variant_duplicate_campus_code_is_a_form_error_not_a_500(): void
    {
        [$user, $school] = $this->admin();
        $this->createCampus($school, ['code' => 'MAIN']);

        // The old failure: the web action created without a uniqueness check, so
        // the database constraint (the stored, normalized code) threw.
        $this->assertThrows(fn () => $this->inSchool($school, fn () => DB::transaction(fn () => Campus::query()->create(['school_id' => $school->id, 'name' => 'X', 'code' => 'main']))), UniqueConstraintViolationException::class);

        $this->actingAs($user)->post('/app/school-setup/campuses', ['name' => 'Second', 'code' => ' main '])
            ->assertRedirect()->assertSessionHasErrors('code');
        $this->assertSame(1, $this->inSchool($school, fn () => Campus::query()->count()));
    }

    #[Test]
    public function a_case_variant_duplicate_grade_level_code_or_order_is_a_form_error(): void
    {
        [$user, $school] = $this->admin();
        $this->createGradeLevel($school, ['code' => 'G5', 'sequence' => 5]);

        $this->assertThrows(fn () => $this->inSchool($school, fn () => DB::transaction(fn () => GradeLevel::query()->create(['school_id' => $school->id, 'name' => 'X', 'code' => 'g5', 'sequence' => 9]))), UniqueConstraintViolationException::class);

        $this->actingAs($user)->post('/app/school-setup/grade-levels', ['name' => 'Grade 5 again', 'code' => 'g5', 'sequence' => 6])->assertSessionHasErrors('code');
        $this->actingAs($user)->post('/app/school-setup/grade-levels', ['name' => 'Other', 'code' => 'G6', 'sequence' => 5])->assertSessionHasErrors('sequence');
        $this->assertSame(1, $this->inSchool($school, fn () => GradeLevel::query()->count()));
    }

    #[Test]
    public function a_case_variant_duplicate_subject_code_is_a_form_error(): void
    {
        [$user, $school] = $this->admin();
        $this->createSubject($school, ['code' => 'MATH']);

        $this->assertThrows(fn () => $this->inSchool($school, fn () => DB::transaction(fn () => Subject::query()->create(['school_id' => $school->id, 'name' => 'X', 'code' => 'math']))), UniqueConstraintViolationException::class);

        $this->actingAs($user)->post('/app/school-setup/subjects', ['name' => 'Maths', 'code' => 'Math'])->assertSessionHasErrors('code');
        $this->assertSame(1, $this->inSchool($school, fn () => Subject::query()->count()));
    }

    #[Test]
    public function an_overlapping_academic_year_is_a_form_error_and_writes_nothing(): void
    {
        [$user, $school] = $this->admin();
        $this->createAcademicYear($school, ['code' => 'AY1', 'starts_on' => '2026-04-01', 'ends_on' => '2027-03-31']);

        $response = $this->actingAs($user)->post('/app/school-setup/academic-years', ['name' => 'Overlap', 'code' => 'AY2', 'starts_on' => '2026-06-01', 'ends_on' => '2027-05-31']);
        $this->assertNotSame(500, $response->getStatusCode());
        $response->assertRedirect()->assertSessionHasErrors(['starts_on' => 'This date range overlaps an existing Academic Year for this School.']);

        $this->actingAs($user)->post('/app/school-setup/academic-years', ['name' => 'Dup code', 'code' => 'ay1', 'starts_on' => '2028-04-01', 'ends_on' => '2029-03-31'])->assertSessionHasErrors('code');
        $this->assertSame(1, $this->inSchool($school, fn () => AcademicYear::query()->count()));
    }

    #[Test]
    public function a_stale_year_transition_is_a_visible_refusal(): void
    {
        [$user, $school] = $this->admin();
        $closed = $this->createAcademicYear($school, ['status' => 'closed']);

        $this->actingAs($user)->post("/app/school-setup/academic-years/{$closed->id}/activate")->assertSessionHasErrors('academic_year');
        $this->actingAs($user)->post("/app/school-setup/academic-years/{$closed->id}/close")->assertSessionHasErrors('academic_year');
        $this->assertSame('closed', $this->inSchool($school, fn () => AcademicYear::query()->findOrFail($closed->id)->status));
    }

    // --- Existing screens completed --------------------------------------

    #[Test]
    public function campus_grade_and_subject_can_be_edited_deactivated_and_reactivated(): void
    {
        [$user, $school] = $this->admin();
        $campus = $this->createCampus($school, ['code' => 'MAIN']);
        $grade = $this->createGradeLevel($school, ['code' => 'G1', 'sequence' => 1]);
        $department = $this->createAcademicDepartment($school);
        $subject = $this->createSubject($school, ['code' => 'ENG']);

        $this->actingAs($user)->put("/app/school-setup/campuses/{$campus->id}", ['name' => 'Main Campus', 'code' => 'main', 'email' => 'office@example.test'])->assertRedirect('/app/school-setup/campuses');
        $this->actingAs($user)->put("/app/school-setup/campuses/{$campus->id}", ['status' => 'inactive'])->assertSessionHasNoErrors();
        $this->actingAs($user)->put("/app/school-setup/grade-levels/{$grade->id}", ['name' => 'Grade One', 'education_stage' => 'primary'])->assertSessionHasNoErrors();
        $this->actingAs($user)->put("/app/school-setup/grade-levels/{$grade->id}", ['status' => 'inactive'])->assertSessionHasNoErrors();
        $this->actingAs($user)->put("/app/school-setup/subjects/{$subject->id}", ['short_name' => 'En', 'subject_type' => 'language', 'academic_department_id' => $department->id])->assertSessionHasNoErrors();
        $this->actingAs($user)->put("/app/school-setup/subjects/{$subject->id}", ['status' => 'inactive'])->assertSessionHasNoErrors();
        $this->actingAs($user)->put("/app/school-setup/subjects/{$subject->id}", ['status' => 'inactive'])->assertSessionHasNoErrors(); // stale repeat converges

        $this->inSchool($school, function () use ($campus, $grade, $subject, $department): void {
            $this->assertSame(['Main Campus', 'MAIN', 'office@example.test', 'inactive'], array_values(Campus::query()->findOrFail($campus->id)->only(['name', 'code', 'email', 'status'])));
            $this->assertSame(['Grade One', 'primary', 'inactive'], array_values(GradeLevel::query()->findOrFail($grade->id)->only(['name', 'education_stage', 'status'])));
            $this->assertSame(['language', $department->id, 'inactive'], array_values(Subject::query()->findOrFail($subject->id)->only(['subject_type', 'academic_department_id', 'status'])));
        });

        $this->actingAs($user)->put("/app/school-setup/campuses/{$campus->id}", ['status' => 'active'])->assertSessionHasNoErrors();
        $this->assertSame('active', $this->inSchool($school, fn () => Campus::query()->findOrFail($campus->id)->status));
        $this->actingAs($user)->put("/app/school-setup/campuses/{$campus->id}", ['status' => 'deleted'])->assertSessionHasErrors('status');
    }

    #[Test]
    public function the_pages_render_their_rows_and_manage_flags(): void
    {
        [$user, $school] = $this->admin();
        $campus = $this->createCampus($school);
        $year = $this->createAcademicYear($school, ['status' => 'active']);
        $grade = $this->createGradeLevel($school);
        $this->createAcademicDepartment($school, ['name' => 'Sciences']);
        $this->createRoom($campus, ['name' => 'Lab 1']);
        $this->createSection($year, $campus, $grade, ['name' => 'Grade 1 A']);
        $this->createAcademicTerm($year, ['name' => 'Term 1']);

        $this->actingAs($user)->get('/app/school-setup/terms')->assertOk()->assertInertia(fn ($page) => $page
            ->component('App/SchoolSetup/Terms')->where('selectedYearId', $year->id)->where('terms.0.name', 'Term 1')->where('canManage', true));
        $this->actingAs($user)->get('/app/school-setup/sections')->assertOk()->assertInertia(fn ($page) => $page
            ->component('App/SchoolSetup/Sections')->where('sections.data.0.name', 'Grade 1 A')->where('sections.data.0.campus.id', $campus->id)->has('gradeLevels', 1));
        $this->actingAs($user)->get('/app/school-setup/departments')->assertOk()->assertInertia(fn ($page) => $page
            ->component('App/SchoolSetup/Departments')->where('departments.0.name', 'Sciences'));
        $this->actingAs($user)->get("/app/school-setup/rooms?campus_id={$campus->id}")->assertOk()->assertInertia(fn ($page) => $page
            ->component('App/SchoolSetup/Rooms')->where('selectedCampusId', $campus->id)->where('rooms.0.name', 'Lab 1'));
        $this->actingAs($user)->get('/app/school-setup')->assertOk()->assertInertia(fn ($page) => $page
            ->where('can', ['viewYears' => true, 'viewStructure' => true, 'viewSubjects' => true]));
    }

    // --- New screens -----------------------------------------------------

    #[Test]
    public function terms_are_created_and_renamed_and_the_services_refusals_are_form_errors(): void
    {
        [$user, $school] = $this->admin();
        $year = $this->createAcademicYear($school, ['starts_on' => '2026-04-01', 'ends_on' => '2027-03-31']);

        $this->actingAs($user)->post("/app/school-setup/academic-years/{$year->id}/terms", ['name' => 'Term 1', 'code' => 't1', 'sequence' => 1, 'starts_on' => '2026-04-01', 'ends_on' => '2026-09-30'])
            ->assertRedirect(route('app.school-setup.terms', ['academic_year_id' => $year->id]))->assertSessionHasNoErrors();
        $this->actingAs($user)->post("/app/school-setup/academic-years/{$year->id}/terms", ['name' => 'Overlap', 'code' => 'T2', 'sequence' => 2, 'starts_on' => '2026-09-01', 'ends_on' => '2026-12-31'])
            ->assertSessionHasErrors(['starts_on' => 'This date range overlaps an existing Term in the same Academic Year.']);
        $this->actingAs($user)->post("/app/school-setup/academic-years/{$year->id}/terms", ['name' => 'Outside', 'code' => 'T3', 'sequence' => 3, 'starts_on' => '2027-03-01', 'ends_on' => '2027-06-30'])
            ->assertSessionHasErrors('starts_on');
        $this->actingAs($user)->post("/app/school-setup/academic-years/{$year->id}/terms", ['name' => 'Dup', 'code' => 'T1', 'sequence' => 4, 'starts_on' => '2026-10-01', 'ends_on' => '2026-12-31'])
            ->assertSessionHasErrors('code');

        $term = $this->inSchool($school, fn () => AcademicTerm::query()->firstOrFail());
        $this->actingAs($user)->put("/app/school-setup/terms/{$term->id}", ['name' => 'First term', 'starts_on' => '2026-05-01'])->assertSessionHasNoErrors();
        $fresh = $this->inSchool($school, fn () => AcademicTerm::query()->findOrFail($term->id));
        $this->assertSame(['First term', 'T1', '2026-04-01'], [$fresh->name, $fresh->code, $fresh->starts_on->toDateString()], 'renaming never moves dates');
        $this->assertSame(1, $this->inSchool($school, fn () => AcademicTerm::query()->count()));
    }

    #[Test]
    public function a_school_admin_creates_the_section_enrollment_and_the_timetable_need(): void
    {
        [$user, $school] = $this->admin();
        $campus = $this->createCampus($school);
        $year = $this->createAcademicYear($school, ['status' => 'active']);
        $grade = $this->createGradeLevel($school);

        $this->actingAs($user)->post("/app/school-setup/academic-years/{$year->id}/sections", ['campus_id' => $campus->id, 'grade_level_id' => $grade->id, 'name' => 'Grade 5 A', 'code' => 'a', 'capacity' => 40])
            ->assertRedirect(route('app.school-setup.sections', ['academic_year_id' => $year->id]))->assertSessionHasNoErrors();
        $this->actingAs($user)->post("/app/school-setup/academic-years/{$year->id}/sections", ['campus_id' => $campus->id, 'grade_level_id' => $grade->id, 'name' => 'Again', 'code' => 'A'])
            ->assertSessionHasErrors('code');

        $section = $this->inSchool($school, fn () => Section::query()->firstOrFail());
        $this->assertSame(['A', $year->id, 40], [$section->code, $section->academic_year_id, $section->capacity]);

        // The Section is now the one Enrollment and the Timetable select.
        $student = $this->createStudent($school);
        $this->actingAs($user)->post("/app/students/{$student->id}/enrollments", ['section_id' => $section->id, 'roll_number' => '1', 'starts_on' => $year->starts_on->toDateString()])
            ->assertSessionHasNoErrors();
        $this->assertSame(1, $this->inSchool($school, fn () => DB::table('student_enrollments')->where('section_id', $section->id)->count()));

        $this->actingAs($user)->put("/app/school-setup/sections/{$section->id}", ['name' => 'Grade 5 Alpha', 'status' => 'inactive'])->assertSessionHasNoErrors();
        $this->assertSame(['Grade 5 Alpha', 'inactive'], array_values($this->inSchool($school, fn () => Section::query()->findOrFail($section->id)->only(['name', 'status']))));
    }

    #[Test]
    public function departments_and_rooms_are_managed_with_unique_codes(): void
    {
        [$user, $school] = $this->admin();
        $campus = $this->createCampus($school);
        $otherCampus = $this->createCampus($school);

        $this->actingAs($user)->post('/app/school-setup/departments', ['name' => 'Sciences', 'code' => 'sci'])->assertSessionHasNoErrors();
        $this->actingAs($user)->post('/app/school-setup/departments', ['name' => 'Sciences 2', 'code' => 'SCI'])->assertSessionHasErrors('code');

        $this->actingAs($user)->post("/app/school-setup/campuses/{$campus->id}/rooms", ['name' => 'Lab', 'code' => 'lab1', 'room_type' => 'laboratory', 'capacity' => 30])
            ->assertRedirect(route('app.school-setup.rooms', ['campus_id' => $campus->id]))->assertSessionHasNoErrors();
        $this->actingAs($user)->post("/app/school-setup/campuses/{$campus->id}/rooms", ['name' => 'Lab again', 'code' => 'LAB1'])->assertSessionHasErrors('code');
        // Unique per Campus: the same code on another Campus is fine.
        $this->actingAs($user)->post("/app/school-setup/campuses/{$otherCampus->id}/rooms", ['name' => 'Lab', 'code' => 'LAB1'])->assertSessionHasNoErrors();
        $this->actingAs($user)->post("/app/school-setup/campuses/{$campus->id}/rooms", ['name' => 'X', 'code' => 'X', 'room_type' => 'kitchen'])->assertSessionHasErrors('room_type');

        $room = $this->inSchool($school, fn () => Room::query()->where('campus_id', $campus->id)->firstOrFail());
        $this->actingAs($user)->put("/app/school-setup/rooms/{$room->id}", ['status' => 'inactive'])->assertSessionHasNoErrors();
        $this->assertSame('inactive', $this->inSchool($school, fn () => Room::query()->findOrFail($room->id)->status));
    }

    // --- Authorization and tenancy ----------------------------------------

    #[Test]
    public function viewers_see_the_pages_but_cannot_change_anything(): void
    {
        $school = $this->createSchool();
        $campus = $this->createCampus($school);
        $year = $this->createAcademicYear($school);
        $grade = $this->createGradeLevel($school);
        $viewer = $this->memberWith(['school.campuses.view', 'academics.years.view', 'academics.structure.view', 'academics.subjects.view'], $school);

        foreach (['/app/school-setup/terms', '/app/school-setup/sections', '/app/school-setup/departments', '/app/school-setup/rooms', '/app/school-setup/campuses'] as $page) {
            $this->actingAs($viewer)->get($page)->assertOk()->assertInertia(fn ($p) => $p->where('canManage', false));
        }

        $this->actingAs($viewer)->put("/app/school-setup/campuses/{$campus->id}", ['status' => 'inactive'])->assertForbidden();
        $this->actingAs($viewer)->post("/app/school-setup/academic-years/{$year->id}/terms", ['name' => 'T', 'code' => 'T', 'sequence' => 1, 'starts_on' => '2026-04-01', 'ends_on' => '2026-05-01'])->assertForbidden();
        $this->actingAs($viewer)->post("/app/school-setup/academic-years/{$year->id}/sections", ['campus_id' => $campus->id, 'grade_level_id' => $grade->id, 'name' => 'S', 'code' => 'S'])->assertForbidden();
        $this->actingAs($viewer)->post('/app/school-setup/departments', ['name' => 'D', 'code' => 'D'])->assertForbidden();
        $this->actingAs($viewer)->post("/app/school-setup/campuses/{$campus->id}/rooms", ['name' => 'R', 'code' => 'R'])->assertForbidden();
        $this->actingAs($viewer)->put("/app/school-setup/grade-levels/{$grade->id}", ['status' => 'inactive'])->assertForbidden();
        $this->assertSame('active', $this->inSchool($school, fn () => Campus::query()->findOrFail($campus->id)->status));

        $nobody = $this->memberWith([], $school);
        foreach (['/app/school-setup/terms', '/app/school-setup/sections', '/app/school-setup/departments', '/app/school-setup/rooms'] as $page) {
            $this->actingAs($nobody)->get($page)->assertForbidden();
        }
    }

    #[Test]
    public function without_a_selected_school_nothing_opens_or_changes(): void
    {
        $school = $this->createSchool();
        $campus = $this->createCampus($school);
        $user = $this->createUserWithCapabilities($school, ['school.campuses.view', 'school.campuses.manage', 'academics.structure.view', 'academics.structure.manage']);

        $this->actingAs($user)->get('/app/school-setup/sections')->assertRedirect('/app');
        $this->actingAs($user)->put("/app/school-setup/campuses/{$campus->id}", ['status' => 'inactive'], ['X-Inertia' => 'true'])->assertStatus(409);
        $this->assertSame('active', $this->inSchool($school, fn () => Campus::query()->findOrFail($campus->id)->status));
    }

    #[Test]
    public function another_schools_rows_and_parents_are_not_found(): void
    {
        [$user, $school] = $this->admin();
        $other = $this->createSchool();
        $foreignCampus = $this->createCampus($other);
        $foreignYear = $this->createAcademicYear($other);
        $foreignGrade = $this->createGradeLevel($other);
        $foreignSubject = $this->createSubject($other);
        $foreignDepartment = $this->createAcademicDepartment($other);
        $foreignRoom = $this->createRoom($foreignCampus);
        $foreignSection = $this->createSection($foreignYear, $foreignCampus, $foreignGrade);
        $foreignTerm = $this->createAcademicTerm($foreignYear);
        $year = $this->createAcademicYear($school);
        $grade = $this->createGradeLevel($school);
        $campus = $this->createCampus($school);

        foreach ([
            "/app/school-setup/campuses/{$foreignCampus->id}", "/app/school-setup/grade-levels/{$foreignGrade->id}",
            "/app/school-setup/subjects/{$foreignSubject->id}", "/app/school-setup/departments/{$foreignDepartment->id}",
            "/app/school-setup/rooms/{$foreignRoom->id}", "/app/school-setup/sections/{$foreignSection->id}",
            "/app/school-setup/terms/{$foreignTerm->id}", "/app/school-setup/academic-years/{$foreignYear->id}",
        ] as $url) {
            $this->assertSame(404, $this->actingAs($user)->put($url, ['name' => 'Hijack'])->getStatusCode(), $url);
        }
        $this->assertSame(404, $this->actingAs($user)->post("/app/school-setup/campuses/{$foreignCampus->id}/rooms", ['name' => 'R', 'code' => 'R'])->getStatusCode());
        $this->assertSame(404, $this->actingAs($user)->post("/app/school-setup/academic-years/{$foreignYear->id}/terms", ['name' => 'T', 'code' => 'T', 'sequence' => 1, 'starts_on' => '2026-04-01', 'ends_on' => '2026-05-01'])->getStatusCode());
        // A parent of another School inside an otherwise valid request.
        $this->assertSame(404, $this->actingAs($user)->post("/app/school-setup/academic-years/{$year->id}/sections", ['campus_id' => $foreignCampus->id, 'grade_level_id' => $grade->id, 'name' => 'S', 'code' => 'S'])->getStatusCode());
        $this->assertSame(404, $this->actingAs($user)->post("/app/school-setup/academic-years/{$year->id}/sections", ['campus_id' => $campus->id, 'grade_level_id' => $foreignGrade->id, 'name' => 'S', 'code' => 'S'])->getStatusCode());
        $this->actingAs($user)->post('/app/school-setup/subjects', ['name' => 'X', 'code' => 'X', 'academic_department_id' => $foreignDepartment->id])->assertSessionHasErrors('academic_department_id');
        // A client-supplied School id is ignored: the row lands in the active School.
        $this->actingAs($user)->post('/app/school-setup/departments', ['name' => 'D', 'code' => 'DX', 'school_id' => $other->id])->assertSessionHasNoErrors();
        $this->assertSame(0, app(TenantContext::class)->withSchool($other, fn () => DB::table('academic_departments')->where('code', 'DX')->count()));

        $this->assertNotSame('Hijack', app(TenantContext::class)->withSchool($other, fn () => Campus::query()->findOrFail($foreignCampus->id)->name));
    }

    #[Test]
    public function the_list_pages_issue_no_query_per_row(): void
    {
        [$user, $school] = $this->admin();
        $campus = $this->createCampus($school);
        $year = $this->createAcademicYear($school, ['status' => 'active']);
        $grade = $this->createGradeLevel($school);
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });
        $count = function (string $url) use (&$queries, $user): int {
            $queries = 0;
            $this->actingAs($user)->get($url)->assertOk();

            return $queries;
        };
        $this->createSection($year, $campus, $grade, ['code' => 'S0']);
        $this->createRoom($campus, ['code' => 'R0']);
        $this->createAcademicTerm($year, ['code' => 'T0']);
        // One Subject with a Department, so the Department eager load (one query) is in both measurements.
        $this->createSubject($school, ['academic_department_id' => $this->createAcademicDepartment($school)->id]);
        $this->actingAs($user)->get('/app/school-setup/sections')->assertOk();

        $before = ['sections' => $count('/app/school-setup/sections'), 'rooms' => $count('/app/school-setup/rooms'), 'subjects' => $count('/app/school-setup/subjects')];
        foreach (range(1, 4) as $i) {
            $this->createSection($year, $this->createCampus($school), $this->createGradeLevel($school), ['code' => "S{$i}"]);
            $this->createRoom($campus, ['code' => "R{$i}"]);
            $this->createSubject($school, ['academic_department_id' => $this->createAcademicDepartment($school)->id]);
        }

        $this->assertSame($before, ['sections' => $count('/app/school-setup/sections'), 'rooms' => $count('/app/school-setup/rooms'), 'subjects' => $count('/app/school-setup/subjects')]);
    }
}

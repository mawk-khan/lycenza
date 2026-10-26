<?php

namespace Tests\Feature\App;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\GradeLevel;
use App\Domain\Admissions\Application\AdmissionConversionService;
use App\Domain\Admissions\Infrastructure\AdmissionApplication;
use App\Domain\Admissions\Infrastructure\Applicant;
use App\Models\Campus;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * SIS 3.1 (MC-1): the browser correction workflow -- Edit availability,
 * state-aware forms, saving, visible refusals and validation, discard, and
 * that the routes themselves (never a hidden button) refuse view-only,
 * foreign-School and out-of-scope actors.
 */
class AdmissionCorrectionUiTest extends TestCase
{
    use CreatesTenancyFixtures;

    private School $school;

    private Campus $north;

    private Campus $south;

    private AcademicYear $year;

    private GradeLevel $grade;

    private Applicant $applicant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = $this->createSchool();
        $this->north = $this->createCampus($this->school, ['name' => 'North']);
        $this->south = $this->createCampus($this->school, ['name' => 'South']);
        $this->year = $this->createAcademicYear($this->school, ['code' => 'AY-UI', 'name' => '2026-27']);
        $this->grade = $this->createGradeLevel($this->school, ['name' => 'Grade 5', 'code' => 'G5', 'sequence' => 5]);
        $this->applicant = $this->createApplicant($this->school, ['first_name' => 'Asha', 'last_name' => 'Vrma', 'date_of_birth' => '2016-04-12']);
    }

    private function signIn(User $user): void
    {
        $this->actingAs($user)->post("/app/schools/{$this->school->id}/activate");
    }

    private function manager(?string $campusId = null): User
    {
        $user = $this->createUser();
        $this->assignSchoolRole($this->createMembership($user, $this->school), 'admissions_officer', $campusId);

        return $user;
    }

    private function application(string $status, ?Campus $campus = null): AdmissionApplication
    {
        return $this->createAdmissionApplication($this->applicant, $this->year, $campus ?? $this->north, $this->grade, ['status' => $status]);
    }

    private function fresh(AdmissionApplication|Applicant $model): AdmissionApplication|Applicant
    {
        return app(TenantContext::class)->withSchool($this->school, fn () => $model->fresh());
    }

    private function version(AdmissionApplication|Applicant $model): string
    {
        return $this->fresh($model)->updated_at->toIso8601String();
    }

    // --- Applicant ----------------------------------------------------------

    #[Test]
    public function a_manager_corrects_an_applicant_in_the_browser(): void
    {
        $this->signIn($this->manager());

        $this->get("/app/admissions/applicants/{$this->applicant->id}")->assertInertia(fn (AssertableInertia $p) => $p
            ->where('canCorrect', true)->where('admitted', false));
        $this->get("/app/admissions/applicants/{$this->applicant->id}/edit")->assertOk()->assertInertia(fn (AssertableInertia $p) => $p
            ->component('App/Admissions/Applicants/Edit')
            ->where('applicant.lastName', 'Vrma')
            ->where('applicant.dateOfBirth', '2016-04-12'));

        $this->put("/app/admissions/applicants/{$this->applicant->id}", [
            'first_name' => 'Asha', 'middle_name' => '', 'last_name' => 'Verma', 'date_of_birth' => '2016-04-12',
            'expected_updated_at' => $this->version($this->applicant),
        ])->assertRedirect("/app/admissions/applicants/{$this->applicant->id}")->assertSessionHas('flash');

        $this->assertSame('Verma', $this->fresh($this->applicant)->last_name);
    }

    #[Test]
    public function correction_validation_is_as_strict_as_creation(): void
    {
        $this->signIn($this->manager());

        $this->from("/app/admissions/applicants/{$this->applicant->id}/edit")
            ->put("/app/admissions/applicants/{$this->applicant->id}", ['first_name' => '', 'date_of_birth' => 'not-a-date', 'expected_updated_at' => $this->version($this->applicant)])
            ->assertSessionHasErrors(['first_name', 'date_of_birth']);
        $this->assertSame('Asha', $this->fresh($this->applicant)->first_name);
    }

    #[Test]
    public function an_admitted_applicant_offers_no_edit_and_refuses_one_visibly(): void
    {
        $section = $this->createSection($this->year, $this->north, $this->grade);
        app(AdmissionConversionService::class)->convert($this->application('accepted'), 'S-UI-1', $section, '01', '2026-06-01');
        $this->signIn($this->manager());

        $this->get("/app/admissions/applicants/{$this->applicant->id}")->assertInertia(fn (AssertableInertia $p) => $p
            ->where('canCorrect', false)->where('admitted', true));
        $this->get("/app/admissions/applicants/{$this->applicant->id}/edit")
            ->assertRedirect("/app/admissions/applicants/{$this->applicant->id}")->assertSessionHasErrors('correction');
        $this->from("/app/admissions/applicants/{$this->applicant->id}/edit")
            ->put("/app/admissions/applicants/{$this->applicant->id}", ['first_name' => 'Aasha', 'date_of_birth' => '2016-04-12', 'expected_updated_at' => $this->version($this->applicant)])
            ->assertSessionHasErrors('correction');
        $this->assertSame('Asha', $this->fresh($this->applicant)->first_name);
    }

    #[Test]
    public function a_stale_applicant_form_is_refused_visibly(): void
    {
        $this->signIn($this->manager());

        $this->from("/app/admissions/applicants/{$this->applicant->id}/edit")
            ->put("/app/admissions/applicants/{$this->applicant->id}", ['first_name' => 'Asha', 'last_name' => 'Verma', 'date_of_birth' => '2016-04-12', 'expected_updated_at' => '2000-01-01T00:00:00+00:00'])
            ->assertSessionHasErrors('correction');
        $this->assertSame('Vrma', $this->fresh($this->applicant)->last_name);
    }

    #[Test]
    public function view_only_campus_scoped_and_foreign_actors_cannot_correct_an_applicant(): void
    {
        $viewer = $this->createUserWithCapabilities($this->school, ['admissions.view']);
        $this->signIn($viewer);
        $this->get("/app/admissions/applicants/{$this->applicant->id}")->assertInertia(fn (AssertableInertia $p) => $p->where('canCorrect', false));
        $this->get("/app/admissions/applicants/{$this->applicant->id}/edit")->assertForbidden();
        $this->put("/app/admissions/applicants/{$this->applicant->id}", ['first_name' => 'X', 'date_of_birth' => '2016-04-12', 'expected_updated_at' => 'x'])->assertForbidden();

        // Applicant identity is whole-School: a Campus-scoped officer never reaches it.
        $this->signIn($this->manager($this->north->id));
        $this->get("/app/admissions/applicants/{$this->applicant->id}/edit")->assertForbidden();

        [$foreignAdmin, $foreignSchool] = $this->createSchoolAdmin('school_admin');
        $this->actingAs($foreignAdmin)->post("/app/schools/{$foreignSchool->id}/activate");
        $this->get("/app/admissions/applicants/{$this->applicant->id}/edit")->assertNotFound();
        $this->get('/app/admissions/applicants/'.Str::uuid().'/edit')->assertNotFound();
        $this->put("/app/admissions/applicants/{$this->applicant->id}", ['first_name' => 'X', 'date_of_birth' => '2016-04-12', 'expected_updated_at' => 'x'])->assertNotFound();
        $this->assertSame('Asha', $this->fresh($this->applicant)->first_name);
    }

    // --- Application ----------------------------------------------------------

    #[Test]
    public function a_draft_application_is_corrected_in_the_browser_and_status_cannot_be_smuggled(): void
    {
        $application = $this->application('draft');
        $this->signIn($this->manager());

        $this->get("/app/admissions/{$application->id}")->assertInertia(fn (AssertableInertia $p) => $p->where('canCorrect', true));
        $this->get("/app/admissions/{$application->id}/edit")->assertOk()->assertInertia(fn (AssertableInertia $p) => $p
            ->component('App/Admissions/Edit')->where('mode', 'context')
            ->where('application.campus.name', 'North')
            ->where('campuses', fn ($c) => collect($c)->pluck('name')->sort()->values()->all() === ['North', 'South']));

        $this->put("/app/admissions/{$application->id}", [
            'expected_status' => 'draft', 'expected_updated_at' => $this->version($application),
            'academic_year_id' => $this->year->id, 'campus_id' => $this->south->id, 'grade_level_id' => $this->grade->id,
            'status' => 'accepted', 'converted_student_id' => (string) Str::uuid(), 'applicant_id' => (string) Str::uuid(),
        ])->assertRedirect("/app/admissions/{$application->id}")->assertSessionHas('flash');

        $fresh = $this->fresh($application);
        $this->assertSame(['draft', $this->south->id, $this->applicant->id, null], [$fresh->status, $fresh->campus_id, $fresh->applicant_id, $fresh->converted_student_id]);
    }

    #[Test]
    public function a_decided_application_offers_only_its_note_and_refuses_a_context_change(): void
    {
        $application = $this->application('accepted');
        $this->signIn($this->manager());

        $this->get("/app/admissions/{$application->id}/edit")->assertInertia(fn (AssertableInertia $p) => $p->where('mode', 'note'));
        $this->put("/app/admissions/{$application->id}", ['expected_status' => 'accepted', 'expected_updated_at' => $this->version($application), 'decision_note' => 'Sibling at school'])
            ->assertRedirect("/app/admissions/{$application->id}");
        $this->assertSame('Sibling at school', $this->fresh($application)->decision_note);

        // A forged "draft" version of an accepted application is refused, never applied.
        $this->from("/app/admissions/{$application->id}/edit")->put("/app/admissions/{$application->id}", [
            'expected_status' => 'draft', 'expected_updated_at' => $this->version($application),
            'academic_year_id' => $this->year->id, 'campus_id' => $this->south->id, 'grade_level_id' => $this->grade->id,
        ])->assertSessionHasErrors('correction');
        $this->assertSame($this->north->id, $this->fresh($application)->campus_id);
    }

    #[Test]
    public function a_converted_application_offers_no_edit_and_refuses_one(): void
    {
        $section = $this->createSection($this->year, $this->north, $this->grade);
        $application = app(AdmissionConversionService::class)->convert($this->application('accepted'), 'S-UI-2', $section, '01', '2026-06-01')->application;
        $this->signIn($this->manager());

        $this->get("/app/admissions/{$application->id}")->assertInertia(fn (AssertableInertia $p) => $p->where('canCorrect', false));
        $this->get("/app/admissions/{$application->id}/edit")->assertRedirect("/app/admissions/{$application->id}")->assertSessionHasErrors('status');
        $this->from("/app/admissions/{$application->id}/edit")->put("/app/admissions/{$application->id}", ['expected_status' => 'converted', 'expected_updated_at' => $this->version($application), 'decision_note' => 'x'])
            ->assertSessionHasErrors('correction');
    }

    #[Test]
    public function an_edit_opened_before_conversion_cannot_rewrite_the_converted_application(): void
    {
        $section = $this->createSection($this->year, $this->north, $this->grade);
        $application = $this->application('accepted');
        $opened = $this->version($application);
        $this->travel(2)->seconds();
        app(AdmissionConversionService::class)->convert($application, 'S-UI-3', $section, '01', '2026-06-01');
        $this->signIn($this->manager());

        $this->from("/app/admissions/{$application->id}/edit")->put("/app/admissions/{$application->id}", ['expected_status' => 'accepted', 'expected_updated_at' => $opened, 'decision_note' => 'Rewritten'])
            ->assertSessionHasErrors('correction');
        $this->assertSame('converted', $this->fresh($application)->status);
        $this->assertNotSame('Rewritten', $this->fresh($application)->decision_note);
    }

    #[Test]
    public function validation_and_duplicate_context_refusals_are_visible_field_errors(): void
    {
        $grade6 = $this->createGradeLevel($this->school, ['name' => 'Grade 6', 'code' => 'G6', 'sequence' => 6]);
        $this->application('draft');
        $other = $this->createAdmissionApplication($this->applicant, $this->year, $this->north, $grade6, ['status' => 'draft']);
        $this->signIn($this->manager());

        $this->from("/app/admissions/{$other->id}/edit")->put("/app/admissions/{$other->id}", [
            'expected_status' => 'draft', 'expected_updated_at' => $this->version($other),
            'academic_year_id' => 'not-a-uuid', 'campus_id' => $this->north->id, 'grade_level_id' => $grade6->id,
        ])->assertSessionHasErrors('academic_year_id');
        $this->from("/app/admissions/{$other->id}/edit")->put("/app/admissions/{$other->id}", [
            'expected_status' => 'draft', 'expected_updated_at' => $this->version($other),
            'academic_year_id' => $this->year->id, 'campus_id' => $this->north->id, 'grade_level_id' => $this->grade->id,
        ])->assertSessionHasErrors('grade_level_id');
        $this->assertSame($grade6->id, $this->fresh($other)->grade_level_id);
    }

    #[Test]
    public function a_campus_scoped_officer_corrects_only_within_their_campuses(): void
    {
        $mine = $this->application('submitted', $this->north);
        $theirs = $this->createAdmissionApplication($this->createApplicant($this->school), $this->year, $this->south, $this->grade, ['status' => 'submitted']);
        $this->signIn($this->manager($this->north->id));

        $this->get("/app/admissions/{$mine->id}/edit")->assertInertia(fn (AssertableInertia $p) => $p
            ->where('campuses', fn ($c) => collect($c)->pluck('name')->all() === ['North']));
        $this->from("/app/admissions/{$mine->id}/edit")->put("/app/admissions/{$mine->id}", [
            'expected_status' => 'submitted', 'expected_updated_at' => $this->version($mine),
            'academic_year_id' => $this->year->id, 'campus_id' => $this->south->id, 'grade_level_id' => $this->grade->id,
        ])->assertSessionHasErrors('campus_id');
        $this->assertSame($this->north->id, $this->fresh($mine)->campus_id);

        $this->get("/app/admissions/{$theirs->id}/edit")->assertNotFound();
        $this->put("/app/admissions/{$theirs->id}", ['expected_status' => 'submitted', 'expected_updated_at' => $this->version($theirs), 'decision_note' => 'x'])->assertNotFound();
        $this->post("/app/admissions/{$theirs->id}/discard")->assertNotFound();
    }

    #[Test]
    public function view_only_and_foreign_actors_cannot_correct_or_discard_an_application(): void
    {
        $application = $this->application('draft');
        $this->signIn($this->createUserWithCapabilities($this->school, ['admissions.view']));
        $this->get("/app/admissions/{$application->id}")->assertInertia(fn (AssertableInertia $p) => $p->where('canCorrect', false));
        $this->get("/app/admissions/{$application->id}/edit")->assertForbidden();
        $this->put("/app/admissions/{$application->id}", ['expected_status' => 'draft', 'expected_updated_at' => 'x'])->assertForbidden();
        $this->post("/app/admissions/{$application->id}/discard")->assertForbidden();

        [$foreignAdmin, $foreignSchool] = $this->createSchoolAdmin('school_admin');
        $this->actingAs($foreignAdmin)->post("/app/schools/{$foreignSchool->id}/activate");
        $this->get("/app/admissions/{$application->id}/edit")->assertNotFound();
        $this->post("/app/admissions/{$application->id}/discard")->assertNotFound();
        $this->get('/app/admissions/not-a-uuid/edit')->assertNotFound();
        $this->assertSame('draft', $this->fresh($application)->status);
    }

    // --- Discard ----------------------------------------------------------------

    #[Test]
    public function a_draft_is_discarded_and_anything_later_is_refused_visibly(): void
    {
        $draft = $this->application('draft');
        $submitted = $this->createAdmissionApplication($this->createApplicant($this->school), $this->year, $this->north, $this->grade, ['status' => 'submitted']);
        $this->signIn($this->manager());

        $this->post("/app/admissions/{$draft->id}/discard")->assertRedirect("/app/admissions/{$draft->id}")->assertSessionHas('flash');
        $this->assertSame('withdrawn', $this->fresh($draft)->status);

        $this->from("/app/admissions/{$submitted->id}")->post("/app/admissions/{$submitted->id}/discard")->assertSessionHasErrors('status');
        $this->assertSame('submitted', $this->fresh($submitted)->status);
    }
}

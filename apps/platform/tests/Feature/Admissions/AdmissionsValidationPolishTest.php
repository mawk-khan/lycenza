<?php

namespace Tests\Feature\Admissions;

use App\Domain\Admissions\Application\AdmissionApplicationService;
use App\Domain\Admissions\Application\AdmissionConversionService;
use App\Domain\Admissions\Application\ApplicantService;
use App\Domain\Admissions\Application\Exceptions\ApplicantDateOfBirthInFutureException;
use App\Domain\Admissions\Application\Exceptions\InvalidAdmissionApplicationTransitionException;
use App\Domain\Admissions\Infrastructure\AdmissionApplication;
use App\Domain\Admissions\Infrastructure\Applicant;
use App\Domain\Guardians\Infrastructure\Guardian;
use App\Domain\Guardians\Infrastructure\RelationshipType;
use App\Domain\Students\Infrastructure\Student;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Models\Campus;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\SchoolTimezone;
use App\Support\Tenancy\TenantContext;
use App\Support\Validation\DateOfBirth;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Finder\Finder;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * SIS 3.2: Admissions validation and safety.
 *
 * - An Applicant's date of birth is never later than the School's own date
 *   (DateOfBirth, the one rule shared with Students): create, correction,
 *   the API and the services all refuse it, and conversion revalidates it so
 *   a legacy future date never becomes a Student (nothing is written).
 * - A NEW or CHANGED application context uses an active Campus: the forms
 *   offer only active Campuses; a forged inactive one is a field error; an
 *   application whose Campus was deactivated later stays readable and keeps
 *   its Campus.
 * - A refused transition reads in the operator's words, never status keys.
 */
class AdmissionsValidationPolishTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function in(School $school, callable $fn): mixed
    {
        return app(TenantContext::class)->withSchool($school, $fn);
    }

    private function admin(School $school): User
    {
        $user = $this->createUser();
        $this->assignSchoolRole($this->createMembership($user, $school), 'school_admin');
        $this->actingAs($user)->post("/app/schools/{$school->id}/activate");

        return $user;
    }

    /** @return array{school: School, campus: Campus, year: mixed, grade: mixed, section: mixed, applicant: Applicant} */
    private function world(): array
    {
        $school = $this->createSchool();
        $campus = $this->createCampus($school);
        $year = $this->createAcademicYear($school, ['code' => 'A'.Str::random(6), 'status' => 'active', 'starts_on' => '2026-04-01', 'ends_on' => '2027-03-31']);
        $grade = $this->createGradeLevel($school);
        $section = $this->createSection($year, $campus, $grade);
        $applicant = $this->createApplicant($school, ['first_name' => 'Asha', 'last_name' => 'Verma', 'date_of_birth' => '2016-05-05']);

        return compact('school', 'campus', 'year', 'grade', 'section', 'applicant');
    }

    private function tomorrow(School $school): string
    {
        return CarbonImmutable::parse(SchoolTimezone::today($school))->addDay()->toDateString();
    }

    // --- Date of birth ---------------------------------------------------------------------

    #[Test]
    public function a_future_date_of_birth_is_refused_on_create_today_and_past_are_accepted(): void
    {
        $w = $this->world();
        $this->admin($w['school']);
        $count = fn () => $this->in($w['school'], fn () => Applicant::query()->count());
        $before = $count();

        $this->post('/app/admissions/applicants', ['first_name' => 'Future', 'date_of_birth' => $this->tomorrow($w['school'])])
            ->assertSessionHasErrors(['date_of_birth' => DateOfBirth::FUTURE_MESSAGE]);
        $this->assertSame($before, $count(), 'nothing saved');

        $this->post('/app/admissions/applicants', ['first_name' => 'Today', 'date_of_birth' => SchoolTimezone::today($w['school'])])->assertSessionHasNoErrors();
        $this->post('/app/admissions/applicants', ['first_name' => 'Past', 'date_of_birth' => '2017-01-01'])->assertSessionHasNoErrors();
        $this->assertSame($before + 2, $count());
        $this->assertSame(SchoolTimezone::today($w['school']), $this->get('/app/admissions/applicants/create')->viewData('page')['props']['today']);
    }

    #[Test]
    public function a_correction_to_a_future_date_of_birth_is_refused_and_writes_nothing(): void
    {
        $w = $this->world();
        $this->admin($w['school']);
        $version = $this->in($w['school'], fn () => $w['applicant']->fresh()->updated_at->toIso8601String());

        $this->put("/app/admissions/applicants/{$w['applicant']->id}", [
            'first_name' => 'Asha', 'last_name' => 'Verma', 'date_of_birth' => $this->tomorrow($w['school']), 'expected_updated_at' => $version,
        ])->assertSessionHasErrors(['date_of_birth' => DateOfBirth::FUTURE_MESSAGE]);

        $this->assertSame('2016-05-05', $this->in($w['school'], fn () => $w['applicant']->fresh()->date_of_birth->toDateString()));
        $this->assertSame(SchoolTimezone::today($w['school']), $this->get("/app/admissions/applicants/{$w['applicant']->id}/edit")->viewData('page')['props']['today']);
    }

    #[Test]
    public function the_api_and_the_service_refuse_a_future_date_of_birth_too(): void
    {
        $w = $this->world();
        $user = $this->createUserWithCapabilities($w['school'], ['admissions.view', 'admissions.manage']);
        $this->withHeader('Authorization', 'Bearer '.$user->createToken('test-device')->plainTextToken)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/schools/{$w['school']->id}/applicants", ['first_name' => 'Api', 'date_of_birth' => $this->tomorrow($w['school'])])
            ->assertStatus(422)->assertJsonPath('error.errors.date_of_birth', [DateOfBirth::FUTURE_MESSAGE]);

        $service = app(ApplicantService::class);
        $this->assertThrows(fn () => $service->create($w['school'], ['first_name' => 'Svc', 'date_of_birth' => $this->tomorrow($w['school'])]), ValidationException::class);
        $version = $this->in($w['school'], fn () => $w['applicant']->fresh()->updated_at->toIso8601String());
        $this->assertThrows(fn () => $service->correct($w['applicant'], ['first_name' => 'Asha', 'date_of_birth' => $this->tomorrow($w['school'])], $version), ValidationException::class);
    }

    #[Test]
    public function the_school_local_date_decides_never_the_utc_date(): void
    {
        // West of UTC: at 02:00 UTC on 28 Oct it is still 27 Oct in New York.
        $west = $this->createSchool(['timezone' => 'America/New_York']);
        $this->travelTo(CarbonImmutable::parse('2026-10-28 02:00:00', 'UTC'));
        $this->assertTrue(DateOfBirth::isFuture($west, '2026-10-28'), 'tomorrow by the School date although today in UTC');
        $this->assertFalse(DateOfBirth::isFuture($west, '2026-10-27'));
        $this->assertThrows(fn () => app(ApplicantService::class)->create($west, ['first_name' => 'W', 'date_of_birth' => '2026-10-28']), ValidationException::class);

        // East of UTC: at 20:00 UTC on 27 Oct it is already 28 Oct in Kolkata.
        $east = $this->createSchool(['timezone' => 'Asia/Kolkata']);
        $this->travelTo(CarbonImmutable::parse('2026-10-27 20:00:00', 'UTC'));
        $this->assertFalse(DateOfBirth::isFuture($east, '2026-10-28'), 'today by the School date although tomorrow in UTC');
        $this->assertSame('2026-10-28', app(ApplicantService::class)->create($east, ['first_name' => 'E', 'date_of_birth' => '2026-10-28'])->date_of_birth->toDateString());
        $this->assertTrue(DateOfBirth::isFuture($east, '2026-10-29'));
    }

    #[Test]
    public function a_legacy_future_date_of_birth_is_never_converted_and_nothing_is_written(): void
    {
        $w = $this->world();
        $admin = $this->admin($w['school']);
        // Legacy row written before the rule (a direct factory write, never the service).
        $legacy = $this->createApplicant($w['school'], ['first_name' => 'Legacy', 'date_of_birth' => $this->tomorrow($w['school'])]);
        $applications = app(AdmissionApplicationService::class);
        $application = $applications->create($legacy, $w['year'], $w['campus'], $w['grade']);
        $applications->submit($application);
        $applications->accept($application);
        $counts = fn () => $this->in($w['school'], fn () => [Student::query()->count(), StudentEnrollment::query()->count(), Guardian::query()->count(), DB::table('admission_fee_selections')->count()]);
        $before = $counts();

        $this->post("/app/admissions/{$application->id}/convert", [
            'section_id' => $w['section']->id, 'roll_number' => '1', 'starts_on' => '2026-06-01',
            'guardian' => ['mode' => 'create', 'relationship_type' => RelationshipType::cases()[0]->value, 'first_name' => 'Guardian'],
        ])->assertSessionHasErrors(['status' => (new ApplicantDateOfBirthInFutureException)->getMessage()]);

        $this->assertSame($before, $counts(), 'no Student, placement, Guardian or fee intent');
        $this->assertSame('accepted', $this->in($w['school'], fn () => $application->fresh()->status));
        $this->assertThrows(fn () => app(AdmissionConversionService::class)->convert($application, $w['section'], '1', '2026-06-01', null, $admin), ApplicantDateOfBirthInFutureException::class);
    }

    // --- Campus ----------------------------------------------------------------------------

    #[Test]
    public function new_and_changed_context_use_an_active_campus_and_history_stays_readable(): void
    {
        $w = $this->world();
        $inactive = $this->createCampus($w['school'], ['name' => 'Old Campus', 'status' => 'inactive']);
        $other = $this->createSchool();
        $foreign = $this->createCampus($other);
        $this->admin($w['school']);

        $create = $this->get("/app/admissions/applicants/{$w['applicant']->id}/applications/create")->viewData('page')['props']['campuses'];
        $this->assertSame([$w['campus']->id], array_column($create, 'id'), 'only active Campuses of this School are offered');

        $payload = ['academic_year_id' => $w['year']->id, 'grade_level_id' => $w['grade']->id];
        $this->post("/app/admissions/applicants/{$w['applicant']->id}/applications", [...$payload, 'campus_id' => $inactive->id])
            ->assertSessionHasErrors(['campus_id' => 'Choose an active Campus of this School.']);
        $this->post("/app/admissions/applicants/{$w['applicant']->id}/applications", [...$payload, 'campus_id' => $foreign->id])
            ->assertSessionHasErrors('campus_id');
        $this->assertSame(0, $this->in($w['school'], fn () => AdmissionApplication::query()->count()));

        // An application whose Campus is deactivated later keeps it and stays readable.
        $application = app(AdmissionApplicationService::class)->create($w['applicant'], $w['year'], $w['campus'], $w['grade']);
        $this->in($w['school'], fn () => Campus::query()->whereKey($w['campus']->id)->update(['status' => 'inactive']));
        $show = $this->get("/app/admissions/{$application->id}")->assertOk()->viewData('page')['props']['application'];
        $this->assertSame($w['campus']->id, $show['campus']['id']);
        $this->assertFalse($show['campusActive']);
        $edit = $this->get("/app/admissions/{$application->id}/edit")->assertOk()->viewData('page')['props']['campuses'];
        $this->assertSame([$w['campus']->id], array_column($edit, 'id'), 'the recorded Campus is shown; no other inactive Campus is offered');
        $this->assertStringEndsWith('(inactive)', $edit[0]['name']);
        $this->assertContains('Old Campus (inactive)', array_column($this->get('/app/admissions')->viewData('page')['props']['campuses'], 'name'), 'the directory filter still finds history');

        $version = ['expected_status' => 'draft', 'expected_updated_at' => $this->in($w['school'], fn () => $application->fresh()->updated_at->toIso8601String())];
        $this->put("/app/admissions/{$application->id}", [...$payload, ...$version, 'campus_id' => $inactive->id])
            ->assertSessionHasErrors(['campus_id' => 'Choose an active Campus of this School.']);
        $this->assertSame($w['campus']->id, $this->in($w['school'], fn () => $application->fresh()->campus_id), 'never moved automatically');
    }

    // --- Wording -----------------------------------------------------------------------------

    #[Test]
    public function a_refused_transition_reads_in_the_operators_words(): void
    {
        $w = $this->world();
        $this->admin($w['school']);
        $application = app(AdmissionApplicationService::class)->create($w['applicant'], $w['year'], $w['campus'], $w['grade']);

        $this->post("/app/admissions/{$application->id}/accept")
            ->assertSessionHasErrors(['status' => "This application is still a draft, so it can't be accepted. Refresh the page to see what can be done next."]);
        $this->assertSame('draft', $this->in($w['school'], fn () => $application->fresh()->status), 'the status machine is unchanged');

        foreach (['draft', 'submitted', 'accepted', 'rejected', 'withdrawn', 'converted'] as $from) {
            foreach (['submitted', 'accepted', 'rejected', 'withdrawn', 'converted'] as $to) {
                $message = (new InvalidAdmissionApplicationTransitionException($from, $to))->getMessage();
                $this->assertDoesNotMatchRegularExpression("/'[a-z_]+'/", $message, 'no quoted status key');
            }
        }
    }

    #[Test]
    public function every_student_and_applicant_date_of_birth_validation_uses_the_one_shared_rule(): void
    {
        // Students and Admissions only: an Employee's date of birth is HR's own rule.
        $offenders = [];
        foreach (Finder::create()->files()->in(app_path())->name('*.php')->path('/(Students|Admissions|Student|Applicant|Admission)/') as $file) {
            if (preg_match("/'date_of_birth' => \[/", $file->getContents()) === 1) {
                $offenders[] = $file->getRelativePathname();
            }
            $shared = ($shared ?? 0) + substr_count($file->getContents(), 'DateOfBirth::rules(');
        }
        $this->assertGreaterThanOrEqual(7, $shared ?? 0, 'web + API Student create/update, onboarding, Applicant create/correction, Applicant API');
        $this->assertSame([], $offenders, 'Student and Applicant date-of-birth validation is DateOfBirth::rules()');
    }
}

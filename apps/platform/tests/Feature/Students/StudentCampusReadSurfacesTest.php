<?php

namespace Tests\Feature\Students;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\GradeLevel;
use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\Students\Application\StudentEnrollmentService;
use App\Domain\Students\Infrastructure\Student;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Models\Campus;
use App\Models\MembershipRoleAssignment;
use App\Models\Role;
use App\Models\School;
use App\Models\SchoolMembership;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use App\Support\Testing\LocalCatalogueFixtures;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * ORG.7F-1 (ADR 0076 §45): the SIS READ surfaces are Campus-aware.
 *
 * - students.view / enrollments.view are Campus-adopted (owner decision
 *   F1-D7: principal@Campus gains reads only).
 * - A Campus reader reaches only Students whose CURRENT operational Campus
 *   is in scope -- never a former Campus, a draft/future year, an inactive
 *   or unplaced Student -- and only the current placement, never history.
 * - Out of scope looks exactly like unknown (404). The full Profile,
 *   history, Guardians and rollover stay whole-School; no Student or
 *   Enrollment write is Campus-adopted; no date of birth or Guardian data
 *   reaches a Campus surface (F1-D1, F1-D2).
 */
class StudentCampusReadSurfacesTest extends TestCase
{
    use CreatesTenancyFixtures;

    private School $school;

    private Campus $a;

    private Campus $b;

    private AcademicYear $prior;

    private AcademicYear $current;

    private AcademicYear $next;

    private GradeLevel $grade;

    /** @var array<string, Section> "{year}:{campus}" => Section */
    private array $sections = [];

    /** @var array<string, Student> */
    private array $students = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = $this->createSchool();
        $this->a = $this->createCampus($this->school, ['name' => 'North']);
        $this->b = $this->createCampus($this->school, ['name' => 'South']);
        $this->grade = $this->createGradeLevel($this->school, ['name' => 'Grade 5']);
        $this->prior = $this->createAcademicYear($this->school, ['code' => 'Y2526', 'name' => '2025-26', 'status' => 'closed', 'starts_on' => '2025-06-01', 'ends_on' => '2026-03-31']);
        $this->current = $this->createAcademicYear($this->school, ['code' => 'Y2627', 'name' => '2026-27', 'status' => 'active', 'starts_on' => '2026-06-01', 'ends_on' => '2027-03-31']);
        $this->next = $this->createAcademicYear($this->school, ['code' => 'Y2728', 'name' => '2027-28', 'status' => 'draft', 'starts_on' => '2027-06-01', 'ends_on' => '2028-03-31']);
        foreach (['prior' => $this->prior, 'current' => $this->current, 'next' => $this->next] as $y => $year) {
            foreach (['a' => $this->a, 'b' => $this->b] as $c => $campus) {
                $this->sections["{$y}:{$c}"] = $this->createSection($year, $campus, $this->grade, ['code' => strtoupper("{$y}{$c}"), 'name' => strtoupper($c), 'status' => 'active']);
            }
        }

        $this->students['here'] = $this->student('Asha', ['current' => 'a']);
        $this->students['elsewhere'] = $this->student('Bela', ['current' => 'b']);
        // Year 1 on Campus A, now (Year 2) on Campus B.
        $this->students['former'] = $this->student('Chitra', ['prior' => 'a', 'current' => 'b']);
        // Current on B, next (draft) year on A.
        $this->students['future'] = $this->student('Dev', ['current' => 'b', 'next' => 'a']);
        $this->students['intake'] = $this->student('Esha', ['next' => 'a']);
        $this->students['inactive'] = $this->student('Farah', ['current' => 'a'], 'inactive');
        $this->students['unplaced'] = $this->student('Gita', []);
    }

    /** @param  array<string, string>  $placements  "{year}" => "{campus}" (active enrollments) */
    private function student(string $name, array $placements, string $status = 'active'): Student
    {
        $student = $this->createStudent($this->school, ['first_name' => $name, 'last_name' => 'Pupil', 'student_number' => 'S-'.$name, 'date_of_birth' => '2015-04-0'.(count($this->students) + 1), 'status' => $status]);
        foreach ($placements as $year => $campus) {
            $startsOn = ['prior' => '2025-06-01', 'current' => '2026-06-01', 'next' => '2027-06-01'][$year];
            $this->createStudentEnrollment($student, $this->sections["{$year}:{$campus}"], ['status' => 'active', 'roll_number' => (string) (count($this->students) + 10), 'starts_on' => $startsOn, 'ends_on' => null]);
        }

        return $student;
    }

    /** @param  list<?string>  $campusIds  null = whole School */
    private function actor(string $role, array $campusIds): User
    {
        $user = $this->createUser();
        $membership = $this->createMembership($user, $this->school);
        foreach ($campusIds as $campusId) {
            $this->assignSchoolRole($membership, $role, $campusId);
        }

        return $user;
    }

    private function web(User $user): self
    {
        app('auth')->forgetGuards();
        $this->flushHeaders();
        $this->actingAs($user)->post("/app/schools/{$this->school->id}/activate");

        return $this->actingAs($user);
    }

    private function api(User $user): self
    {
        app('auth')->forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$user->createToken('test-device')->plainTextToken);
    }

    private function url(string $path): string
    {
        return "/api/v1/schools/{$this->school->id}{$path}";
    }

    private function id(string $key): string
    {
        return $this->students[$key]->id;
    }

    private function enrollmentOf(string $key, AcademicYear $year): StudentEnrollment
    {
        return app(TenantContext::class)->withSchool($this->school, fn () => StudentEnrollment::query()->where('student_id', $this->id($key))->where('academic_year_id', $year->id)->firstOrFail());
    }

    /** @return list<string> first names on the web Directory */
    private function webDirectory(User $user, string $query = ''): array
    {
        $names = [];
        $this->web($user)->get('/app/students'.$query)->assertOk()->assertInertia(function (AssertableInertia $page) use (&$names): void {
            $names = collect($page->toArray()['props']['students']['data'])->pluck('firstName')->sort()->values()->all();
        });

        return $names;
    }

    // --- Directory -------------------------------------------------------------

    #[Test]
    public function the_whole_school_directory_is_unchanged(): void
    {
        $admin = $this->actor('school_admin', [null]);

        $this->assertSame(['Asha', 'Bela', 'Chitra', 'Dev', 'Esha', 'Farah', 'Gita'], $this->webDirectory($admin));
        $this->web($admin)->get('/app/students')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('campusView', false)
            ->where('canManage', true)
            ->where('students.data.0.status', 'active')
            ->missing('students.data.0.dateOfBirth')
            ->missing('students.data.0.currentPlacement.campus')
        );
    }

    #[Test]
    public function a_campus_directory_lists_only_students_currently_in_scope(): void
    {
        $principalA = $this->actor('principal', [$this->a->id]);

        // Not Bela (current B), not Chitra (Year 1 A, now B), not Dev (next year A),
        // not Esha (draft year only), not Farah (inactive), not Gita (unplaced).
        $this->assertSame(['Asha'], $this->webDirectory($principalA));
        $this->assertSame(['Bela', 'Chitra', 'Dev'], $this->webDirectory($this->actor('principal', [$this->b->id])));
        $this->assertSame(['Asha', 'Bela', 'Chitra', 'Dev'], $this->webDirectory($this->actor('principal', [$this->a->id, $this->b->id])), 'several Campus grants are a union');
    }

    #[Test]
    public function a_campus_directory_row_carries_only_the_safe_operational_fields(): void
    {
        $response = $this->web($this->actor('principal', [$this->a->id]))->get('/app/students')->assertOk();
        $response->assertInertia(function (AssertableInertia $page): void {
            $props = $page->toArray()['props'];
            $this->assertTrue($props['campusView']);
            $this->assertFalse($props['canManage'], 'Student writes are whole-School');
            $row = $props['students']['data'][0];
            $this->assertSame(['currentPlacement', 'firstName', 'id', 'lastName', 'middleName', 'studentNumber'], collect($row)->keys()->sort()->values()->all());
            $this->assertSame(['campus' => 'North', 'gradeLevel' => 'Grade 5', 'section' => 'A', 'rollNumber' => '10'], $row['currentPlacement']);
        });
        $response->assertDontSee('2015-04-01', false);
        $response->assertDontSee('dateOfBirth', false);
    }

    #[Test]
    public function the_api_campus_list_is_the_same_reach_and_shape(): void
    {
        $principalA = $this->actor('principal', [$this->a->id]);
        $response = $this->api($principalA)->getJson($this->url('/students'))->assertOk();

        $this->assertSame([$this->id('here')], collect($response->json('data'))->pluck('id')->all());
        $this->assertSame(['firstName', 'id', 'lastName', 'middleName', 'studentNumber'], collect($response->json('data.0'))->keys()->sort()->values()->all());
        $this->assertSame(1, $response->json('meta.total'));
        $this->assertSame(7, $this->api($this->actor('school_admin', [null]))->getJson($this->url('/students'))->json('meta.total'));
    }

    #[Test]
    public function filters_only_narrow_and_search_input_is_literal(): void
    {
        $principalA = $this->actor('principal', [$this->a->id]);

        $this->assertSame([], $this->webDirectory($principalA, '?status=inactive'), 'an inactive Student never appears to a Campus reader');
        $this->assertSame([], $this->webDirectory($principalA, '?name=Bela'));
        $this->assertSame([], $this->webDirectory($principalA, '?name=%25'), '% is a literal, not a wildcard');
        $this->assertSame([], $this->webDirectory($principalA, '?name=_'), '_ is a literal, not a wildcard');
        $this->assertSame(['Asha'], $this->webDirectory($principalA, '?name=asha'));
        $this->assertSame([], collect($this->api($principalA)->getJson($this->url('/students?name=%25'))->json('data'))->all());
        $this->assertSame([], collect($this->api($principalA)->getJson($this->url('/students?student_number=S-Bela'))->json('data'))->all());
    }

    // --- Overview and concealment ---------------------------------------------

    #[Test]
    public function the_overview_is_minimized(): void
    {
        $response = $this->web($this->actor('principal', [$this->a->id]))->get("/app/students/{$this->id('here')}/overview")->assertOk();
        $response->assertInertia(function (AssertableInertia $page): void {
            $props = $page->toArray()['props'];
            $this->assertSame('App/Students/Overview', $page->toArray()['component']);
            $this->assertSame(['firstName', 'id', 'lastName', 'middleName', 'studentNumber'], collect($props['student'])->keys()->sort()->values()->all());
            $this->assertSame(['campus' => 'North', 'gradeLevel' => 'Grade 5', 'section' => 'A', 'rollNumber' => '10'], $props['currentPlacement']);
            $this->assertFalse($props['canOpenProfile']);
            foreach (['relationships', 'accountLink', 'enrollmentHistory', 'dateOfBirth'] as $absent) {
                $this->assertArrayNotHasKey($absent, $props);
            }
        });
        $response->assertDontSee('2015-04-01', false);
    }

    #[Test]
    public function out_of_scope_students_look_like_unknown_ones(): void
    {
        $principalA = $this->actor('principal', [$this->a->id]);
        $unknown = $this->web($principalA)->get('/app/students/'.Str::uuid().'/overview')->assertNotFound();
        foreach (['elsewhere', 'former', 'future', 'intake', 'inactive', 'unplaced'] as $key) {
            $response = $this->web($principalA)->get("/app/students/{$this->id($key)}/overview")->assertNotFound();
            $this->assertSame($unknown->status(), $response->status(), "{$key} is indistinguishable from an unknown Student");
            $response->assertDontSee($this->students[$key]->first_name, false);
        }
        $this->web($principalA)->get('/app/students/not-a-uuid/overview')->assertNotFound();
    }

    #[Test]
    public function a_whole_school_reader_opens_any_overview_and_the_profile(): void
    {
        $admin = $this->actor('school_admin', [null]);
        $this->web($admin)->get("/app/students/{$this->id('unplaced')}/overview")->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('canOpenProfile', true)->where('currentPlacement', null));
        $this->web($admin)->get("/app/students/{$this->id('former')}")->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('App/Students/Show')->has('enrollmentHistory', 2));
    }

    // --- Whole-School boundary --------------------------------------------------

    #[Test]
    public function the_profile_history_guardians_and_rollover_stay_whole_school(): void
    {
        $principalA = $this->actor('principal', [$this->a->id]);
        $here = $this->id('here');

        $this->web($principalA)->get("/app/students/{$here}")->assertForbidden();
        $this->web($principalA)->get("/app/students/{$here}/history")->assertForbidden();
        $this->web($principalA)->get('/app/enrollment-rollovers')->assertForbidden();
        $this->web($principalA)->get('/app/data-health')->assertForbidden();
        $this->api($principalA)->getJson($this->url("/students/{$here}"))->assertForbidden();
        $this->api($principalA)->getJson($this->url("/students/{$here}/guardians"))->assertForbidden();
        $this->api($principalA)->getJson($this->url("/students/{$here}/enrollments"))->assertForbidden();
        $this->api($principalA)->getJson($this->url('/enrollment-rollovers'))->assertForbidden();
    }

    #[Test]
    public function no_student_or_enrollment_write_is_campus_authorized(): void
    {
        $principalA = $this->actor('principal', [$this->a->id]);
        $here = $this->id('here');
        $placement = $this->enrollmentOf('here', $this->current);

        $this->web($principalA)->get('/app/students/create')->assertForbidden();
        $this->web($principalA)->post('/app/students', ['student_number' => 'S-NEW', 'first_name' => 'New', 'date_of_birth' => '2015-01-01'])->assertForbidden();
        $this->web($principalA)->put("/app/students/{$here}", ['student_number' => 'S-X', 'first_name' => 'Renamed', 'date_of_birth' => '2015-01-01'])->assertForbidden();
        $this->web($principalA)->post("/app/students/{$here}/status", ['status' => 'inactive'])->assertForbidden();
        $this->web($principalA)->post("/app/students/{$this->id('unplaced')}/enrollments", ['section_id' => $this->sections['current:a']->id, 'roll_number' => '99', 'starts_on' => '2026-06-02'])->assertForbidden();
        $this->web($principalA)->post("/app/enrollments/{$placement->id}/transfer", ['target_section_id' => $this->sections['current:b']->id, 'roll_number' => '98', 'effective_date' => '2026-07-01'])->assertForbidden();
        $this->web($principalA)->post("/app/enrollments/{$placement->id}/withdraw", ['ends_on' => '2026-07-01'])->assertForbidden();
        $this->api($principalA)->patchJson($this->url("/students/{$here}"), ['first_name' => 'Renamed'])->assertForbidden();

        $this->assertSame('Asha', app(TenantContext::class)->withSchool($this->school, fn () => Student::query()->whereKey($here)->value('first_name')));
        $this->assertSame('active', $this->enrollmentOf('here', $this->current)->status);
    }

    // --- Current versus historical enrollment ----------------------------------

    #[Test]
    public function the_campus_enrollment_directory_holds_current_in_scope_placements_only(): void
    {
        $principalA = $this->actor('principal', [$this->a->id]);
        $rows = $this->api($principalA)->getJson($this->url('/enrollments'))->assertOk()->json('data');

        $this->assertSame([$this->enrollmentOf('here', $this->current)->id], collect($rows)->pluck('id')->all(), 'no Year 1 A row (Chitra), no next-year A row (Dev, Esha), no inactive Student');
        $this->assertSame([], $this->api($principalA)->getJson($this->url("/enrollments?campus_id={$this->b->id}"))->json('data'), 'a request Campus never widens the scope');
        $this->assertSame([], $this->api($principalA)->getJson($this->url("/enrollments?academic_year_id={$this->prior->id}"))->json('data'), 'history is never listed');
        $this->assertCount(8, $this->api($this->actor('school_admin', [null]))->getJson($this->url('/enrollments?per_page=100'))->json('data'), 'whole School: every row, history included');

        $this->web($principalA)->get('/app/enrollments')->assertOk()->assertInertia(function (AssertableInertia $page): void {
            $props = $page->toArray()['props'];
            $this->assertTrue($props['campusView']);
            $this->assertSame(['Asha'], collect($props['enrollments']['data'])->pluck('student.firstName')->all());
            $this->assertSame([$this->current->id], collect($props['academicYears'])->pluck('id')->all());
            $this->assertSame([$this->a->id], collect($props['campuses'])->pluck('id')->all());
            $this->assertSame([$this->sections['current:a']->id], collect($props['sections'])->pluck('id')->all());
        });
    }

    #[Test]
    public function one_enrollment_and_the_current_placement_follow_the_same_rule(): void
    {
        $principalA = $this->actor('principal', [$this->a->id]);
        $own = $this->enrollmentOf('here', $this->current);

        $this->api($principalA)->getJson($this->url("/enrollments/{$own->id}"))->assertOk()->assertJsonPath('data.campus.id', $this->a->id);
        foreach ([$this->enrollmentOf('former', $this->prior), $this->enrollmentOf('future', $this->next), $this->enrollmentOf('elsewhere', $this->current), $this->enrollmentOf('inactive', $this->current)] as $hidden) {
            $this->api($principalA)->getJson($this->url("/enrollments/{$hidden->id}"))->assertNotFound();
        }

        $this->api($principalA)->getJson($this->url("/students/{$this->id('here')}/enrollments/current"))->assertOk()->assertJsonPath('data.id', $own->id);
        $this->api($principalA)->getJson($this->url("/students/{$this->id('here')}/enrollments/current?academic_year_id={$this->prior->id}"))->assertOk()->assertJsonPath('data', null);
        foreach (['elsewhere', 'former', 'future', 'intake', 'inactive', 'unplaced'] as $key) {
            $this->api($principalA)->getJson($this->url("/students/{$this->id($key)}/enrollments/current"))->assertNotFound();
        }

        // Whole School: the named-year read is unchanged.
        $admin = $this->actor('school_admin', [null]);
        $this->api($admin)->getJson($this->url("/students/{$this->id('former')}/enrollments/current?academic_year_id={$this->prior->id}"))->assertOk()
            ->assertJsonPath('data.campus.id', $this->a->id);
        $this->assertCount(2, $this->api($admin)->getJson($this->url("/students/{$this->id('former')}/enrollments"))->json('data'));
    }

    #[Test]
    public function placement_needs_its_own_enrollments_view_reach(): void
    {
        // A Campus grant of a role holding students.view but not
        // enrollments.view would see no placement; principal holds both.
        $principalA = $this->actor('principal', [$this->a->id]);
        $this->web($principalA)->get("/app/students/{$this->id('here')}/overview")->assertInertia(fn (AssertableInertia $page) => $page->where('canViewPlacement', true));
    }

    #[Test]
    public function a_mixed_scope_reader_sees_placements_only_within_its_enrollment_reach(): void
    {
        // students.view for the whole School, enrollments.view for Campus A only.
        $user = $this->actor('principal', [$this->a->id]);
        $membership = SchoolMembership::query()->where('user_id', $user->id)->where('school_id', $this->school->id)->firstOrFail();
        $role = LocalCatalogueFixtures::asOwner(fn () => Role::query()->create(['key' => 'test_role_'.uniqid(), 'name' => 'Student reader', 'scope' => 'school', 'is_system' => false]));
        LocalCatalogueFixtures::asOwner(fn () => $role->capabilities()->sync(['students.view']));
        app(TenantContext::class)->withSchool($this->school, fn () => LocalCatalogueFixtures::asOwner(fn () => MembershipRoleAssignment::query()->create([
            'school_id' => $this->school->id, 'school_membership_id' => $membership->id, 'role_id' => $role->id,
        ])));

        $this->web($user)->get('/app/students')->assertInertia(function (AssertableInertia $page): void {
            $rows = collect($page->toArray()['props']['students']['data'])->keyBy('firstName');
            $this->assertFalse($page->toArray()['props']['campusView'], 'the Directory reach is whole-School');
            $this->assertSame('Grade 5', $rows['Asha']['currentPlacement']['gradeLevel']);
            $this->assertNull($rows['Bela']['currentPlacement'], 'a placement outside the enrollments.view reach is never shown');
            $this->assertNull($rows['Farah']['currentPlacement'], 'an inactive Student\'s placement is not current operational placement for a Campus reach');
        });
    }

    // --- Freshness, tenancy, navigation -----------------------------------------

    #[Test]
    public function a_transfer_moves_read_access_on_the_next_request(): void
    {
        $principalA = $this->actor('principal', [$this->a->id]);
        $principalB = $this->actor('principal', [$this->b->id]);
        $this->web($principalA)->get("/app/students/{$this->id('here')}/overview")->assertOk();

        app(TenantContext::class)->withSchool($this->school, fn () => app(StudentEnrollmentService::class)->transferPlacement(
            $this->enrollmentOf('here', $this->current), $this->sections['current:b'], '77', '2026-08-01',
        ));

        $this->web($principalA)->get("/app/students/{$this->id('here')}/overview")->assertNotFound();
        $this->assertSame([], $this->webDirectory($principalA));
        $this->assertContains('Asha', $this->webDirectory($principalB));
        $this->api($principalA)->getJson($this->url("/enrollments/{$this->enrollmentOf('here', $this->current)->id}"))->assertNotFound();
    }

    #[Test]
    public function an_inactive_campus_confers_nothing(): void
    {
        $principalA = $this->actor('principal', [$this->a->id]);
        app(TenantContext::class)->withSchool($this->school, fn () => Campus::query()->whereKey($this->a->id)->update(['status' => 'inactive']));

        $this->api($principalA)->getJson($this->url('/students'))->assertForbidden();
        $this->web($principalA)->get("/app/students/{$this->id('here')}/overview")->assertForbidden();
    }

    #[Test]
    public function another_schools_records_never_participate(): void
    {
        $other = $this->createSchool();
        $otherCampus = $this->createCampus($other);
        $foreign = app(TenantContext::class)->withSchool($other, fn () => $this->createStudent($other, ['first_name' => 'Foreign']));
        $principalA = $this->actor('principal', [$this->a->id]);

        $this->web($principalA)->get("/app/students/{$foreign->id}/overview")->assertNotFound();
        $this->assertSame([], $this->api($principalA)->getJson($this->url("/enrollments?campus_id={$otherCampus->id}"))->json('data'));
        $this->api($this->actor('school_admin', [null]))->getJson($this->url("/students/{$foreign->id}/enrollments/current"))->assertNotFound();
    }

    #[Test]
    public function campus_navigation_offers_the_directories_and_never_the_whole_school_sis_hubs(): void
    {
        $this->web($this->actor('principal', [$this->a->id]))->get('/app/students')->assertInertia(function (AssertableInertia $page): void {
            $keys = collect($page->toArray()['props']['navigation']['groups'])->flatMap(fn (array $g) => collect($g['items'])->pluck('key'))->all();
            $this->assertContains('students', $keys);
            $this->assertContains('enrollments', $keys);
            foreach (['enrollment-rollovers', 'guardians', 'data-health'] as $hidden) {
                $this->assertNotContains($hidden, $keys);
            }
        });
    }

    #[Test]
    public function teacher_and_other_campus_roles_gain_no_student_directory(): void
    {
        foreach (['front_office', 'admissions_officer', 'hostel_warden', 'librarian'] as $role) {
            $user = $this->actor($role, [$this->a->id]);
            $this->web($user)->get('/app/students')->assertForbidden();
            $this->api($user)->getJson($this->url('/students'))->assertForbidden();
        }
        $teacher = $this->actor('teacher', [null]);
        $this->web($teacher)->get('/app/students')->assertForbidden();
    }

    protected function tearDown(): void
    {
        app('auth')->forgetGuards();
        parent::tearDown();
    }
}

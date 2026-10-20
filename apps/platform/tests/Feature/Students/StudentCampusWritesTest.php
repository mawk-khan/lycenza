<?php

namespace Tests\Feature\Students;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\GradeLevel;
use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\Students\Infrastructure\Student;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Models\Campus;
use App\Models\MembershipRoleAssignment;
use App\Models\Role;
use App\Models\School;
use App\Models\SchoolAuditEvent;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use App\Support\Testing\LocalCatalogueFixtures;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * ORG.7F-2 (ADR 0076 §46): the two narrow Campus SIS writes.
 *
 * - Name edit: first/middle/last name of a Student whose CURRENT
 *   operational Campus is in the students.manage scope -- nothing else,
 *   out of scope is NOT FOUND.
 * - Atomic onboarding: students.manage AND enrollments.manage in the
 *   destination Campus; a NEW Student with a server-allocated number plus
 *   the current placement, or nothing. Never an existing Student, never a
 *   typed number, never a Guardian.
 * - Every other students.manage / enrollments.manage operation stays
 *   whole-School (declared).
 */
class StudentCampusWritesTest extends TestCase
{
    use CreatesTenancyFixtures;

    private School $school;

    private Campus $a;

    private Campus $b;

    private AcademicYear $current;

    private AcademicYear $next;

    private GradeLevel $grade;

    /** @var array<string, Section> */
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
        $prior = $this->createAcademicYear($this->school, ['code' => 'Y0', 'status' => 'closed', 'starts_on' => '2025-06-01', 'ends_on' => '2026-03-31']);
        $this->current = $this->createAcademicYear($this->school, ['code' => 'Y1', 'status' => 'active', 'starts_on' => '2026-06-01', 'ends_on' => '2027-03-31']);
        $this->next = $this->createAcademicYear($this->school, ['code' => 'Y2', 'status' => 'draft', 'starts_on' => '2027-06-01', 'ends_on' => '2028-03-31']);
        foreach (['prior' => $prior, 'current' => $this->current, 'next' => $this->next] as $y => $year) {
            foreach (['a' => $this->a, 'b' => $this->b] as $c => $campus) {
                $this->sections["{$y}:{$c}"] = $this->createSection($year, $campus, $this->grade, ['code' => strtoupper("{$y}{$c}"), 'name' => strtoupper($c), 'status' => 'active']);
            }
        }
        $this->students['here'] = $this->student('Asha', ['current' => 'a']);
        $this->students['elsewhere'] = $this->student('Bela', ['current' => 'b']);
        $this->students['former'] = $this->student('Chitra', ['prior' => 'a', 'current' => 'b']);
        $this->students['future'] = $this->student('Dev', ['current' => 'b', 'next' => 'a']);
        $this->students['inactive'] = $this->student('Farah', ['current' => 'a'], 'inactive');
        $this->students['unplaced'] = $this->student('Gita', []);
    }

    /** @param  array<string, string>  $placements */
    private function student(string $name, array $placements, string $status = 'active'): Student
    {
        $student = $this->createStudent($this->school, ['first_name' => $name, 'last_name' => 'Pupil', 'student_number' => 'S-'.$name, 'date_of_birth' => '2015-04-01', 'status' => $status]);
        foreach ($placements as $year => $campus) {
            $startsOn = ['prior' => '2025-06-01', 'current' => '2026-06-01', 'next' => '2027-06-01'][$year];
            $this->createStudentEnrollment($student, $this->sections["{$year}:{$campus}"], ['status' => 'active', 'roll_number' => 'R-'.$name, 'starts_on' => $startsOn, 'ends_on' => null]);
        }

        return $student;
    }

    /** @param  list<?string>  $campusIds */
    private function actor(string $role, array $campusIds): User
    {
        $user = $this->createUser();
        $membership = $this->createMembership($user, $this->school);
        foreach ($campusIds as $campusId) {
            $this->assignSchoolRole($membership, $role, $campusId);
        }

        return $user;
    }

    /** A Campus grant of a custom role holding exactly these keys. */
    private function holderOf(array $capabilities, string $campusId): User
    {
        $user = $this->createUser();
        $membership = $this->createMembership($user, $this->school);
        $role = LocalCatalogueFixtures::asOwner(fn () => Role::query()->create(['key' => 'test_role_'.uniqid(), 'name' => 'Test', 'scope' => 'school', 'is_system' => false]));
        LocalCatalogueFixtures::asOwner(fn () => $role->capabilities()->sync($capabilities));
        $this->inSchool(fn () => LocalCatalogueFixtures::asOwner(fn () => MembershipRoleAssignment::query()->create([
            'school_id' => $this->school->id, 'school_membership_id' => $membership->id, 'role_id' => $role->id, 'campus_id' => $campusId,
        ])));

        return $user;
    }

    private function web(User $user): self
    {
        app('auth')->forgetGuards();
        $this->flushHeaders();
        $this->actingAs($user)->post("/app/schools/{$this->school->id}/activate");

        return $this->actingAs($user);
    }

    private function inSchool(callable $fn): mixed
    {
        return app(TenantContext::class)->withSchool($this->school, $fn);
    }

    private function fresh(string $key): Student
    {
        return $this->inSchool(fn () => Student::query()->findOrFail($this->students[$key]->id));
    }

    private function onboardPayload(array $overrides = []): array
    {
        return ['first_name' => 'Nia', 'middle_name' => null, 'last_name' => 'New', 'date_of_birth' => '2016-02-03', 'section_id' => $this->sections['current:a']->id, 'roll_number' => '41', ...$overrides];
    }

    // --- Name edit -------------------------------------------------------------------

    #[Test]
    public function a_campus_editor_changes_only_the_three_names_of_a_current_in_scope_student(): void
    {
        $principalA = $this->actor('principal', [$this->a->id]);
        $id = $this->students['here']->id;

        $this->web($principalA)->get("/app/students/{$id}/names")->assertOk()->assertInertia(function (AssertableInertia $page): void {
            $this->assertSame(['firstName', 'id', 'lastName', 'middleName', 'studentNumber'], collect($page->toArray()['props']['student'])->keys()->sort()->values()->all());
        });
        $this->web($principalA)->put("/app/students/{$id}/names", [
            'first_name' => 'Ashaa', 'middle_name' => 'K', 'last_name' => 'Rao',
            // Over-posted fields are ignored, never mass-assigned.
            'student_number' => 'HACK-1', 'date_of_birth' => '2000-01-01', 'status' => 'inactive', 'school_id' => Str::uuid()->toString(),
        ])->assertRedirect("/app/students/{$id}/overview");

        $student = $this->fresh('here');
        $this->assertSame(['Ashaa', 'K', 'Rao'], [$student->first_name, $student->middle_name, $student->last_name]);
        $this->assertSame(['S-Asha', '2015-04-01', 'active'], [$student->student_number, $student->date_of_birth->toDateString(), $student->status]);
        $audit = $this->inSchool(fn () => SchoolAuditEvent::query()->where('event_type', 'student.updated')->where('subject_id', $id)->orderByDesc('occurred_at')->orderByDesc('id')->firstOrFail());
        $this->assertSame(['first_name', 'middle_name', 'last_name'], $audit->metadata['changedFields']);
        $this->assertSame('campus', $audit->metadata['scope']);
    }

    #[Test]
    public function students_outside_the_current_campus_scope_look_like_unknown_ones(): void
    {
        $principalA = $this->actor('principal', [$this->a->id]);
        foreach (['elsewhere', 'former', 'future', 'inactive', 'unplaced'] as $key) {
            $id = $this->students[$key]->id;
            $this->web($principalA)->get("/app/students/{$id}/names")->assertNotFound();
            $this->web($principalA)->put("/app/students/{$id}/names", ['first_name' => 'X'])->assertNotFound();
            $this->assertNotSame('X', $this->fresh($key)->first_name, $key);
        }
        $this->web($principalA)->put('/app/students/'.Str::uuid().'/names', ['first_name' => 'X'])->assertNotFound();
    }

    #[Test]
    public function the_overview_offers_name_edit_only_within_reach(): void
    {
        $principalA = $this->actor('principal', [$this->a->id]);
        $this->web($principalA)->get("/app/students/{$this->students['here']->id}/overview")->assertInertia(fn (AssertableInertia $page) => $page->where('canEditNames', true)->where('canOpenProfile', false));
        $reader = $this->holderOf(['students.view', 'enrollments.view'], $this->a->id);
        $this->web($reader)->get("/app/students/{$this->students['here']->id}/overview")->assertInertia(fn (AssertableInertia $page) => $page->where('canEditNames', false));
        $this->web($reader)->put("/app/students/{$this->students['here']->id}/names", ['first_name' => 'X'])->assertForbidden();
    }

    #[Test]
    public function a_whole_school_editor_keeps_the_full_identity_edit_and_may_rename_anyone(): void
    {
        $admin = $this->actor('school_admin', [null]);
        $this->web($admin)->put("/app/students/{$this->students['unplaced']->id}/names", ['first_name' => 'Gitanjali'])->assertRedirect();
        $this->assertSame('Gitanjali', $this->fresh('unplaced')->first_name);
        $this->web($admin)->get("/app/students/{$this->students['here']->id}/edit")->assertOk();
    }

    // --- Onboarding ------------------------------------------------------------------

    #[Test]
    public function a_campus_principal_onboards_a_new_student_atomically_with_a_server_number(): void
    {
        $principalA = $this->actor('principal', [$this->a->id]);
        $this->createStudent($this->school, ['student_number' => '0041']);

        $this->web($principalA)->get('/app/students/onboard')->assertOk()->assertInertia(function (AssertableInertia $page): void {
            $this->assertSame([$this->sections['current:a']->id], collect($page->toArray()['props']['sections'])->pluck('id')->all(), 'only active current-year Sections of an in-scope Campus');
        });

        $response = $this->web($principalA)->post('/app/students/onboard', $this->onboardPayload([
            // Never accepted: a typed number, an existing Student, a status, a Guardian.
            'student_number' => 'S-Asha', 'student_id' => $this->students['unplaced']->id, 'status' => 'inactive',
            'guardian' => ['first_name' => 'Mum', 'contact' => '+911234567890'],
        ]));

        $student = $this->inSchool(fn () => Student::query()->where('first_name', 'Nia')->firstOrFail());
        $response->assertRedirect("/app/students/{$student->id}/overview");
        $this->assertSame('42', $student->student_number, 'allocated above the numeric 0041, never the typed S-Asha');
        $this->assertSame(['active', '2016-02-03'], [$student->status, $student->date_of_birth->toDateString()]);
        $placement = $this->inSchool(fn () => StudentEnrollment::query()->where('student_id', $student->id)->sole());
        $this->assertSame([$this->current->id, $this->a->id, $this->sections['current:a']->id, '41', 'active'], [$placement->academic_year_id, $placement->campus_id, $placement->section_id, $placement->roll_number, $placement->status]);
        $this->assertSame('Gita', $this->fresh('unplaced')->first_name, 'the named existing Student was never touched');
        $this->assertSame(0, $this->inSchool(fn () => StudentEnrollment::query()->where('student_id', $this->students['unplaced']->id)->count()), 'no capture');
        $this->assertSame(0, $this->inSchool(fn () => DB::table('student_guardian_relationships')->where('student_id', $student->id)->count()), 'no Guardian (F2-D12)');
        $audit = $this->inSchool(fn () => SchoolAuditEvent::query()->where('event_type', 'student.campus_onboarded')->where('subject_id', $student->id)->firstOrFail());
        $this->assertSame(['academicYearId', 'campusId', 'enrollmentId', 'sectionId', 'studentId'], collect($audit->metadata)->keys()->sort()->values()->all());

        // The Campus overview then shows the allocated number, never the date of birth.
        $this->web($principalA)->get("/app/students/{$student->id}/overview")->assertOk()->assertSee('42', false)->assertDontSee('2016-02-03', false);
    }

    #[Test]
    public function every_refused_destination_is_the_same_answer_and_leaves_nothing(): void
    {
        $principalA = $this->actor('principal', [$this->a->id]);
        $inactive = $this->createSection($this->current, $this->a, $this->grade, ['code' => 'OFF', 'status' => 'inactive']);
        $messages = [];
        foreach (['other Campus' => $this->sections['current:b']->id, 'draft year' => $this->sections['next:a']->id, 'closed year' => $this->sections['prior:a']->id, 'inactive Section' => $inactive->id, 'unknown' => Str::uuid()->toString()] as $case => $sectionId) {
            $response = $this->web($principalA)->post('/app/students/onboard', $this->onboardPayload(['section_id' => $sectionId]));
            $response->assertSessionHasErrors('section_id');
            $messages[$case] = session('errors')->first('section_id');
        }
        $this->assertCount(1, array_unique($messages), 'one generic refusal for every case');
        $this->assertSame(0, $this->inSchool(fn () => Student::query()->where('first_name', 'Nia')->count()), 'no Campus-less Student survives');
        $this->assertSame(0, $this->inSchool(fn () => DB::table('student_number_sequences')->where('school_id', $this->school->id)->whereNotNull('last_allocated_value')->count()), 'no number was consumed');
    }

    #[Test]
    public function no_active_year_refuses_onboarding(): void
    {
        $principalA = $this->actor('principal', [$this->a->id]);
        $this->inSchool(fn () => AcademicYear::query()->whereKey($this->current->id)->update(['status' => 'closed']));

        $this->web($principalA)->post('/app/students/onboard', $this->onboardPayload())->assertSessionHasErrors('section_id');
        $this->assertSame(0, $this->inSchool(fn () => Student::query()->where('first_name', 'Nia')->count()));
    }

    #[Test]
    public function a_taken_roll_number_rolls_the_whole_onboarding_back(): void
    {
        $principalA = $this->actor('principal', [$this->a->id]);

        $this->web($principalA)->post('/app/students/onboard', $this->onboardPayload(['roll_number' => 'R-Asha']))->assertSessionHasErrors('roll_number');
        $this->assertSame(0, $this->inSchool(fn () => Student::query()->where('first_name', 'Nia')->count()), 'Student and placement are one transaction');
    }

    #[Test]
    public function onboarding_needs_both_keys_in_the_destination_campus(): void
    {
        foreach ([['students.view', 'students.manage'], ['enrollments.view', 'enrollments.manage']] as $keys) {
            $user = $this->holderOf($keys, $this->a->id);
            $this->web($user)->get('/app/students/onboard')->assertForbidden();
            $this->web($user)->post('/app/students/onboard', $this->onboardPayload())->assertForbidden();
        }
        // Each key on a different Campus: no destination is in both scopes.
        $split = $this->holderOf(['students.view', 'students.manage'], $this->a->id);
        $membership = DB::table('school_memberships')->where('user_id', $split->id)->value('id');
        $role = LocalCatalogueFixtures::asOwner(fn () => Role::query()->create(['key' => 'test_role_'.uniqid(), 'name' => 'Test', 'scope' => 'school', 'is_system' => false]));
        LocalCatalogueFixtures::asOwner(fn () => $role->capabilities()->sync(['enrollments.view', 'enrollments.manage']));
        $this->inSchool(fn () => LocalCatalogueFixtures::asOwner(fn () => MembershipRoleAssignment::query()->create(['school_id' => $this->school->id, 'school_membership_id' => $membership, 'role_id' => $role->id, 'campus_id' => $this->b->id])));
        $this->web($split)->get('/app/students/onboard')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('sections', []));
        $this->web($split)->post('/app/students/onboard', $this->onboardPayload())->assertSessionHasErrors('section_id');
        $this->assertSame(0, $this->inSchool(fn () => Student::query()->where('first_name', 'Nia')->count()));
    }

    #[Test]
    public function every_other_student_and_enrollment_write_stays_whole_school(): void
    {
        $principalA = $this->actor('principal', [$this->a->id]);
        $here = $this->students['here']->id;
        $placement = $this->inSchool(fn () => StudentEnrollment::query()->where('student_id', $here)->firstOrFail());

        foreach ([
            ['get', '/app/students/create'], ['post', '/app/students'], ['get', "/app/students/{$here}/edit"], ['put', "/app/students/{$here}"],
            ['post', "/app/students/{$here}/status"], ['get', "/app/students/{$here}/enrollments/create"], ['post', "/app/students/{$this->students['unplaced']->id}/enrollments"],
            ['get', "/app/enrollments/{$placement->id}/transfer"], ['post', "/app/enrollments/{$placement->id}/transfer"], ['post', "/app/enrollments/{$placement->id}/withdraw"],
            ['post', "/app/enrollments/{$placement->id}/complete"], ['get', '/app/enrollments/closed-year-placements'], ['get', '/app/enrollment-rollovers/create'],
            ['get', "/app/students/{$here}/guardians/add"], ['get', "/app/students/{$here}/account-link/search?q=a"],
        ] as [$method, $url]) {
            $this->web($principalA)->{$method}($url)->assertForbidden();
        }
        app('auth')->forgetGuards();
        $api = $this->withHeader('Authorization', 'Bearer '.$principalA->createToken('t')->plainTextToken);
        $api->patchJson("/api/v1/schools/{$this->school->id}/students/{$here}", ['first_name' => 'X'])->assertForbidden();
        $api->postJson("/api/v1/schools/{$this->school->id}/students/{$this->students['unplaced']->id}/enrollments", [])->assertForbidden();
    }

    #[Test]
    public function admissions_officers_and_other_campus_roles_gain_no_student_write(): void
    {
        foreach (['admissions_officer', 'front_office', 'hostel_warden', 'librarian'] as $role) {
            $user = $this->actor($role, [$this->a->id]);
            $this->web($user)->get('/app/students/onboard')->assertForbidden();
            $this->web($user)->put("/app/students/{$this->students['here']->id}/names", ['first_name' => 'X'])->assertForbidden();
        }
    }

    protected function tearDown(): void
    {
        app('auth')->forgetGuards();
        parent::tearDown();
    }
}

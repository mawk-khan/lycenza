<?php

namespace Tests\Feature\HR;

use App\Domain\HR\Infrastructure\Department;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmployeeAssignment;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\HR\Infrastructure\Position;
use App\Models\Campus;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\SchoolTimezone;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * ORG.7E-1 (ADR 0076 §43): HR operational Campus adoption.
 *
 * - The Directory and the operational overview reach only Employees with a
 *   CURRENT assignment on an in-scope Campus, restricted in SQL; filters only
 *   narrow; a multi-Campus Employee's other-Campus placement never shows.
 * - Core identity edits need EVERY current Campus in scope.
 * - Onboarding is one atomic, Campus-safe operation that never captures.
 * - Assignment writes act only on CURRENT in-scope assignments; a new one
 *   needs every current Campus plus the destination in scope.
 * - The Profile, history, employment lifecycle, rehire, import, archive and
 *   account links stay whole-School.
 */
class HrCampusScopeTest extends TestCase
{
    use CreatesTenancyFixtures;

    private School $school;

    private Campus $a;

    private Campus $b;

    private Position $teacher;

    private Position $clerk;

    private Department $deptB;

    /** @var array<string, EmploymentRecord> */
    private array $staff = [];

    private string $today;

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = $this->createSchool();
        $this->a = $this->createCampus($this->school, ['name' => 'North']);
        $this->b = $this->createCampus($this->school, ['name' => 'South']);
        $this->teacher = $this->createPosition($this->school, ['name' => 'Teacher', 'status' => 'active']);
        $this->clerk = $this->createPosition($this->school, ['name' => 'Clerk', 'status' => 'active']);
        $this->deptB = $this->createDepartment($this->school, ['name' => 'South Science', 'campus_id' => $this->b->id, 'status' => 'active']);
        $this->today = SchoolTimezone::today($this->school);

        $this->staff['onlyA'] = $this->employee('Asha OnlyA', [[$this->a, $this->teacher]]);
        $this->staff['onlyB'] = $this->employee('Bela OnlyB', [[$this->b, $this->teacher]]);
        // Primary on B, secondary on A: Campus A must never see the B placement.
        $this->staff['both'] = $this->employee('Chitra Both', [[$this->b, $this->clerk, $this->deptB], [$this->a, $this->teacher]]);
        $this->staff['schoolWide'] = $this->employee('Dev SchoolWide', [[null, $this->teacher]]);
        $this->staff['aPlusWide'] = $this->employee('Esha APlusWide', [[$this->a, $this->teacher], [null, $this->clerk]]);
        $this->staff['future'] = $this->employee('Farah Future', [[$this->a, $this->teacher]], [], ['starts_on' => CarbonImmutable::parse($this->today)->addDays(5)->toDateString()]);
        $this->staff['ended'] = $this->employee('Gita Ended', [[$this->a, $this->teacher]], ['status' => 'terminated', 'ends_on' => CarbonImmutable::parse($this->today)->subDays(3)->toDateString()]);
        $this->staff['archived'] = $this->employee('Hema Archived', [[$this->a, $this->teacher]], [], [], ['record_status' => 'archived']);
        $this->staff['unassigned'] = $this->employee('Indu Unassigned', []);
    }

    /**
     * @param  list<array{0: ?Campus, 1: Position, 2?: Department}>  $placements  first = primary
     */
    private function employee(string $name, array $placements, array $employment = [], array $assignment = [], array $person = []): EmploymentRecord
    {
        $employee = $this->createEmployee($this->school, ['full_name' => $name, ...$person]);
        $record = $this->createEmploymentRecord($employee, ['starts_on' => '2025-01-01', 'ends_on' => null, 'status' => 'active', ...$employment]);
        foreach ($placements as $i => $p) {
            $this->createEmployeeAssignment($record, $p[1], ['campus_id' => $p[0]?->id, 'department_id' => ($p[2] ?? null)?->id, 'is_primary' => $i === 0, 'starts_on' => '2025-01-01', 'ends_on' => null, ...$assignment]);
        }

        return $record;
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
        return $this->staff[$key]->employee_id;
    }

    private function assignmentOn(string $key, ?Campus $campus): EmployeeAssignment
    {
        return $this->inSchool(fn () => EmployeeAssignment::query()->where('employment_record_id', $this->staff[$key]->id)->where('campus_id', $campus?->id)->firstOrFail());
    }

    private function inSchool(callable $fn): mixed
    {
        return app(TenantContext::class)->withSchool($this->school, $fn);
    }

    /** @return list<string> */
    private function directory(User $user, string $query = ''): array
    {
        $response = $this->api($user)->getJson($this->url('/employees'.$query))->assertOk();

        return collect($response->json('data'))->pluck('display_name')->sort()->values()->all();
    }

    /** Signs the user in on the web and activates the School (the trusted session selection). */
    private function withSchoolSession(User $user): self
    {
        app('auth')->forgetGuards();
        $this->flushHeaders();
        $this->actingAs($user)->post("/app/schools/{$this->school->id}/activate");

        return $this->actingAs($user);
    }

    // --- E1-A: Directory and overview ---------------------------------------

    #[Test]
    public function the_whole_school_directory_is_unchanged(): void
    {
        $hr = $this->actor('hr_officer', [null]);

        $this->assertSame(['Asha OnlyA', 'Bela OnlyB', 'Chitra Both', 'Dev SchoolWide', 'Esha APlusWide', 'Farah Future', 'Gita Ended', 'Indu Unassigned'], $this->directory($hr));
        $row = collect($this->api($hr)->getJson($this->url('/employees'))->json('data'))->firstWhere('display_name', 'Chitra Both');
        $this->assertSame($this->b->id, $row['campus_id'], 'whole-School keeps the primary placement summary');
        $this->assertSame('South Science', $row['department_name']);
    }

    #[Test]
    public function a_campus_directory_lists_only_employees_current_in_scope(): void
    {
        $hrA = $this->actor('hr_officer', [$this->a->id]);

        // Not: only on B, School-wide only, not started, ended, archived, unassigned.
        $this->assertSame(['Asha OnlyA', 'Chitra Both', 'Esha APlusWide'], $this->directory($hrA));
        $this->assertSame(['Asha OnlyA', 'Chitra Both', 'Esha APlusWide'], $this->directory($hrA, '?include_archived=1'));
        $this->assertSame(3, $this->api($hrA)->getJson($this->url('/employees'))->json('meta.total'));
    }

    #[Test]
    public function a_multi_campus_employee_shows_only_the_in_scope_placement(): void
    {
        $hrA = $this->actor('hr_officer', [$this->a->id]);
        $manager = $this->assignmentOn('onlyB', $this->b);
        $this->inSchool(fn () => $this->assignmentOn('both', $this->b)->update(['manager_assignment_id' => $manager->id]));

        $row = collect($this->api($hrA)->getJson($this->url('/employees'))->json('data'))->firstWhere('display_name', 'Chitra Both');
        $this->assertSame($this->a->id, $row['campus_id']);
        $this->assertSame('North', $row['campus_name']);
        $this->assertSame('Teacher', $row['position_name']);
        $this->assertNull($row['department_name']);
        $this->assertNull($row['manager_display_name'], 'the B assignment\'s manager is never shown');
        $json = json_encode($this->api($hrA)->getJson($this->url('/employees'))->json());
        $this->assertStringNotContainsString($this->b->id, (string) $json);
        $this->assertStringNotContainsString('South', (string) $json);
    }

    #[Test]
    public function request_filters_only_narrow_a_campus_directory(): void
    {
        $hrA = $this->actor('hr_officer', [$this->a->id]);

        $this->assertSame([], $this->directory($hrA, "?campus_id={$this->b->id}"), 'another Campus filter matches nobody');
        $this->assertSame(['Asha OnlyA', 'Chitra Both', 'Esha APlusWide'], $this->directory($hrA, "?campus_id={$this->a->id}"));
        $this->assertSame([], $this->directory($hrA, "?department_id={$this->deptB->id}"), 'a B-placement Department never matches through an A reach');
        $this->assertSame(['Chitra Both'], $this->directory($this->actor('hr_officer', [null]), "?department_id={$this->deptB->id}"));
        $this->assertSame(['Asha OnlyA', 'Chitra Both', 'Esha APlusWide'], $this->directory($hrA, "?position_id={$this->teacher->id}"));
        $this->assertSame([], $this->directory($hrA, "?position_id={$this->clerk->id}"), 'the clerk placements are on B or School-wide');
    }

    #[Test]
    public function several_campus_grants_are_a_union_and_no_grant_is_refused(): void
    {
        $hrAB = $this->actor('hr_officer', [$this->a->id, $this->b->id]);
        $this->assertSame(['Asha OnlyA', 'Bela OnlyB', 'Chitra Both', 'Esha APlusWide'], $this->directory($hrAB));

        $nobody = $this->actor('front_office', [null]);
        $this->api($nobody)->getJson($this->url('/employees'))->assertForbidden();
    }

    #[Test]
    public function the_web_directory_offers_only_in_scope_campuses_and_safe_links(): void
    {
        $hrA = $this->actor('hr_officer', [$this->a->id]);

        $this->withSchoolSession($hrA)->get('/app/hr/employees')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('App/HR/Employees/Index')
            ->where('employees.total', 3)
            ->where('campusOptions', [['id' => $this->a->id, 'name' => 'North']])
            ->where('canManage', false)
            ->where('canOnboard', true)
            ->where('canViewProfile', false)
            ->where('canOpenHub', false));
    }

    #[Test]
    public function the_overview_is_minimized_and_scope_filtered(): void
    {
        $hrA = $this->actor('hr_officer', [$this->a->id]);

        $this->withSchoolSession($hrA)->get("/app/hr/employees/{$this->id('both')}/overview")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('App/HR/Employees/Overview')
            ->where('overview.employee.displayName', 'Chitra Both')
            ->where('overview.placement.campusId', $this->a->id)
            ->has('overview.currentAssignments', 1)
            ->where('overview.currentAssignments.0.campusId', $this->a->id)
            ->where('can.viewProfile', false)
            ->where('can.edit', false)
            ->where('can.addAssignment', false)
            ->missing('overview.employee.workEmail')
            ->missing('overview.employee.userId'));

        // The principal holds no assignments.view: no assignment section at all.
        $principal = $this->actor('principal', [$this->a->id]);
        $this->withSchoolSession($principal)->get("/app/hr/employees/{$this->id('onlyA')}/overview")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('overview.currentAssignments', null)
            ->where('can.edit', true));
    }

    #[Test]
    public function out_of_scope_employees_look_like_unknown_ones(): void
    {
        $hrA = $this->actor('hr_officer', [$this->a->id]);
        foreach (['onlyB', 'schoolWide', 'future', 'ended', 'archived', 'unassigned'] as $key) {
            $this->withSchoolSession($hrA)->get("/app/hr/employees/{$this->id($key)}/overview")->assertNotFound();
            $unknownId = (string) Str::uuid();
            $outOfScope = $this->api($hrA)->patchJson($this->url("/employees/{$this->id($key)}"), ['full_name' => 'X']);
            $unknown = $this->api($hrA)->patchJson($this->url("/employees/{$unknownId}"), ['full_name' => 'X']);
            $outOfScope->assertNotFound();
            // The same envelope; only the echoed id differs.
            $this->assertSame(
                str_replace($unknownId, 'ID', (string) json_encode(array_diff_key((array) $unknown->json('error'), ['requestId' => 1]))),
                str_replace($this->id($key), 'ID', (string) json_encode(array_diff_key((array) $outOfScope->json('error'), ['requestId' => 1]))),
                "{$key} is indistinguishable from an unknown Employee",
            );
        }
    }

    #[Test]
    public function the_profile_hub_and_history_stay_whole_school(): void
    {
        $hrA = $this->actor('hr_officer', [$this->a->id]);
        $this->withSchoolSession($hrA)->get("/app/hr/employees/{$this->id('onlyA')}")->assertForbidden();
        $this->api($hrA)->getJson($this->url("/employees/{$this->id('onlyA')}"))->assertForbidden();
        $this->api($hrA)->getJson($this->url("/employees/{$this->id('onlyA')}/activity"))->assertForbidden();
        $this->withSchoolSession($hrA)->get('/app/hr')->assertForbidden();
        foreach (['/app/hr/departments', '/app/hr/positions', '/app/hr/categories'] as $config) {
            $this->withSchoolSession($hrA)->get($config)->assertForbidden();
        }
    }

    #[Test]
    public function campus_hr_navigation_lands_on_the_staff_directory(): void
    {
        $hrA = $this->actor('hr_officer', [$this->a->id]);
        $this->withSchoolSession($hrA)->get('/app/hr/employees')->assertInertia(function (AssertableInertia $page): void {
            $keys = collect($page->toArray()['props']['navigation']['groups'])->flatMap(fn (array $g) => collect($g['items'])->pluck('key'))->all();
            $this->assertContains('staff-directory', $keys);
            foreach (['hr', 'leave', 'staff-attendance', 'payroll'] as $hidden) {
                $this->assertNotContains($hidden, $keys);
            }
        });
    }

    // --- E1-C: core manage and onboarding ------------------------------------

    #[Test]
    public function a_campus_editor_changes_core_fields_only_with_every_current_campus_in_scope(): void
    {
        $hrA = $this->actor('hr_officer', [$this->a->id]);
        $this->api($hrA)->patchJson($this->url("/employees/{$this->id('onlyA')}"), ['full_name' => 'Asha Renamed', 'work_phone' => '555'])
            ->assertOk()->assertJsonPath('data.fullName', 'Asha Renamed')->assertJsonMissingPath('data.userId');

        $both = $this->api($hrA)->patchJson($this->url("/employees/{$this->id('both')}"), ['full_name' => 'Nope']);
        $both->assertForbidden()->assertJsonPath('error.code', 'HR_WHOLE_SCHOOL_AUTHORITY_REQUIRED');
        $this->assertStringNotContainsString('South', (string) $both->json('error.message'));
        $this->api($hrA)->patchJson($this->url("/employees/{$this->id('aPlusWide')}"), ['full_name' => 'Nope'])->assertForbidden();
        $this->withSchoolSession($hrA)->get("/app/hr/employees/{$this->id('both')}/edit")->assertForbidden();
        $this->assertSame('Chitra Both', $this->inSchool(fn () => Employee::query()->find($this->id('both'))->full_name));

        $hrAB = $this->actor('hr_officer', [$this->a->id, $this->b->id]);
        $this->api($hrAB)->patchJson($this->url("/employees/{$this->id('both')}"), ['full_name' => 'Chitra Renamed'])->assertOk();
        $this->assertTrue($this->inSchool(fn () => DB::table('school_audit_events')->where('event_type', 'employee.updated')->where('subject_id', $this->id('both'))->exists()));
    }

    #[Test]
    public function a_campus_principal_edits_core_fields_but_never_onboards_or_sees_sensitive_hr(): void
    {
        $principal = $this->actor('principal', [$this->a->id]);
        $this->withSchoolSession($principal)->put("/app/hr/employees/{$this->id('onlyA')}", ['full_name' => 'Asha P'])
            ->assertRedirect("/app/hr/employees/{$this->id('onlyA')}/overview");
        $this->withSchoolSession($principal)->get('/app/hr/employees/onboard')->assertForbidden();
        $this->withSchoolSession($principal)->get("/app/hr/employees/{$this->id('onlyA')}")->assertForbidden();
        $this->api($principal)->postJson($this->url("/employees/{$this->id('onlyA')}/employment-records/{$this->staff['onlyA']->id}/assignments"), [])->assertForbidden();
    }

    #[Test]
    public function campus_onboarding_creates_employee_employment_and_assignment_atomically(): void
    {
        $hrA = $this->actor('hr_officer', [$this->a->id]);
        $this->withSchoolSession($hrA)->post('/app/hr/employees/onboard', [
            'full_name' => 'Jaya New', 'work_email' => 'jaya@example.test', 'employment_type' => 'full_time',
            'starts_on' => $this->today, 'campus_id' => $this->a->id, 'position_id' => $this->teacher->id,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $employee = $this->inSchool(fn () => Employee::query()->where('full_name', 'Jaya New')->firstOrFail());
        $this->assertNull($employee->user_id);
        $employment = $this->inSchool(fn () => EmploymentRecord::query()->where('employee_id', $employee->id)->sole());
        $this->assertSame('active', $employment->status);
        $assignment = $this->inSchool(fn () => EmployeeAssignment::query()->where('employment_record_id', $employment->id)->sole());
        $this->assertSame($this->a->id, $assignment->campus_id);
        $this->assertTrue($assignment->is_primary);
        $this->assertContains('Jaya New', $this->directory($hrA));
    }

    #[Test]
    public function campus_onboarding_refuses_outside_scope_and_leaves_nothing_behind(): void
    {
        $hrA = $this->actor('hr_officer', [$this->a->id]);
        $before = $this->inSchool(fn () => Employee::query()->count());
        $base = ['full_name' => 'Kavya X', 'employment_type' => 'full_time', 'starts_on' => $this->today, 'campus_id' => $this->a->id, 'position_id' => $this->teacher->id];
        $inactive = $this->createPosition($this->school, ['name' => 'Old', 'status' => 'inactive']);
        $existingEmail = $this->inSchool(function () {
            Employee::query()->whereKey($this->id('onlyB'))->update(['work_email' => 'bela@example.test']);

            return 'bela@example.test';
        });

        foreach ([
            'another Campus' => ['campus_id' => $this->b->id],
            'a future start' => ['starts_on' => CarbonImmutable::parse($this->today)->addDay()->toDateString()],
            'an existing identity' => ['work_email' => $existingEmail],
            'a Department of another Campus' => ['department_id' => $this->deptB->id],
            'an inactive Position (late failure)' => ['position_id' => $inactive->id],
        ] as $case => $override) {
            $response = $this->withSchoolSession($hrA)->post('/app/hr/employees/onboard', [...$base, ...$override]);
            $response->assertSessionHasErrors();
            $this->assertSame($before, $this->inSchool(fn () => Employee::query()->count()), "{$case}: nothing persists");
        }
        $errors = session('errors')?->getBag('default')->all() ?? [];
        $this->assertStringNotContainsString('bela@example.test', implode(' ', $errors));
    }

    #[Test]
    public function campus_actors_never_reach_the_declared_whole_school_hr_operations(): void
    {
        $hrA = $this->actor('hr_officer', [$this->a->id]);
        $e = $this->id('onlyA');
        $er = $this->staff['onlyA']->id;

        $this->api($hrA)->postJson($this->url('/employees'), ['full_name' => 'X'])->assertForbidden();
        $this->api($hrA)->postJson($this->url("/employees/{$e}/archive"))->assertForbidden();
        $this->api($hrA)->postJson($this->url("/employees/{$e}/restore"))->assertForbidden();
        $this->api($hrA)->postJson($this->url("/employees/{$e}/link-user"), ['user_id' => $hrA->id])->assertForbidden();
        $this->api($hrA)->postJson($this->url("/employees/{$e}/unlink-user"))->assertForbidden();
        $this->api($hrA)->postJson($this->url("/employees/{$e}/employment-records"), ['employment_type' => 'x', 'starts_on' => $this->today])->assertForbidden();
        $this->api($hrA)->patchJson($this->url("/employees/{$e}/employment-records/{$er}"), ['employment_type' => 'x'])->assertForbidden();
        $this->api($hrA)->postJson($this->url("/employees/{$e}/employment-records/{$er}/end"), ['ends_on' => $this->today])->assertForbidden();
        $this->api($hrA)->postJson($this->url("/employees/{$e}/employment-records/{$er}/separate"), ['ends_on' => $this->today])->assertForbidden();
        $this->api($hrA)->postJson($this->url("/employees/{$this->id('ended')}/rehire"), ['employment_type' => 'x', 'starts_on' => $this->today])->assertForbidden();
        $this->api($hrA)->postJson($this->url('/employee-imports'), [])->assertForbidden();

        foreach ([['get', '/app/hr/employees/create'], ['post', '/app/hr/employees'], ['get', '/app/hr/employees/import'], ['post', "/app/hr/employees/{$e}/archive"], ['post', "/app/hr/employees/{$e}/restore"], ['get', "/app/hr/employees/{$e}/account-link/search"], ['post', "/app/hr/employees/{$e}/employment-records"], ['post', "/app/hr/employees/{$e}/employment-records/{$er}/end"], ['post', "/app/hr/employees/{$e}/employment-records/{$er}/separate"], ['post', "/app/hr/employees/{$e}/rehire"]] as [$method, $path]) {
            $this->withSchoolSession($hrA)->{$method}($path)->assertForbidden();
        }
        $this->assertSame('active', $this->inSchool(fn () => Employee::query()->find($e)->record_status));
    }

    // --- E1-D: assignment management -----------------------------------------

    private function assignmentUrl(string $key, EmployeeAssignment $a, string $action): string
    {
        return $this->url("/employees/{$this->id($key)}/employment-records/{$this->staff[$key]->id}/assignments/{$a->id}/{$action}");
    }

    #[Test]
    public function a_campus_actor_ends_only_a_current_in_scope_assignment(): void
    {
        $hrA = $this->actor('hr_officer', [$this->a->id]);

        $this->api($hrA)->withHeader('Idempotency-Key', 'end-b-'.Str::random(8))->postJson($this->assignmentUrl('both', $this->assignmentOn('both', $this->b), 'end'), ['ends_on' => $this->today])->assertNotFound();
        $this->api($hrA)->withHeader('Idempotency-Key', 'end-w-'.Str::random(8))->postJson($this->assignmentUrl('aPlusWide', $this->assignmentOn('aPlusWide', null), 'end'), ['ends_on' => $this->today])->assertNotFound();
        $this->api($hrA)->withHeader('Idempotency-Key', 'end-p-'.Str::random(8))->postJson($this->assignmentUrl('both', $this->assignmentOn('both', $this->a), 'end'), ['ends_on' => CarbonImmutable::parse($this->today)->subDay()->toDateString()])
            ->assertStatus(422)->assertJsonPath('error.code', 'HR_ASSIGNMENT_UNAVAILABLE');

        $this->api($hrA)->withHeader('Idempotency-Key', 'end-a-'.Str::random(8))->postJson($this->assignmentUrl('both', $this->assignmentOn('both', $this->a), 'end'), ['ends_on' => CarbonImmutable::parse($this->today)->subDay()->addDay()->toDateString()])->assertOk();
        // The B placement is untouched and still current.
        $this->assertNull($this->assignmentOn('both', $this->b)->ends_on);
    }

    #[Test]
    public function ending_an_assignment_never_clears_another_campus_reporting_line(): void
    {
        $hrA = $this->actor('hr_officer', [$this->a->id]);
        $managerA = $this->assignmentOn('onlyA', $this->a);
        $this->inSchool(fn () => EmployeeAssignment::query()->whereKey($this->assignmentOn('onlyB', $this->b)->id)->update(['manager_assignment_id' => $managerA->id]));

        $this->api($hrA)->withHeader('Idempotency-Key', 'end-m-'.Str::random(8))->postJson($this->assignmentUrl('onlyA', $managerA, 'end'), ['ends_on' => $this->today])
            ->assertStatus(422)->assertJsonPath('error.code', 'HR_ASSIGNMENT_UNAVAILABLE');
        $this->assertSame($managerA->id, $this->assignmentOn('onlyB', $this->b)->manager_assignment_id, 'the B reporting line is untouched');
        $this->assertNull($this->assignmentOn('onlyA', $this->a)->ends_on);
    }

    #[Test]
    public function campus_writes_never_backdate_or_extend_history(): void
    {
        $hrA = $this->actor('hr_officer', [$this->a->id]);
        $yesterday = CarbonImmutable::parse($this->today)->subDay()->toDateString();

        $this->api($hrA)->withHeader('Idempotency-Key', 'bd-'.Str::random(8))
            ->postJson($this->url("/employees/{$this->id('onlyA')}/employment-records/{$this->staff['onlyA']->id}/assignments"), ['starts_on' => $yesterday, 'position_id' => $this->clerk->id, 'campus_id' => $this->a->id])
            ->assertStatus(422)->assertJsonPath('error.code', 'HR_ASSIGNMENT_UNAVAILABLE');

        $a = $this->assignmentOn('onlyA', $this->a);
        $soon = CarbonImmutable::parse($this->today)->addDays(10)->toDateString();
        $this->inSchool(fn () => EmployeeAssignment::query()->whereKey($a->id)->update(['ends_on' => $soon]));
        $this->api($hrA)->withHeader('Idempotency-Key', 'ext-'.Str::random(8))->postJson($this->assignmentUrl('onlyA', $a, 'end'), ['ends_on' => CarbonImmutable::parse($soon)->addDays(30)->toDateString()])
            ->assertStatus(422)->assertJsonPath('error.code', 'HR_ASSIGNMENT_UNAVAILABLE');
        $this->api($hrA)->withHeader('Idempotency-Key', 'shr-'.Str::random(8))->postJson($this->assignmentUrl('onlyA', $a, 'end'), ['ends_on' => $this->today])->assertOk();

        $this->withSchoolSession($hrA)->post('/app/hr/employees/onboard', [
            'full_name' => 'Lata Back', 'employment_type' => 'full_time', 'starts_on' => $yesterday, 'campus_id' => $this->a->id, 'position_id' => $this->teacher->id,
        ])->assertSessionHasErrors('employee');
        $this->assertFalse($this->inSchool(fn () => Employee::query()->where('full_name', 'Lata Back')->exists()));
    }

    #[Test]
    public function a_reporting_manager_outside_the_scope_is_never_named(): void
    {
        $hrA = $this->actor('hr_officer', [$this->a->id]);
        $managerOnB = $this->assignmentOn('onlyB', $this->b);
        $managerOnA = $this->assignmentOn('aPlusWide', $this->a);
        $this->inSchool(fn () => EmployeeAssignment::query()->whereKey($this->assignmentOn('onlyA', $this->a)->id)->update(['manager_assignment_id' => $managerOnB->id]));
        $this->inSchool(fn () => EmployeeAssignment::query()->whereKey($this->assignmentOn('both', $this->a)->id)->update(['manager_assignment_id' => $managerOnA->id]));

        $rows = collect($this->api($hrA)->getJson($this->url('/employees'))->json('data'))->keyBy('display_name');
        $this->assertNull($rows['Asha OnlyA']['manager_display_name']);
        $this->assertNull($rows['Asha OnlyA']['manager_employee_id']);
        $this->assertSame('Esha APlusWide', $rows['Chitra Both']['manager_display_name']);
        $this->withSchoolSession($hrA)->get("/app/hr/employees/{$this->id('onlyA')}/overview")->assertInertia(fn (AssertableInertia $page) => $page
            ->where('overview.placement.managerName', null)->where('overview.currentAssignments.0.managerName', null));
        // Whole-School unchanged.
        $whole = collect($this->api($this->actor('hr_officer', [null]))->getJson($this->url('/employees'))->json('data'))->keyBy('display_name');
        $this->assertSame('Bela OnlyB', $whole['Asha OnlyA']['manager_display_name']);
    }

    #[Test]
    public function a_new_assignment_never_captures_an_employee(): void
    {
        $hrA = $this->actor('hr_officer', [$this->a->id]);
        $create = fn (User $u, string $key, Campus $campus) => $this->api($u)->withHeader('Idempotency-Key', 'asg-'.Str::random(10))
            ->postJson($this->url("/employees/{$this->id($key)}/employment-records/{$this->staff[$key]->id}/assignments"), ['starts_on' => $this->today, 'position_id' => $this->clerk->id, 'campus_id' => $campus->id]);

        $create($hrA, 'onlyA', $this->a)->assertCreated()->assertJsonPath('data.campusId', $this->a->id);
        $create($hrA, 'onlyA', $this->b)->assertStatus(422)->assertJsonPath('error.code', 'HR_ASSIGNMENT_UNAVAILABLE');
        $create($hrA, 'both', $this->a)->assertForbidden()->assertJsonPath('error.code', 'HR_WHOLE_SCHOOL_AUTHORITY_REQUIRED');
        foreach (['onlyB', 'unassigned', 'ended', 'schoolWide', 'future'] as $key) {
            $create($hrA, $key, $this->a)->assertNotFound();
        }

        $hrAB = $this->actor('hr_officer', [$this->a->id, $this->b->id]);
        $create($hrAB, 'both', $this->a)->assertCreated();
        $create($hrAB, 'onlyA', $this->b)->assertCreated();
    }

    #[Test]
    public function set_primary_and_manager_stay_inside_the_scope(): void
    {
        $hrA = $this->actor('hr_officer', [$this->a->id]);
        $key = fn () => 'k-'.Str::random(10);

        // Making the A assignment primary would demote the B primary.
        $this->api($hrA)->withHeader('Idempotency-Key', $key())->postJson($this->assignmentUrl('both', $this->assignmentOn('both', $this->a), 'primary'))
            ->assertForbidden()->assertJsonPath('error.code', 'HR_WHOLE_SCHOOL_AUTHORITY_REQUIRED');
        $this->assertTrue($this->assignmentOn('both', $this->b)->is_primary);

        $managerOnB = $this->assignmentOn('onlyB', $this->b);
        $managerOnA = $this->assignmentOn('aPlusWide', $this->a);
        $this->api($hrA)->withHeader('Idempotency-Key', $key())->postJson($this->assignmentUrl('onlyA', $this->assignmentOn('onlyA', $this->a), 'manager'), ['manager_assignment_id' => $managerOnB->id])->assertNotFound();
        $this->api($hrA)->withHeader('Idempotency-Key', $key())->postJson($this->assignmentUrl('onlyA', $this->assignmentOn('onlyA', $this->a), 'manager'), ['manager_assignment_id' => $managerOnA->id])
            ->assertOk()->assertJsonPath('data.managerAssignmentId', $managerOnA->id);

        $hrAB = $this->actor('hr_officer', [$this->a->id, $this->b->id]);
        $this->api($hrAB)->withHeader('Idempotency-Key', $key())->postJson($this->assignmentUrl('both', $this->assignmentOn('both', $this->a), 'primary'))->assertOk();
        $this->assertFalse($this->assignmentOn('both', $this->b)->is_primary);
    }

    #[Test]
    public function manager_candidates_are_current_in_scope_assignments_only(): void
    {
        $hrA = $this->actor('hr_officer', [$this->a->id]);
        $subordinate = $this->assignmentOn('onlyA', $this->a);
        $response = $this->withSchoolSession($hrA)->getJson("/app/hr/employees/{$this->id('onlyA')}/employment-records/{$this->staff['onlyA']->id}/assignments/{$subordinate->id}/manager-candidates?q=a")->assertOk();
        $labels = collect($response->json('candidates'))->pluck('label')->implode(' | ');
        $this->assertStringContainsString('Chitra Both', $labels);
        $this->assertStringContainsString('Esha APlusWide', $labels);
        foreach (['Bela OnlyB', 'Dev SchoolWide', 'Gita Ended', 'Farah Future', 'South'] as $absent) {
            $this->assertStringNotContainsString($absent, $labels);
        }
    }

    #[Test]
    public function an_inactive_campus_confers_nothing(): void
    {
        $hrA = $this->actor('hr_officer', [$this->a->id]);
        $this->inSchool(fn () => Campus::query()->whereKey($this->a->id)->update(['status' => 'inactive']));
        app('auth')->forgetGuards();

        $this->api($hrA)->getJson($this->url('/employees'))->assertForbidden();
        $this->assertNotContains('Asha OnlyA', $this->directory($this->actor('hr_officer', [$this->b->id])));
    }

    #[Test]
    public function another_schools_records_never_participate(): void
    {
        $other = $this->createSchool();
        $otherCampus = $this->createCampus($other);
        $hrA = $this->actor('hr_officer', [$this->a->id]);

        $this->assertSame([], $this->directory($hrA, "?campus_id={$otherCampus->id}"));
        $foreign = app(TenantContext::class)->withSchool($other, fn () => $this->createEmployee($other, ['full_name' => 'Foreign']));
        $this->api($hrA)->patchJson($this->url("/employees/{$foreign->id}"), ['full_name' => 'X'])->assertNotFound();
    }

    /** Guards against a test-order dependent token cache. */
    protected function tearDown(): void
    {
        app('auth')->forgetGuards();
        parent::tearDown();
    }
}

<?php

namespace Tests\Feature\HR\Workforce;

use App\Domain\HR\Infrastructure\EmployeeAssignment;
use App\Domain\HR\Infrastructure\EmployeePlacementChange;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\HR\Infrastructure\EmploymentTerm;
use App\Models\Campus;
use App\Models\School;
use App\Models\User;
use App\Support\Operations\CheckResult;
use App\Support\Operations\DatabaseRoleVerifier;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * HR.1A over HTTP: the terms change and the named placement change (allow
 * AND deny, tenant 404), the Profile's workforce history and catalogues,
 * onboarding/employment forms requiring a closed staff type, the API
 * refusing in-place terms and future ends (S-8, X-3), the account kind in
 * the link picker (S-15), the date-aware directory state (ORG10-D01) and the
 * verifier's workforce check.
 */
class WorkforceWebTest extends TestCase
{
    use CreatesTenancyFixtures;

    private School $school;

    private Campus $a;

    private Campus $b;

    private EmploymentRecord $employment;

    private EmployeeAssignment $primary;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-15T06:00:00Z'));
        $this->school = $this->createSchool(['timezone' => 'Asia/Kolkata']);
        $this->a = $this->createCampus($this->school, ['name' => 'North']);
        $this->b = $this->createCampus($this->school, ['name' => 'South']);
        $position = $this->createPosition($this->school, ['name' => 'Teacher', 'status' => 'active']);
        $this->employment = $this->createEmploymentRecord($this->createEmployee($this->school), ['starts_on' => '2026-04-01', 'staff_type' => 'teaching']);
        $this->primary = $this->createEmployeeAssignment($this->employment, $position, ['campus_id' => $this->a->id, 'is_primary' => true, 'starts_on' => '2026-04-01']);
    }

    private function signedIn(User $user): self
    {
        app('auth')->forgetGuards();
        $this->flushHeaders();
        $this->actingAs($user)->post("/app/schools/{$this->school->id}/activate");

        return $this->actingAs($user);
    }

    private function page(): string
    {
        return "/app/hr/employees/{$this->employment->employee_id}";
    }

    private function inSchool(callable $fn): mixed
    {
        return app(TenantContext::class)->withSchool($this->school, $fn);
    }

    #[Test]
    public function a_whole_school_hr_officer_records_dated_terms_and_others_are_refused(): void
    {
        $payload = ['effective_from' => '2026-10-01', 'employment_type' => 'permanent', 'staff_type' => 'non_teaching', 'reason_code' => 'reclassification'];
        $url = "{$this->page()}/employment-records/{$this->employment->id}/terms";

        $this->signedIn($this->createUserWithCapabilities($this->school, ['hr.employees.assignments.view']))->post($url, $payload)->assertForbidden();
        $campusHr = $this->createUser();
        $this->assignSchoolRole($this->createMembership($campusHr, $this->school), 'hr_officer', $this->a->id);
        $this->signedIn($campusHr)->post($url, $payload)->assertForbidden();
        $this->assertSame(1, $this->inSchool(fn () => EmploymentTerm::query()->where('employment_record_id', $this->employment->id)->count()));

        $hr = $this->fullHrActor($this->school);
        $this->signedIn($hr)->from($this->page())->post($url, [...$payload, 'staff_type' => 'visiting'])->assertSessionHasErrors('staff_type');
        $this->signedIn($hr)->from($this->page())->post($url, [...$payload, 'employment_type' => 'full_time'])->assertSessionHasErrors('employment_type');
        $this->signedIn($hr)->post($url, $payload)->assertRedirect($this->page());
        $this->assertSame(2, $this->inSchool(fn () => EmploymentTerm::query()->where('employment_record_id', $this->employment->id)->count()));

        // Another School's employment id is unknown here.
        $foreign = $this->createEmploymentRecord($this->createEmployee($this->createSchool()));
        $this->signedIn($hr)->post("{$this->page()}/employment-records/{$foreign->id}/terms", $payload)->assertNotFound();
    }

    #[Test]
    public function the_named_placement_change_route_allows_hr_and_refuses_others(): void
    {
        $url = "{$this->page()}/employment-records/{$this->employment->id}/assignments/{$this->primary->id}/placement-change";
        $payload = ['change_kind' => 'transfer', 'effective_on' => '2026-11-15', 'reason_code' => 'staffing_requirement', 'position_id' => $this->primary->position_id, 'campus_id' => $this->b->id];

        $this->signedIn($this->createUserWithCapabilities($this->school, ['hr.employees.view']))->post($url, $payload)->assertForbidden();
        $this->signedIn($this->fullHrActor($this->school))->from($this->page())->post($url, [...$payload, 'change_kind' => 'demotion'])->assertSessionHasErrors('change_kind');
        $this->signedIn($this->fullHrActor($this->school))->from($this->page())->post($url, [...$payload, 'campus_id' => $this->a->id])->assertSessionHasErrors('change_kind');
        $this->assertSame(0, $this->inSchool(fn () => EmployeePlacementChange::query()->count()));

        $this->signedIn($this->fullHrActor($this->school))->post($url, $payload)->assertRedirect($this->page());
        $this->assertSame(['transfer'], $this->inSchool(fn () => EmployeePlacementChange::query()->pluck('change_kind')->all()));
    }

    #[Test]
    public function the_profile_shows_history_and_closed_catalogues_only_to_the_employment_tier(): void
    {
        $props = $this->signedIn($this->fullHrActor($this->school))->get($this->page())->assertOk()->viewData('page')['props'];
        $history = $props['workforceHistory'][$this->employment->id];
        $this->assertSame('Teaching', $history['currentTerms']['staffTypeLabel']);
        $this->assertCount(1, $history['terms']);
        $this->assertSame(['permanent', 'probationary', 'fixed_term', 'part_time', 'temporary', 'contract', 'consultant'], array_column($props['workforceOptions']['employmentTypes'], 'value'));
        $this->assertSame(['teaching', 'non_teaching', 'outsourced'], array_column($props['workforceOptions']['staffTypes'], 'value'));
        $this->assertNotEmpty($props['campuses']);

        $personalOnly = $this->createUserWithCapabilities($this->school, ['hr.employees.view', 'hr.employees.personal.view']);
        $props = $this->signedIn($personalOnly)->get($this->page())->assertOk()->viewData('page')['props'];
        $this->assertSame([], $props['workforceHistory']);
        $this->assertSame([], $props['campuses']);
    }

    #[Test]
    public function every_web_path_that_starts_an_employment_requires_a_closed_staff_type(): void
    {
        $hr = $this->fullHrActor($this->school);
        $position = $this->createPosition($this->school, ['status' => 'active']);

        $this->signedIn($hr)->from('/app/hr/employees/onboard')->post('/app/hr/employees/onboard', [
            'full_name' => 'Nia New', 'employment_type' => 'permanent', 'starts_on' => '2026-11-15', 'campus_id' => $this->a->id, 'position_id' => $position->id,
        ])->assertSessionHasErrors('staff_type');
        $this->signedIn($hr)->post('/app/hr/employees/onboard', [
            'full_name' => 'Nia New', 'employment_type' => 'permanent', 'staff_type' => 'outsourced', 'starts_on' => '2026-11-15', 'campus_id' => $this->a->id, 'position_id' => $position->id,
        ])->assertSessionHasNoErrors();
        $this->assertSame('outsourced', $this->inSchool(fn () => EmploymentRecord::query()->whereHas('employee', fn ($q) => $q->where('full_name', 'Nia New'))->value('staff_type')));
        $this->assertSame('initial_placement', $this->inSchool(fn () => EmployeePlacementChange::query()->latest('created_at')->value('change_kind')));

        $other = $this->createEmployee($this->school);
        $this->signedIn($hr)->from('/app/hr/employees/'.$other->id)->post("/app/hr/employees/{$other->id}/employment-records", ['employment_type' => 'permanent', 'starts_on' => '2026-11-01'])->assertSessionHasErrors('staff_type');
    }

    #[Test]
    public function the_api_never_overwrites_terms_and_never_ends_in_the_future(): void
    {
        $hr = $this->fullHrActor($this->school);
        $api = "/api/v1/schools/{$this->school->id}/employees/{$this->employment->employee_id}/employment-records/{$this->employment->id}";

        $this->actingAs($hr, 'sanctum')->patchJson($api, ['employment_type' => 'contract'])->assertUnprocessable()->assertJsonStructure(['error' => ['errors' => ['employment_type']]]);
        $this->flushHeaders();
        $this->actingAs($hr, 'sanctum')->patchJson($api, ['probation_ends_on' => '2026-12-31'])->assertOk();
        $this->flushHeaders();
        $this->actingAs($hr, 'sanctum')->withHeader('Idempotency-Key', (string) Str::uuid())->postJson("{$api}/end", ['ends_on' => '2026-12-31'])->assertConflict()->assertJsonPath('error.code', 'SEPARATION_USE_CASE');
        $this->assertNull($this->inSchool(fn () => EmploymentRecord::query()->whereKey($this->employment->id)->value('ends_on')));
        $this->signedIn($hr)->from($this->page())->post("{$this->page()}/employment-records/{$this->employment->id}/end", ['ends_on' => '2026-12-31'])->assertSessionHasErrors();
    }

    #[Test]
    public function the_account_picker_shows_each_accounts_kind(): void
    {
        $employee = $this->createEmployee($this->school, ['user_id' => null]);
        $staff = $this->createUser(['name' => 'Kind Staff']);
        $this->assignSchoolRole($this->createMembership($staff, $this->school), 'teacher');
        $guardian = $this->createUser(['name' => 'Kind Guardian']);
        $this->createMembership($guardian, $this->school);
        $member = $this->createUser(['name' => 'Kind Member']);
        $this->createMembership($member, $this->school);

        $candidates = collect($this->signedIn($this->fullHrActor($this->school))->getJson("/app/hr/employees/{$employee->id}/account-link/search?q=Kind")->assertOk()->json('candidates'))->keyBy('name');

        $this->assertSame('staff', $candidates['Kind Staff']['accountKind']);
        $this->assertSame('member', $candidates['Kind Member']['accountKind']);
        $this->assertSame('member', $candidates['Kind Guardian']['accountKind'], 'no grant: not staff');
    }

    #[Test]
    public function a_future_hire_written_as_active_is_pre_joining_never_current(): void
    {
        $future = $this->createEmploymentRecord($this->createEmployee($this->school, ['full_name' => 'Zara Future']), ['starts_on' => '2026-12-01', 'status' => 'active']);
        unset($future);
        $hr = $this->fullHrActor($this->school);
        $names = fn (string $q) => collect($this->signedIn($hr)->get("/app/hr/employees?employment={$q}")->assertOk()->viewData('page')['props']['employees']['data'])->pluck('display_name')->all();

        $this->assertNotContains('Zara Future', $names('current'), 'ORG10-D01');
        $this->assertNotEmpty($names('current'), 'the current list is not vacuous');
        $this->assertContains('Zara Future', $names('pre_joining'));
    }

    #[Test]
    public function the_verifier_proves_the_workforce_backstops_are_present(): void
    {
        $checks = collect(app(DatabaseRoleVerifier::class)->verify())->keyBy('code');

        $this->assertSame(CheckResult::PASS, $checks['hr_workforce_invariants']->status);
        $this->assertSame(CheckResult::PASS, $checks['runtime_destructive_privileges_restricted']->status, 'employment_terms / employee_placement_changes: no runtime UPDATE or DELETE');
    }
}

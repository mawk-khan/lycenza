<?php

namespace Tests\Feature\HR\Reporting;

use App\Domain\HR\Application\ReportingLine;
use App\Domain\HR\Infrastructure\EmployeeAssignment;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\HR\Infrastructure\ReportingRelationship;
use App\Models\Campus;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * HR.3A over HTTP: the set-manager routes (web and API) record a dated,
 * reasoned line; the whole-School correction route (allow AND deny, tenant
 * 404); the Profile's reporting history and reasons. The clock is frozen
 * (School-local 2026-11-15).
 */
class ReportingWebTest extends TestCase
{
    use CreatesTenancyFixtures;

    private School $school;

    private Campus $north;

    /** @var array<string, array{0: EmploymentRecord, 1: EmployeeAssignment}> */
    private array $staff = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-15T06:00:00Z'));
        $this->school = $this->createSchool(['timezone' => 'Asia/Kolkata']);
        $this->north = $this->createCampus($this->school, ['name' => 'North']);
        $position = $this->createPosition($this->school, ['name' => 'Teacher', 'status' => 'active']);
        foreach (['sub', 'manager', 'other'] as $key) {
            $employment = $this->createEmploymentRecord($this->createEmployee($this->school, ['full_name' => ucfirst($key).' Person']), ['starts_on' => '2026-01-01', 'staff_type' => 'teaching']);
            $this->staff[$key] = [$employment, $this->createEmployeeAssignment($employment, $position, ['campus_id' => $this->north->id, 'is_primary' => true, 'starts_on' => '2026-01-01'])];
        }
    }

    private function signedIn(User $user): self
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

    private function managerUrl(string $key = 'sub'): string
    {
        [$employment, $assignment] = $this->staff[$key];

        return "/app/hr/employees/{$employment->employee_id}/employment-records/{$employment->id}/assignments/{$assignment->id}/manager";
    }

    private function managerOf(string $key, string $on): ?string
    {
        return app(ReportingLine::class)->managerOf($this->school, $this->staff[$key][0]->id, $on);
    }

    #[Test]
    public function the_web_route_records_a_dated_reasoned_line_and_refuses_without_a_reason(): void
    {
        $hr = $this->fullHrActor($this->school);
        $page = "/app/hr/employees/{$this->staff['sub'][0]->employee_id}";

        $this->signedIn($hr)->from($page)->post($this->managerUrl(), ['manager_assignment_id' => $this->staff['manager'][1]->id])->assertSessionHasErrors('reason_code');
        $this->signedIn($hr)->from($page)->post($this->managerUrl(), ['manager_assignment_id' => $this->staff['manager'][1]->id, 'reason_code' => 'because'])->assertSessionHasErrors('reason_code');
        $this->assertSame(0, $this->inSchool(fn () => ReportingRelationship::query()->count()));

        $this->signedIn($hr)->post($this->managerUrl(), ['manager_assignment_id' => $this->staff['manager'][1]->id, 'reason_code' => 'restructuring', 'effective_on' => '2026-10-01'])->assertRedirect($page)->assertSessionHasNoErrors();
        $this->assertNull($this->managerOf('sub', '2026-09-30'));
        $this->assertSame($this->staff['manager'][0]->employee_id, $this->managerOf('sub', '2026-10-01'), 'a whole-School officer may record a past date');

        // Removal from a date, with its own reason.
        $this->signedIn($hr)->post($this->managerUrl(), ['manager_assignment_id' => null, 'reason_code' => 'manager_departure', 'effective_on' => '2026-12-01'])->assertSessionHasNoErrors();
        $this->assertSame($this->staff['manager'][0]->employee_id, $this->managerOf('sub', '2026-11-30'));
        $this->assertNull($this->managerOf('sub', '2026-12-01'));

        $this->signedIn($this->createUserWithCapabilities($this->school, ['hr.employees.view', 'hr.employees.assignments.view']))
            ->post($this->managerUrl(), ['manager_assignment_id' => $this->staff['other'][1]->id, 'reason_code' => 'restructuring'])->assertForbidden();
    }

    #[Test]
    public function the_api_route_answers_the_line_and_needs_a_reason(): void
    {
        [$employment, $assignment] = $this->staff['sub'];
        $url = "/api/v1/schools/{$this->school->id}/employees/{$employment->employee_id}/employment-records/{$employment->id}/assignments/{$assignment->id}/manager";
        $api = fn () => $this->withHeader('Authorization', 'Bearer '.$this->fullHrActor($this->school)->createToken('t')->plainTextToken)->withHeader('Idempotency-Key', 'k-'.Str::random(12));

        $this->assertStringContainsString('reason_code', $api()->postJson($url, ['manager_assignment_id' => $this->staff['manager'][1]->id])->assertStatus(422)->getContent());
        app('auth')->forgetGuards();
        $api()->postJson($url, ['manager_assignment_id' => $this->staff['manager'][1]->id, 'reason_code' => 'organisational_change'])
            ->assertOk()
            ->assertJsonPath('data.subordinateEmploymentId', $employment->id)
            ->assertJsonPath('data.managerEmploymentId', $this->staff['manager'][0]->id)
            ->assertJsonPath('data.startsOn', '2026-11-15')
            ->assertJsonPath('data.startedVia', 'assigned');
    }

    #[Test]
    public function only_a_whole_school_officer_corrects_a_line_and_the_profile_shows_the_trail(): void
    {
        $hr = $this->fullHrActor($this->school);
        $this->signedIn($hr)->post($this->managerUrl(), ['manager_assignment_id' => $this->staff['manager'][1]->id, 'reason_code' => 'organisational_change', 'effective_on' => '2026-02-01']);
        $line = $this->inSchool(fn () => ReportingRelationship::query()->sole());
        [$employment] = $this->staff['sub'];
        $url = "/app/hr/employees/{$employment->employee_id}/employment-records/{$employment->id}/reporting-lines/{$line->id}/correct";
        $payload = ['manager_assignment_id' => $this->staff['other'][1]->id, 'reason_code' => 'administrative_correction'];

        $campusHr = $this->createUser();
        $this->assignSchoolRole($this->createMembership($campusHr, $this->school), 'hr_officer', $this->north->id);
        $this->signedIn($campusHr)->post($url, $payload)->assertForbidden();
        $this->signedIn($this->createUserWithCapabilities($this->school, ['hr.employees.view', 'hr.employees.assignments.view']))->post($url, $payload)->assertForbidden();
        $this->signedIn($hr)->from('/app/hr/employees')->post($url, [...$payload, 'reason_code' => ''])->assertSessionHasErrors('reason_code');
        // A line of another employment is unknown on this URL.
        [$otherEmployment] = $this->staff['other'];
        $this->signedIn($hr)->post("/app/hr/employees/{$otherEmployment->employee_id}/employment-records/{$otherEmployment->id}/reporting-lines/{$line->id}/correct", $payload)->assertNotFound();
        $this->assertNull($this->inSchool(fn () => $line->fresh()->voided_at));

        $this->signedIn($hr)->post($url, $payload)->assertRedirect("/app/hr/employees/{$employment->employee_id}")->assertSessionHasNoErrors();
        $this->assertSame($this->staff['other'][0]->employee_id, $this->managerOf('sub', '2026-06-01'));

        $props = $this->signedIn($hr)->get("/app/hr/employees/{$employment->employee_id}")->assertOk()->viewData('page')['props'];
        $trail = $props['workforceHistory'][$employment->id]['reporting'];
        $this->assertSame([['Manager Person', true, 'Administrative correction'], ['Other Person', false, null]], array_map(fn (array $r) => [$r['managerName'], $r['voided'], $r['voidReasonLabel']], $trail));
        $this->assertSame('Corrected', $trail[1]['startedLabel']);
        $this->assertSame(['organisational_change', 'restructuring', 'employee_transfer', 'manager_departure', 'administrative_correction'], array_column($props['workforceOptions']['reportingReasons'], 'value'));
    }

    #[Test]
    public function a_campus_officer_records_from_today_on_through_the_overview(): void
    {
        $campusHr = $this->createUser();
        $this->assignSchoolRole($this->createMembership($campusHr, $this->school), 'hr_officer', $this->north->id);
        [$employment] = $this->staff['sub'];

        $props = $this->signedIn($campusHr)->get("/app/hr/employees/{$employment->employee_id}/overview")->assertOk()->viewData('page')['props'];
        $this->assertSame('2026-11-15', $props['today']);
        $this->assertNotEmpty($props['reportingReasons']);

        $this->signedIn($campusHr)->from("/app/hr/employees/{$employment->employee_id}/overview")
            ->post($this->managerUrl(), ['manager_assignment_id' => $this->staff['manager'][1]->id, 'reason_code' => 'organisational_change', 'effective_on' => '2026-11-14'])
            ->assertSessionHasErrors('manager_assignment_id');
        $this->signedIn($campusHr)->post($this->managerUrl(), ['manager_assignment_id' => $this->staff['manager'][1]->id, 'reason_code' => 'organisational_change', 'effective_on' => '2026-11-15'])->assertSessionHasNoErrors();
        $this->assertSame($this->staff['manager'][0]->employee_id, $this->managerOf('sub', '2026-11-15'));
    }
}

<?php

namespace Tests\Feature\HR;

use App\Domain\HR\Application\EmployeeLifecycleService;
use App\Domain\HR\Application\Exceptions\InvalidEmploymentEffectiveDateException;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Models\Campus;
use App\Models\School;
use App\Models\User;
use App\Support\Authorization\Campus\CampusScopeResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * ORG.7E-0 (ADR 0076 §42): the consumers normalized onto the canonical
 * current-placement rule, and the pin that HR is still not Campus-adopted.
 */
class HrCurrentPlacementConsumersTest extends TestCase
{
    use CreatesTenancyFixtures;

    private School $school;

    private Campus $a;

    private Campus $b;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = $this->createSchool(['timezone' => 'Asia/Kolkata']);
        $this->a = $this->createCampus($this->school, ['name' => 'North']);
        $this->b = $this->createCampus($this->school, ['name' => 'South']);
        $this->admin = $this->createUser();
        $this->assignSchoolRole($this->createMembership($this->admin, $this->school), 'school_admin');
    }

    /** An Employee with an employment and one assignment per given Campus (the first primary). */
    private function employee(string $name, array $campuses, array $employment = []): EmploymentRecord
    {
        $person = $this->createEmployee($this->school, ['full_name' => $name]);
        $record = $this->createEmploymentRecord($person, ['starts_on' => '2026-01-01', 'ends_on' => null, 'status' => 'active', ...$employment]);
        $position = $this->createPosition($this->school);
        foreach ($campuses as $i => $campus) {
            $this->createEmployeeAssignment($record, $position, ['campus_id' => $campus->id, 'is_primary' => $i === 0, 'starts_on' => '2026-01-01', 'ends_on' => null]);
        }

        return $record;
    }

    /** @return list<string> */
    private function directoryNames(?Campus $campus): array
    {
        app('auth')->forgetGuards();
        $token = $this->admin->createToken('test-device')->plainTextToken;
        $query = $campus === null ? '' : '?campus_id='.$campus->id;

        return collect($this->withHeader('Authorization', 'Bearer '.$token)->getJson("/api/v1/schools/{$this->school->id}/employees{$query}")->assertOk()->json('data'))
            ->pluck('display_name')->sort()->values()->all();
    }

    #[Test]
    public function the_directory_campus_filter_is_operational_campus_membership(): void
    {
        $this->travelTo(Carbon::parse('2026-10-19 20:00:00', 'UTC')); // 20 Oct in Asia/Kolkata
        $this->employee('Both Campuses', [$this->a, $this->b]);
        $this->employee('South Only', [$this->b]);
        $this->employee('Leaving Later', [$this->b], ['status' => 'separated', 'ends_on' => '2026-12-31']);
        $this->employee('Starts Tomorrow Local', [$this->b], ['starts_on' => '2026-10-21']);
        // Starts on the School's 20 Oct, which is still 19 Oct in UTC: proves the local date.
        $today = $this->employee('Starts Today Local', [], ['starts_on' => '2026-10-20']);
        $this->createEmployeeAssignment($today, $this->createPosition($this->school), ['campus_id' => $this->b->id, 'starts_on' => '2026-10-20', 'ends_on' => null]);

        $this->assertSame(['Both Campuses', 'South Only', 'Starts Today Local'], $this->directoryNames($this->b), 'a secondary assignment counts; a terminal employment or one not yet started does not');
        $this->assertSame(['Both Campuses'], $this->directoryNames($this->a));

        app(TenantContext::class)->withSchool($this->school, fn () => $this->b->forceFill(['status' => 'inactive'])->save());
        $this->assertSame([], $this->directoryNames($this->b), 'an inactive Campus places nobody');
    }

    #[Test]
    public function separation_judges_future_on_the_schools_local_date(): void
    {
        $actor = $this->createUserWithCapabilities($this->school, ['hr.employees.assignments.manage']);
        $record = $this->employee('Leaver', [$this->a]);
        // 20:00 UTC on 19 Oct is already 20 Oct in Asia/Kolkata: 20 Oct is not future.
        $this->travelTo(Carbon::parse('2026-10-19 20:00:00', 'UTC'));
        $ended = app(TenantContext::class)->withSchool($this->school, fn () => app(EmployeeLifecycleService::class)->separate($record, '2026-10-20', $actor));
        $this->assertSame('2026-10-20', $ended->ends_on->toDateString());

        $next = $this->employee('Next', [$this->a]);
        $this->expectException(InvalidEmploymentEffectiveDateException::class);
        app(TenantContext::class)->withSchool($this->school, fn () => app(EmployeeLifecycleService::class)->separate($next, '2026-10-21', $actor));
    }

    #[Test]
    public function a_campus_scoped_grant_still_gains_nothing_from_hr_capabilities(): void
    {
        $principal = $this->createUser();
        $this->assignSchoolRole($this->createMembership($principal, $this->school), 'principal', $this->a->id);
        $this->employee('Here', [$this->a]);

        foreach (['hr.employees.view', 'hr.employees.manage', 'hr.employees.personal.view', 'hr.employees.assignments.manage'] as $capability) {
            $this->assertFalse(Gate::forUser($principal)->allows('capability', [$capability, $this->school]), $capability);
            $this->assertTrue(app(CampusScopeResolver::class)->campusScope($principal, $capability, $this->school)->isNone(), "{$capability} is not Campus-adopted");
        }
        $token = $principal->createToken('test-device')->plainTextToken;
        $this->withHeader('Authorization', 'Bearer '.$token)->getJson("/api/v1/schools/{$this->school->id}/employees")->assertForbidden();
    }
}

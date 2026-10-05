<?php

namespace Tests\Feature\StaffSelfService;

use App\Domain\HR\Application\EmploymentCoverage;
use App\Domain\Identity\Application\Staff\StaffRoleCatalog;
use App\Models\Role;
use App\Models\SchoolMembership;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\StaffSelfService\Concerns\CreatesSelfServiceFixtures;
use Tests\TestCase;

/**
 * HRX.4 (ADR 0065 §25.1-§25.3): the self-service capabilities are a separate
 * bundle; the role is never an enforcement condition; ActingEmployee is
 * re-resolved per School; a lifecycle change ends access.
 */
class StaffSelfServiceAuthorizationTest extends TestCase
{
    use CreatesSelfServiceFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-05 10:00:00');
    }

    private function as(User $user): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$user->createToken('test-device')->plainTextToken);
    }

    /** @return list<string> */
    private function roleCapabilities(string $role): array
    {
        return DB::table('roles as r')->join('role_capabilities as rc', 'rc.role_id', '=', 'r.id')->where('r.key', $role)->orderBy('rc.capability_key')->pluck('rc.capability_key')->all();
    }

    #[Test]
    public function the_staff_self_service_role_is_exactly_the_three_own_capabilities_and_teacher_is_unchanged(): void
    {
        $this->assertSame(['hr.leave.self', 'hr.staff_attendance.self', 'payroll.payslips.self'], $this->roleCapabilities('staff_self_service'));
        $this->assertSame('school', DB::table('roles')->where('key', 'staff_self_service')->value('scope'));
        $this->assertSame(['attendance.teacher', 'curriculum.delivery.teacher', 'lms.assignments.teacher', 'lms.content.teacher'], $this->roleCapabilities('teacher'), 'E33: byte-for-byte the pre-HRX.4 bundle');
        $this->assertSame([], array_values(array_intersect($this->roleCapabilities('principal'), ['hr.leave.self', 'hr.staff_attendance.self', 'payroll.payslips.self'])), 'principal unchanged');
        $this->assertSame([], array_values(array_intersect($this->roleCapabilities('teacher'), ['hr.leave.self', 'hr.staff_attendance.self', 'payroll.payslips.self', 'hr.leave.approve'])));
        $this->assertSame(['hr.leave.self', 'hr.staff_attendance.self', 'payroll.payslips.self'], array_values(array_intersect($this->roleCapabilities('school_admin'), ['hr.leave.self', 'hr.staff_attendance.self', 'payroll.payslips.self'])), 'school_admin holds them so it can grant the role');
    }

    #[Test]
    public function a_school_admin_may_grant_the_role_and_a_role_grant_works_exactly_like_direct_capabilities(): void
    {
        [$schoolAdmin, $school] = $this->createSchoolAdmin();
        $catalog = collect(app(StaffRoleCatalog::class)->catalogFor($schoolAdmin, $school))->keyBy('key');
        $this->assertTrue($catalog['staff_self_service']['grantable']);

        $w = $this->attendanceWorld();
        $viaRole = $this->staffMember($w['school']);
        $this->assignSchoolRole(SchoolMembership::query()->where('school_id', $w['school']->id)->where('user_id', $viaRole['user']->id)->firstOrFail(), 'staff_self_service');
        $direct = $this->staffMember($w['school'], self::SELF_CAPABILITIES);
        $base = "/api/v1/schools/{$w['school']->id}/my";

        foreach ([$viaRole['user'], $direct['user']] as $user) {
            $this->as($user)->getJson("{$base}/leave/requests")->assertOk();
            $this->as($user)->getJson("{$base}/staff-attendance")->assertOk();
            $this->as($user)->getJson("{$base}/payslips")->assertOk();
            // The bundle is not manager approval and not administration.
            $this->as($user)->getJson("/api/v1/schools/{$w['school']->id}/leave/approvals")->assertForbidden();
            $this->as($user)->getJson("/api/v1/schools/{$w['school']->id}/leave/requests")->assertForbidden();
        }
        // A role holder without an ActingEmployee reaches no one's data.
        $orphan = $this->createUser();
        $this->assignSchoolRole($this->createMembership($orphan, $w['school']), 'staff_self_service');
        $this->as($orphan)->getJson("{$base}/leave/requests")->assertNotFound();
    }

    #[Test]
    public function the_acting_employee_is_resolved_again_in_each_school_and_never_crosses(): void
    {
        $a = $this->attendanceWorld();
        $b = $this->attendanceWorld();
        $me = $this->selfMember($a);
        $this->submitLeave($a, '2026-10-12', '2026-10-12', employment: $me['employment']);
        // The same User is a member of School B with the capabilities but no Employee there.
        $membership = $this->createMembership($me['user'], $b['school']);
        $role = Role::query()->where('key', 'staff_self_service')->firstOrFail();
        $this->assignSchoolRole($membership, $role->key);

        $this->as($me['user'])->getJson("/api/v1/schools/{$a['school']->id}/my/leave/requests")->assertOk()->assertJsonCount(1, 'data');
        $this->as($me['user'])->getJson("/api/v1/schools/{$b['school']->id}/my/leave/requests")->assertNotFound();
        $this->as($me['user'])->getJson("/api/v1/schools/{$b['school']->id}/my/payslips")->assertNotFound();

        // With an Employee of their own in School B, School B shows only School B's data.
        $employeeB = $this->createEmployee($b['school'], ['user_id' => $me['user']->id]);
        $this->createEmploymentRecord($employeeB, ['status' => 'active', 'starts_on' => '2024-01-01', 'ends_on' => null]);
        $this->as($me['user'])->getJson("/api/v1/schools/{$b['school']->id}/my/leave/requests")->assertOk()->assertJsonCount(0, 'data');
    }

    #[Test]
    public function separation_or_suspension_ends_self_service_access(): void
    {
        $w = $this->attendanceWorld();
        $me = $this->selfMember($w);
        $base = "/api/v1/schools/{$w['school']->id}/my";
        $this->as($me['user'])->getJson("{$base}/leave")->assertOk();

        $this->inSchool($w['school'], fn () => DB::table('employment_records')->where('id', $me['employment']->id)->update(['status' => 'separated', 'ends_on' => '2026-10-01']));
        $this->as($me['user'])->getJson("{$base}/leave")->assertNotFound();
        $this->as($me['user'])->getJson("{$base}/payslips")->assertNotFound();
        $this->as($me['user'])->getJson("{$base}/staff-attendance")->assertNotFound();

        // E21-RH.6: an ended employment is never reopened, so the notice-period case is another member's current record.
        $serving = $this->selfMember($w);
        $this->inSchool($w['school'], fn () => DB::table('employment_records')->where('id', $serving['employment']->id)->update(['status' => 'notice_period']));
        $this->assertContains('notice_period', EmploymentCoverage::CURRENT_STATUSES);
        $this->as($serving['user'])->getJson("{$base}/leave")->assertOk();
        SchoolMembership::query()->where('school_id', $w['school']->id)->where('user_id', $serving['user']->id)->update(['status' => 'suspended']);
        $this->as($serving['user'])->getJson("{$base}/leave")->assertNotFound();
    }
}

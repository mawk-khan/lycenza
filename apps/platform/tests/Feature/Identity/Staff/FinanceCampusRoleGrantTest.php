<?php

namespace Tests\Feature\Identity\Staff;

use App\Domain\Identity\Application\Staff\StaffAccessService;
use App\Domain\Identity\Application\Staff\StaffAccountException;
use App\Models\MembershipRoleAssignment;
use App\Models\SchoolMembership;
use App\Support\Authorization\Campus\CampusScopeResolver;
use App\Support\Tenancy\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Concerns\FakesEmail;
use Tests\Feature\Auth\Mfa\Concerns\CreatesMfaFixtures;
use Tests\TestCase;

/**
 * ORG.7G-1 (ADR 0076 §50, G1-D9/G1-D10): cashier may be granted for one
 * Campus and then reads Finance there only (never records a payment from that
 * grant); accountant -- whose template holds whole-School Finance authority --
 * is refused a Campus grant on the Staff accounts page AND at the service, and
 * keeps its whole-School grant unchanged.
 */
class FinanceCampusRoleGrantTest extends TestCase
{
    use CreatesMfaFixtures, CreatesTenancyFixtures, FakesEmail, StaffAccountTestHelpers;

    /** @return list<array{key: string, campusId: ?string}> */
    private function grants(SchoolMembership $membership): array
    {
        return app(TenantContext::class)->withSchool($membership->school, fn () => MembershipRoleAssignment::query()
            ->where('school_membership_id', $membership->id)->active()->with('role')->orderBy('created_at')->get()
            ->map(fn (MembershipRoleAssignment $g) => ['key' => $g->role->key, 'campusId' => $g->campus_id])->all());
    }

    #[Test]
    public function cashier_is_granted_for_one_campus_and_reads_finance_there_only(): void
    {
        [$admin, $school] = $this->staffAdmin();
        $campus = $this->createCampus($school);
        [$staff, $membership] = $this->staffMember($school, 'teacher');

        $this->staffPost($admin, $school, "/members/{$membership->id}/roles", ['role' => 'cashier', 'campus_id' => $campus->id])->assertOk();
        $this->assertContains(['key' => 'cashier', 'campusId' => $campus->id], $this->grants($membership));

        $resolver = app(CampusScopeResolver::class);
        $this->assertSame([$campus->id], $resolver->campusScope($staff, 'finance.charges.view', $school)->campusIds());
        $this->assertSame([$campus->id], $resolver->campusScope($staff, 'finance.payments.view', $school)->campusIds());
        $this->assertSame([$campus->id], $resolver->campusScope($staff, 'finance.payments.record', $school)->campusIds(), 'ORG.7G-2: ordinary payment entry for that Campus');
        foreach (['finance.charges.manage', 'finance.ledger.reverse', 'finance.fee_concessions.approve', 'finance.fee_structures.manage'] as $never) {
            $this->assertTrue($resolver->campusScope($staff, $never, $school)->isNone(), "{$never} is never conferred by a Campus grant");
        }
    }

    #[Test]
    public function accountant_is_never_granted_for_a_campus_on_the_page_or_at_the_service(): void
    {
        [$admin, $school] = $this->staffAdmin();
        $campus = $this->createCampus($school);
        [, $membership] = $this->staffMember($school, 'teacher');

        $this->staffPost($admin, $school, "/members/{$membership->id}/roles", ['role' => 'accountant', 'campus_id' => $campus->id])->assertUnprocessable();
        $this->assertNotContains('accountant', array_column($this->grants($membership), 'key'));

        try {
            app(StaffAccessService::class)->grantRole($school, $admin, $membership->id, 'accountant', $campus->id);
            $this->fail('accountant was granted for a Campus');
        } catch (StaffAccountException $e) {
            $this->assertSame('role_not_campus_scopable', $e->outcome);
        }

        $this->staffPost($admin, $school, "/members/{$membership->id}/roles", ['role' => 'accountant'])->assertOk();
        $this->assertContains(['key' => 'accountant', 'campusId' => null], $this->grants($membership), 'its whole-School grant is unchanged');
    }
}

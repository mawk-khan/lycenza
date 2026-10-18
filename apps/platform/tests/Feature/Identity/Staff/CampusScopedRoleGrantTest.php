<?php

namespace Tests\Feature\Identity\Staff;

use App\Models\MembershipRoleAssignment;
use App\Models\School;
use App\Models\SchoolAuditEvent;
use App\Models\SchoolMembership;
use App\Support\Authorization\Campus\CampusScopeResolver;
use App\Support\Tenancy\TenantContext;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Concerns\FakesEmail;
use Tests\Feature\Auth\Mfa\Concerns\CreatesMfaFixtures;
use Tests\TestCase;

/**
 * ORG.7A (ADR 0076 §11.4-§11.6, §33): Settings -> Staff accounts grants a role
 * for the entire School or for one active Campus. Who may grant is unchanged
 * (whole-School authority, fresh MFA); a Campus-scoped holder administers
 * nothing; access-administration roles are never Campus-scoped.
 */
class CampusScopedRoleGrantTest extends TestCase
{
    use CreatesMfaFixtures, CreatesTenancyFixtures, FakesEmail, StaffAccountTestHelpers;

    /** @return array<int, array{campusId: ?string, key: string}> */
    private function grants(SchoolMembership $membership): array
    {
        return app(TenantContext::class)->withSchool($membership->school, fn () => MembershipRoleAssignment::query()
            ->where('school_membership_id', $membership->id)->active()->with('role')->orderBy('created_at')->get()
            ->map(fn (MembershipRoleAssignment $g) => ['key' => $g->role->key, 'campusId' => $g->campus_id])->all());
    }

    private function audits(School $school, string $type): array
    {
        return app(TenantContext::class)->withSchool($school, fn () => SchoolAuditEvent::query()->where('event_type', $type)->orderBy('occurred_at')->orderBy('id')->pluck('metadata')->all());
    }

    #[Test]
    public function an_administrator_grants_and_revokes_a_role_for_one_campus_with_a_fresh_code(): void
    {
        [$admin, $school] = $this->staffAdmin();
        $campus = $this->createCampus($school, ['name' => 'Jubilee Campus']);
        [$staff, $membership] = $this->staffMember($school, 'teacher');

        $this->staffPost($admin, $school, "/members/{$membership->id}/roles", ['role' => 'front_office', 'campus_id' => $campus->id])->assertOk();
        $this->staffPost($admin, $school, "/members/{$membership->id}/roles", ['role' => 'front_office'])->assertOk();
        $this->assertEqualsCanonicalizing([
            ['key' => 'teacher', 'campusId' => null], ['key' => 'front_office', 'campusId' => $campus->id], ['key' => 'front_office', 'campusId' => null],
        ], $this->grants($membership));

        // The same scope twice is refused; the entire-School grant is revoked on its own.
        $this->staffPost($admin, $school, "/members/{$membership->id}/roles", ['role' => 'front_office', 'campus_id' => $campus->id])->assertUnprocessable();
        $this->staffPost($admin, $school, "/members/{$membership->id}/roles/front_office/revoke")->assertOk();
        $this->assertContains(['key' => 'front_office', 'campusId' => $campus->id], $this->grants($membership));
        $this->assertSame([$campus->id], app(CampusScopeResolver::class)->scopeFor($staff, 'visitor.visits.view', $school)->campusIds());

        $this->staffPost($admin, $school, "/members/{$membership->id}/roles/front_office/revoke", ['campus_id' => $campus->id])->assertOk();
        $this->assertSame([['key' => 'teacher', 'campusId' => null]], $this->grants($membership));
        $this->assertTrue(app(CampusScopeResolver::class)->scopeFor($staff, 'visitor.visits.view', $school)->isNone());

        // Audit carries the Campus id (identifier only) on the scoped events.
        $assigned = $this->audits($school, 'school.membership.role_assigned');
        $this->assertSame($campus->id, $assigned[0]['campusId']);
        $this->assertArrayNotHasKey('campusId', $assigned[1]);
        $revoked = $this->audits($school, 'school.membership.role_revoked');
        $this->assertSame($campus->id, end($revoked)['campusId']);

        // The page shows each grant with its scope, and offers active Campuses.
        $this->staffPost($admin, $school, "/members/{$membership->id}/roles", ['role' => 'stores_officer', 'campus_id' => $campus->id])->assertOk();
        $this->enterSchool($admin, $school);
        $this->get('/app/settings/staff')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p
            ->where('campuses', [['id' => $campus->id, 'name' => 'Jubilee Campus']])
            ->where('staff', fn ($staffList) => collect($staffList)->firstWhere('membershipId', $membership->id)['roles'] === [
                ['key' => 'stores_officer', 'name' => 'Stores Officer', 'campusId' => $campus->id, 'campusName' => 'Jubilee Campus', 'campusActive' => true],
                ['key' => 'teacher', 'name' => 'Teacher', 'campusId' => null, 'campusName' => null, 'campusActive' => null],
            ])
            ->where('roleCatalog', fn ($catalog) => collect($catalog)->firstWhere('key', 'school_admin')['campusScopable'] === false
                && collect($catalog)->firstWhere('key', 'front_office')['campusScopable'] === true
                && collect($catalog)->firstWhere('key', 'front_office')['campusAreas'] === ['Visitor check-in and visits']));
    }

    #[Test]
    public function invalid_campus_targets_and_access_administration_roles_are_refused(): void
    {
        [$admin, $school] = $this->staffAdmin();
        $other = $this->createSchool();
        $foreign = $this->createCampus($other);
        $inactive = $this->createCampus($school, ['status' => 'inactive']);
        $campus = $this->createCampus($school);
        [, $membership] = $this->staffMember($school, 'teacher');

        $this->staffPost($admin, $school, "/members/{$membership->id}/roles", ['role' => 'front_office', 'campus_id' => $foreign->id])->assertUnprocessable()->assertJsonPath('error.message', 'Choose an active Campus of this School.');
        $this->staffPost($admin, $school, "/members/{$membership->id}/roles", ['role' => 'front_office', 'campus_id' => $inactive->id])->assertUnprocessable();
        $this->staffPost($admin, $school, "/members/{$membership->id}/roles", ['role' => 'school_admin', 'campus_id' => $campus->id])->assertUnprocessable()->assertJsonPath('error.message', 'That role can only be granted for the entire School.');
        // A role with no Campus-adopted capability would confer nothing for a Campus.
        $this->staffPost($admin, $school, "/members/{$membership->id}/roles", ['role' => 'librarian', 'campus_id' => $campus->id])->assertUnprocessable();
        $this->staffPost($admin, $school, "/members/{$membership->id}/roles", ['role' => 'front_office', 'campus_id' => 'not-a-uuid'])->assertUnprocessable();
        $this->assertSame([['key' => 'teacher', 'campusId' => null]], $this->grants($membership));
    }

    #[Test]
    public function a_campus_scoped_holder_administers_no_staff_access(): void
    {
        [$admin, $school] = $this->staffAdmin();
        $campus = $this->createCampus($school);
        [$scoped, $scopedMembership] = $this->staffMember($school, 'teacher');
        $this->assignSchoolRole($scopedMembership, 'principal', $campus->id);
        $this->withMfaCodes($scoped);
        [, $target] = $this->staffMember($school, 'teacher');

        // principal@Campus holds school.members.view only through the Campus grant -- never School-wide.
        $this->staffPost($scoped, $school, "/members/{$target->id}/roles", ['role' => 'front_office'])->assertForbidden();
        $this->staffPost($scoped, $school, "/members/{$target->id}/suspend")->assertForbidden();
        $this->staffPost($scoped, $school, '/invitations', ['email' => 'x@example.test', 'roles' => ['teacher']])->assertForbidden();
        $this->enterSchool($scoped, $school);
        $this->get('/app/settings/staff')->assertForbidden();
        $this->assertNotNull($admin);
    }
}

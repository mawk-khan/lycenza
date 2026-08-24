<?php

namespace Tests\Feature\StudentGuardianIdentity;

use App\Domain\Identity\Application\AccountLinkService;
use App\Domain\Identity\Application\Exceptions\CrossSchoolMembershipLinkException;
use App\Domain\Identity\Application\Exceptions\MembershipAlreadyLinkedException;
use App\Domain\Identity\Application\Exceptions\NoActiveAccountLinkException;
use App\Domain\Identity\Application\Exceptions\PersonaAlreadyLinkedException;
use App\Models\SchoolAuditEvent;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCommunicationFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 5B.2 -- App\Domain\Identity\Application\AccountLinkService: the
 * sole write path for linking/unlinking a Student/Guardian domain
 * identity to an existing SchoolMembership. Every scenario goes
 * through the real service, matching this codebase's established
 * convention.
 */
class AccountLinkServiceTest extends TestCase
{
    use CreatesCommunicationFixtures, CreatesTenancyFixtures;

    private function service(): AccountLinkService
    {
        return app(AccountLinkService::class);
    }

    // --- §38: Guardian link ---------------------------------------------

    #[Test]
    public function a_guardian_can_be_linked_to_a_same_school_membership(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $member = $this->createUser();
        $membership = $this->createMembership($member, $school);
        $guardian = $this->createGuardian($school);

        $link = $this->service()->linkGuardian($school, $guardian, $membership, $admin);

        $this->assertSame('active', $link->status);
        $this->assertSame($guardian->id, $link->guardian_id);
        $this->assertNull($link->student_id);
        $this->assertSame($membership->id, $link->school_membership_id);
    }

    #[Test]
    public function linking_an_already_linked_guardian_is_denied(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $guardian = $this->createGuardian($school);
        $membershipA = $this->createMembership($this->createUser(), $school);
        $membershipB = $this->createMembership($this->createUser(), $school);
        $this->service()->linkGuardian($school, $guardian, $membershipA, $admin);

        $this->expectException(PersonaAlreadyLinkedException::class);
        $this->service()->linkGuardian($school, $guardian, $membershipB, $admin);
    }

    #[Test]
    public function linking_to_a_membership_that_already_has_a_different_active_persona_is_denied(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $membership = $this->createMembership($this->createUser(), $school);
        $guardianA = $this->createGuardian($school);
        $guardianB = $this->createGuardian($school);
        $this->service()->linkGuardian($school, $guardianA, $membership, $admin);

        $this->expectException(MembershipAlreadyLinkedException::class);
        $this->service()->linkGuardian($school, $guardianB, $membership, $admin);
    }

    #[Test]
    public function linking_to_a_cross_school_membership_is_denied(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $otherSchool = $this->createSchool();
        $foreignMembership = $this->createMembership($this->createUser(), $otherSchool);
        $guardian = $this->createGuardian($school);

        $this->expectException(CrossSchoolMembershipLinkException::class);
        $this->service()->linkGuardian($school, $guardian, $foreignMembership, $admin);
    }

    #[Test]
    public function unlinking_a_guardian_succeeds_and_preserves_every_underlying_record(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $member = $this->createUser();
        $membership = $this->createMembership($member, $school);
        $guardian = $this->createGuardian($school);
        $this->service()->linkGuardian($school, $guardian, $membership, $admin);

        $this->service()->unlinkGuardian($school, $guardian, $admin);

        $this->assertNull($this->service()->activeLinkForGuardian($guardian));
        app(TenantContext::class)->withSchool($school, function () use ($guardian, $member) {
            $this->assertNotNull($guardian->fresh());
            $this->assertNotNull($member->fresh());
        });
    }

    #[Test]
    public function unlinking_when_nothing_is_linked_is_denied(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $guardian = $this->createGuardian($school);

        $this->expectException(NoActiveAccountLinkException::class);
        $this->service()->unlinkGuardian($school, $guardian, $admin);
    }

    #[Test]
    public function relinking_after_unlink_succeeds(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $guardian = $this->createGuardian($school);
        $membershipA = $this->createMembership($this->createUser(), $school);
        $membershipB = $this->createMembership($this->createUser(), $school);

        $this->service()->linkGuardian($school, $guardian, $membershipA, $admin);
        $this->service()->unlinkGuardian($school, $guardian, $admin);
        $newLink = $this->service()->linkGuardian($school, $guardian, $membershipB, $admin);

        $this->assertSame($membershipB->id, $newLink->school_membership_id);
        $this->assertSame('active', $this->service()->activeLinkForGuardian($guardian)->status);
    }

    #[Test]
    public function account_link_creation_and_removal_are_audited_without_sensitive_data(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $membership = $this->createMembership($this->createUser(), $school);
        $guardian = $this->createGuardian($school);

        $this->service()->linkGuardian($school, $guardian, $membership, $admin);
        $this->service()->unlinkGuardian($school, $guardian, $admin);

        $events = app(TenantContext::class)->withSchool($school, fn () => SchoolAuditEvent::query()
            ->whereIn('event_type', ['guardian.account_linked', 'guardian.account_unlinked'])
            ->get());

        $this->assertCount(2, $events);
        foreach ($events as $event) {
            $payload = json_encode($event->metadata);
            $this->assertStringNotContainsString('password', $payload);
            $this->assertStringNotContainsString('token', $payload);
        }
    }

    // --- §37: Student link ------------------------------------------------

    #[Test]
    public function a_student_can_be_linked_to_a_same_school_membership_and_unlinked(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $membership = $this->createMembership($this->createUser(), $school);
        $student = $this->createStudent($school);

        $link = $this->service()->linkStudent($school, $student, $membership, $admin);
        $this->assertSame($student->id, $link->student_id);
        $this->assertNull($link->guardian_id);

        $this->service()->unlinkStudent($school, $student, $admin);
        $this->assertNull($this->service()->activeLinkForStudent($student));
    }

    // --- §10/§11/§36: one membership, one persona, staff dual-role ------

    #[Test]
    public function a_membership_cannot_be_linked_as_both_a_student_and_a_guardian_simultaneously(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $membership = $this->createMembership($this->createUser(), $school);
        $student = $this->createStudent($school);
        $guardian = $this->createGuardian($school);
        $this->service()->linkStudent($school, $student, $membership, $admin);

        $this->expectException(MembershipAlreadyLinkedException::class);
        $this->service()->linkGuardian($school, $guardian, $membership, $admin);
    }

    #[Test]
    public function a_staff_membership_can_be_linked_as_a_guardian_without_losing_its_staff_role(): void
    {
        // §11/§36: a teacher whose own child attends the School --
        // linking their staff membership as a Guardian must not affect
        // their existing role/capabilities.
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $teacher = $this->createUser();
        $teacherMembership = $this->createMembership($teacher, $school);
        $this->assignSchoolRole($teacherMembership, 'principal');
        $guardian = $this->createGuardian($school);

        $this->service()->linkGuardian($school, $guardian, $teacherMembership, $admin);

        $link = $this->service()->activeLinkForGuardian($guardian);
        $this->assertSame($teacherMembership->id, $link->school_membership_id);

        $capable = app(CapabilityResolver::class)
            ->canInSchool($teacher, 'school.settings.view', $school);
        $this->assertTrue($capable, 'Linking as Guardian must not remove the staff membership\'s own role capabilities.');
    }

    // --- §35: multi-school User -------------------------------------------

    #[Test]
    public function a_multi_school_users_membership_in_school_b_does_not_satisfy_a_school_a_link(): void
    {
        [$adminA, $schoolA] = $this->createSchoolAdmin('school_admin');
        $schoolB = $this->createSchool();
        $user = $this->createUser();
        $this->createMembership($user, $schoolA);
        $membershipB = $this->createMembership($user, $schoolB);
        $guardian = $this->createGuardian($schoolA);

        $this->expectException(CrossSchoolMembershipLinkException::class);
        $this->service()->linkGuardian($schoolA, $guardian, $membershipB, $adminA);
    }

    // --- §49: performance ---------------------------------------------------

    #[Test]
    public function batch_link_lookup_for_many_guardians_uses_a_bounded_query_count(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $guardianIds = [];
        for ($i = 0; $i < 25; $i++) {
            $guardian = $this->createGuardian($school);
            $membership = $this->createMembership($this->createUser(), $school);
            $this->service()->linkGuardian($school, $guardian, $membership, $admin);
            $guardianIds[] = $guardian->id;
        }

        DB::enableQueryLog();
        $links = $this->service()->activeLinksForGuardians($school, $guardianIds);
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertCount(25, $links);
        $this->assertLessThan(5, $queryCount);
    }
}

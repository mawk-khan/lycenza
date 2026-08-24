<?php

namespace Tests\Feature\App;

use App\Models\School;
use App\Models\User;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCommunicationFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 5B.2 §29/§30/§34 -- HTTP-layer coverage for the Student/
 * Guardian account-link admin actions: search, link, unlink,
 * authorization, and cross-School forgery rejection.
 */
class AccountLinkHubTest extends TestCase
{
    use CreatesCommunicationFixtures, CreatesTenancyFixtures;

    private function activate(User $user, School $school): void
    {
        $this->actingAs($user)->post("/app/schools/{$school->id}/activate");
    }

    #[Test]
    public function a_school_admin_can_link_and_unlink_a_guardian_account(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $member = $this->createUser(['name' => 'Priya Sharma']);
        $membership = $this->createMembership($member, $school);
        $guardian = $this->createGuardian($school);
        $this->activate($admin, $school);

        $search = $this->actingAs($admin)->get("/app/guardians/{$guardian->id}/account-link/search?q=priya");
        $search->assertOk();
        $search->assertJsonFragment(['schoolMembershipId' => $membership->id]);

        $link = $this->post("/app/guardians/{$guardian->id}/account-link", [
            'school_membership_id' => $membership->id,
        ]);
        $link->assertRedirect("/app/guardians/{$guardian->id}");

        $this->get("/app/guardians/{$guardian->id}")->assertInertia(fn ($page) => $page
            ->where('accountLink.schoolMembershipId', $membership->id)
        );

        $unlink = $this->delete("/app/guardians/{$guardian->id}/account-link");
        $unlink->assertRedirect("/app/guardians/{$guardian->id}");

        $this->get("/app/guardians/{$guardian->id}")->assertInertia(fn ($page) => $page
            ->where('accountLink', null)
        );
    }

    #[Test]
    public function a_school_admin_can_link_and_unlink_a_student_account(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $member = $this->createUser();
        $membership = $this->createMembership($member, $school);
        $student = $this->createStudent($school);
        $this->activate($admin, $school);

        $this->post("/app/students/{$student->id}/account-link", [
            'school_membership_id' => $membership->id,
        ])->assertRedirect("/app/students/{$student->id}");

        $this->get("/app/students/{$student->id}")->assertInertia(fn ($page) => $page
            ->where('accountLink.schoolMembershipId', $membership->id)
        );

        $this->delete("/app/students/{$student->id}/account-link")->assertRedirect("/app/students/{$student->id}");

        $this->get("/app/students/{$student->id}")->assertInertia(fn ($page) => $page
            ->where('accountLink', null)
        );
    }

    #[Test]
    public function linking_a_school_bs_membership_to_a_school_as_guardian_is_rejected(): void
    {
        [$adminA, $schoolA] = $this->createSchoolAdmin('school_admin');
        $foreignMembership = $this->createMembership($this->createUser(), $this->createSchool());
        $guardianA = $this->createGuardian($schoolA);
        $this->activate($adminA, $schoolA);

        $this->post("/app/guardians/{$guardianA->id}/account-link", [
            'school_membership_id' => $foreignMembership->id,
        ])->assertSessionHasErrors('school_membership_id');
    }

    #[Test]
    public function the_search_endpoint_never_returns_a_cross_school_membership(): void
    {
        [$adminA, $schoolA] = $this->createSchoolAdmin('school_admin');
        $foreignMember = $this->createUser(['name' => 'Uniquename Foreign']);
        $this->createMembership($foreignMember, $this->createSchool());
        $guardianA = $this->createGuardian($schoolA);
        $this->activate($adminA, $schoolA);

        $response = $this->actingAs($adminA)->get("/app/guardians/{$guardianA->id}/account-link/search?q=uniquename");

        $response->assertOk();
        $response->assertJson(['candidates' => []]);
    }

    #[Test]
    public function the_search_endpoint_excludes_a_membership_that_is_already_linked(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $member = $this->createUser(['name' => 'Already Linked']);
        $membership = $this->createMembership($member, $school);
        $guardianA = $this->createGuardian($school);
        $guardianB = $this->createGuardian($school);
        $this->activate($admin, $school);

        $this->post("/app/guardians/{$guardianA->id}/account-link", ['school_membership_id' => $membership->id]);

        $response = $this->actingAs($admin)->get("/app/guardians/{$guardianB->id}/account-link/search?q=already");
        $response->assertOk();
        $response->assertJson(['candidates' => []]);
    }

    #[Test]
    public function a_member_without_guardians_manage_is_denied_the_link_action(): void
    {
        $user = $this->createUser();
        $school = $this->createSchool();
        $this->createMembership($user, $school);
        $guardian = $this->createGuardian($school);
        $membership = $this->createMembership($this->createUser(), $school);
        $this->activate($user, $school);

        $this->actingAs($user)
            ->post("/app/guardians/{$guardian->id}/account-link", ['school_membership_id' => $membership->id])
            ->assertForbidden();
    }

    #[Test]
    public function a_guest_is_redirected_to_login_not_shown_a_403_for_the_search_endpoint(): void
    {
        $guardian = $this->createGuardian($this->createSchool());

        $this->get("/app/guardians/{$guardian->id}/account-link/search?q=a")->assertRedirect('/login');
    }

    #[Test]
    public function linking_an_already_linked_guardian_returns_a_clean_validation_error(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $guardian = $this->createGuardian($school);
        $membershipA = $this->createMembership($this->createUser(), $school);
        $membershipB = $this->createMembership($this->createUser(), $school);
        $this->activate($admin, $school);

        $this->post("/app/guardians/{$guardian->id}/account-link", ['school_membership_id' => $membershipA->id]);

        $this->post("/app/guardians/{$guardian->id}/account-link", ['school_membership_id' => $membershipB->id])
            ->assertSessionHasErrors('school_membership_id');
    }
}

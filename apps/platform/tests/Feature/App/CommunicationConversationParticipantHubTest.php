<?php

namespace Tests\Feature\App;

use App\Domain\Identity\Application\AccountLinkService;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCommunicationFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 5D.1 §44-§48 -- HTTP-layer coverage for Student/Guardian
 * conversation participation: thread creation (success/denial paths),
 * the Guardian/Student composer search endpoints, and the privacy
 * invariant that a linked account only ever sees threads it actually
 * participates in. Mirrors tests/Feature/App/CommunicationHubTest.php's
 * session-auth conventions.
 */
class CommunicationConversationParticipantHubTest extends TestCase
{
    use CreatesCommunicationFixtures, CreatesTenancyFixtures;

    private function activate($user, $school): void
    {
        $this->actingAs($user)->post("/app/schools/{$school->id}/activate");
    }

    private function links(): AccountLinkService
    {
        return app(AccountLinkService::class);
    }

    #[Test]
    public function a_school_admin_can_start_a_private_conversation_with_a_linked_guardian(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $member = $this->createUser();
        $membership = $this->createMembership($member, $school);
        $guardian = $this->createGuardian($school);
        $this->links()->linkGuardian($school, $guardian, $membership, $admin);
        $this->activate($admin, $school);

        $response = $this->post('/app/communications/conversations', [
            'thread_type' => 'direct',
            'subject' => 'Regarding pickup time',
            'guardian_ids' => [$guardian->id],
        ]);

        $response->assertRedirect();
    }

    #[Test]
    public function an_unlinked_guardian_is_rejected_with_a_validation_error(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $guardian = $this->createGuardian($school);
        $this->activate($admin, $school);

        $this->post('/app/communications/conversations', [
            'thread_type' => 'direct',
            'guardian_ids' => [$guardian->id],
        ])->assertInvalid(['participants']);
    }

    #[Test]
    public function a_guardian_linked_to_an_inactive_membership_is_rejected(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $member = $this->createUser();
        $membership = $this->createMembership($member, $school, 'suspended');
        $guardian = $this->createGuardian($school);
        $this->links()->linkGuardian($school, $guardian, $membership, $admin);
        $this->activate($admin, $school);

        $this->post('/app/communications/conversations', [
            'thread_type' => 'direct',
            'guardian_ids' => [$guardian->id],
        ])->assertInvalid(['participants']);
    }

    #[Test]
    public function an_actor_lacking_the_guardian_conversation_capability_is_forbidden(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $member = $this->createUser();
        $membership = $this->createMembership($member, $school);
        $guardian = $this->createGuardian($school);
        $this->links()->linkGuardian($school, $guardian, $membership, $admin);

        $actor = $this->createUserWithCapabilities($school, ['communications.send']);
        $this->activate($actor, $school);

        $this->post('/app/communications/conversations', [
            'thread_type' => 'direct',
            'guardian_ids' => [$guardian->id],
        ])->assertForbidden();
    }

    #[Test]
    public function a_school_that_has_disabled_guardian_conversations_denies_the_request(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $this->createConversationPolicy($school, ['allow_guardian_conversations' => false]);
        $member = $this->createUser();
        $membership = $this->createMembership($member, $school);
        $guardian = $this->createGuardian($school);
        $this->links()->linkGuardian($school, $guardian, $membership, $admin);
        $this->activate($admin, $school);

        $this->post('/app/communications/conversations', [
            'thread_type' => 'direct',
            'guardian_ids' => [$guardian->id],
        ])->assertInvalid(['participants']);
    }

    #[Test]
    public function a_cross_school_guardian_id_is_denied(): void
    {
        [$admin, $schoolA] = $this->createSchoolAdmin('school_admin');
        $schoolB = $this->createSchool();
        $guardianB = $this->createGuardian($schoolB);
        $this->activate($admin, $schoolA);

        $this->post('/app/communications/conversations', [
            'thread_type' => 'direct',
            'guardian_ids' => [$guardianB->id],
        ])->assertInvalid(['participants']);
    }

    #[Test]
    public function student_conversations_are_policy_disabled_by_default_even_for_an_authorized_school_admin(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $member = $this->createUser();
        $membership = $this->createMembership($member, $school);
        $student = $this->createStudent($school);
        $this->links()->linkStudent($school, $student, $membership, $admin);

        // school_admin is NOT seeded with communications.conversations.students
        // by default (brief §15) -- grant it explicitly to isolate the
        // school-policy gate from the capability gate.
        $actor = $this->createUserWithCapabilities($school, ['communications.send', 'communications.conversations.students']);
        $this->activate($actor, $school);

        $this->post('/app/communications/conversations', [
            'thread_type' => 'direct',
            'student_ids' => [$student->id],
        ])->assertInvalid(['participants']);
    }

    #[Test]
    public function a_linked_student_conversation_succeeds_once_capability_and_school_policy_both_allow_it(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $this->createConversationPolicy($school, ['allow_student_conversations' => true]);
        $member = $this->createUser();
        $membership = $this->createMembership($member, $school);
        $student = $this->createStudent($school);
        $this->links()->linkStudent($school, $student, $membership, $admin);

        $actor = $this->createUserWithCapabilities($school, ['communications.send', 'communications.conversations.students']);
        $this->activate($actor, $school);

        $this->post('/app/communications/conversations', [
            'thread_type' => 'direct',
            'student_ids' => [$student->id],
        ])->assertRedirect();
    }

    #[Test]
    public function no_participants_at_all_is_a_validation_error(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $this->activate($admin, $school);

        $this->post('/app/communications/conversations', [
            'thread_type' => 'direct',
        ])->assertInvalid(['participants']);
    }

    // --- Search endpoints ----------------------------------------------------

    #[Test]
    public function the_guardian_search_endpoint_is_gated_by_the_guardian_conversation_capability(): void
    {
        [, $school] = $this->createSchoolAdmin('school_admin');
        $actor = $this->createUserWithCapabilities($school, ['communications.send']);
        $this->activate($actor, $school);

        $this->get('/app/communications/participants/search/guardians')->assertForbidden();
    }

    #[Test]
    public function the_guardian_search_endpoint_returns_matching_active_guardians(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $this->createGuardian($school, ['first_name' => 'Aisha', 'last_name' => 'Khan']);
        $this->activate($admin, $school);

        $this->getJson('/app/communications/participants/search/guardians?q=Aisha')
            ->assertOk()
            ->assertJsonFragment(['label' => 'Aisha Khan']);
    }

    #[Test]
    public function the_student_search_endpoint_is_gated_by_the_student_conversation_capability(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $this->activate($admin, $school);

        // school_admin does NOT hold communications.conversations.students by default.
        $this->get('/app/communications/participants/search/students')->assertForbidden();
    }

    // --- Privacy ---------------------------------------------------------------

    #[Test]
    public function a_linked_guardians_own_login_only_sees_threads_it_actually_participates_in(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $member = $this->createUser();
        $membership = $this->createMembership($member, $school);
        $this->assignSchoolRole($membership, 'principal');
        $guardian = $this->createGuardian($school);
        $this->links()->linkGuardian($school, $guardian, $membership, $admin);

        // A thread the linked Guardian is genuinely a participant of.
        $this->activate($admin, $school);
        $this->post('/app/communications/conversations', [
            'thread_type' => 'direct',
            'guardian_ids' => [$guardian->id],
        ])->assertRedirect();

        // A second, unrelated thread the linked Guardian is NOT a participant of.
        $otherMember = $this->createUser();
        $this->createMembership($otherMember, $school);
        $unrelatedThread = $this->createThread($school, $admin);
        $this->createParticipant($unrelatedThread, $admin);
        $this->createParticipant($unrelatedThread, $otherMember);

        $this->activate($member, $school);
        $this->get('/app/communications/conversations')->assertInertia(fn ($page) => $page
            ->component('App/Communications/Conversations')
            ->has('threads.data', 1));
    }
}

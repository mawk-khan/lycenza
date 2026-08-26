<?php

namespace Tests\Feature\App;

use App\Domain\Identity\Application\AccountLinkService;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCommunicationFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 5D.1b §6/§7/§8/§9/§10/§12/§24/§25/§28/§32-§35 -- UI-facing
 * surface coverage for the Guardian/Student conversation composer:
 * capability/policy visibility props, the enriched search payload
 * shape (safe fields only, eligibility signal), dual-role behavior,
 * and thread-detail provenance badges. Complements
 * tests/Feature/App/CommunicationConversationParticipantHubTest.php
 * (Phase 5D.1), which covers thread-creation authorization itself.
 */
class CommunicationConversationParticipantUiTest extends TestCase
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

    // --- §8: capability/policy visibility props -----------------------------

    #[Test]
    public function the_composer_exposes_guardian_and_student_capability_and_policy_flags(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $this->activate($admin, $school);

        $this->get('/app/communications/conversations')->assertInertia(fn ($page) => $page
            ->where('canSelectGuardianParticipants', true)
            ->where('canSelectStudentParticipants', false)
            ->where('guardianConversationsAllowedByPolicy', true)
            ->where('studentConversationsAllowedByPolicy', false)
        );
    }

    #[Test]
    public function an_actor_without_either_conversation_capability_sees_both_flags_false(): void
    {
        [, $school] = $this->createSchoolAdmin('school_admin');
        $actor = $this->createUserWithCapabilities($school, ['communications.view', 'communications.send']);
        $this->activate($actor, $school);

        $this->get('/app/communications/conversations')->assertInertia(fn ($page) => $page
            ->where('canSelectGuardianParticipants', false)
            ->where('canSelectStudentParticipants', false)
        );
    }

    #[Test]
    public function the_policy_flag_reflects_an_explicit_school_override(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $this->createConversationPolicy($school, ['allow_guardian_conversations' => false, 'allow_student_conversations' => true]);
        $this->activate($admin, $school);

        $this->get('/app/communications/conversations')->assertInertia(fn ($page) => $page
            ->where('guardianConversationsAllowedByPolicy', false)
            ->where('studentConversationsAllowedByPolicy', true)
        );
    }

    // --- §6/§10/§24: Guardian search payload ---------------------------------

    #[Test]
    public function guardian_search_marks_a_linked_eligible_guardian_as_account_linked_with_safe_child_context(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $member = $this->createUser();
        $membership = $this->createMembership($member, $school);
        $guardian = $this->createGuardian($school, ['first_name' => 'Aisha', 'last_name' => 'Khan']);
        $student = $this->createStudent($school, ['first_name' => 'Sara', 'last_name' => 'Khan']);
        $this->createStudentGuardianRelationship($student, $guardian, ['is_primary' => true]);
        $this->links()->linkGuardian($school, $guardian, $membership, $admin);
        $this->activate($admin, $school);

        $response = $this->getJson('/app/communications/participants/search/guardians?q=Aisha')->assertOk();

        $response->assertJsonFragment([
            'id' => $guardian->id,
            'label' => 'Aisha Khan',
            'accountLinked' => true,
            'guardianOfNames' => ['Sara Khan'],
        ]);
    }

    #[Test]
    public function guardian_search_marks_an_unlinked_guardian_as_not_account_linked(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $guardian = $this->createGuardian($school, ['first_name' => 'Unlinked', 'last_name' => 'Guardian']);
        $this->activate($admin, $school);

        $response = $this->getJson('/app/communications/participants/search/guardians?q=Unlinked')->assertOk();

        $response->assertJsonFragment(['id' => $guardian->id, 'accountLinked' => false]);
    }

    #[Test]
    public function guardian_search_marks_a_guardian_linked_to_an_inactive_membership_as_not_account_linked(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $membership = $this->createMembership($this->createUser(), $school, 'suspended');
        $guardian = $this->createGuardian($school, ['first_name' => 'Suspended', 'last_name' => 'Guardian']);
        $this->links()->linkGuardian($school, $guardian, $membership, $admin);
        $this->activate($admin, $school);

        $response = $this->getJson('/app/communications/participants/search/guardians?q=Suspended')->assertOk();

        $response->assertJsonFragment(['id' => $guardian->id, 'accountLinked' => false]);
    }

    #[Test]
    public function guardian_search_excludes_an_emergency_contact_only_child_from_guardian_of_names(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $guardian = $this->createGuardian($school, ['first_name' => 'Pickup', 'last_name' => 'Only']);
        $student = $this->createStudent($school, ['first_name' => 'Someone', 'last_name' => 'Else']);
        $this->createStudentGuardianRelationship($student, $guardian, [
            'is_primary' => false, 'is_legal_guardian' => false, 'is_authorized_pickup' => true,
        ]);
        $this->activate($admin, $school);

        $response = $this->getJson('/app/communications/participants/search/guardians?q=Pickup')->assertOk();

        $response->assertJsonFragment(['id' => $guardian->id, 'guardianOfNames' => []]);
    }

    #[Test]
    public function guardian_search_never_returns_a_guardian_contact_field(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $this->createGuardian($school, ['first_name' => 'Aisha', 'last_name' => 'Khan']);
        $this->activate($admin, $school);

        $response = $this->getJson('/app/communications/participants/search/guardians?q=Aisha')->assertOk();

        $raw = $response->getContent();
        $this->assertStringNotContainsString('email', $raw);
        $this->assertStringNotContainsString('mobile', $raw);
        $this->assertStringNotContainsString('contact', strtolower($raw));
    }

    // --- §7/§10/§25: Student search payload ----------------------------------

    #[Test]
    public function student_search_includes_grade_section_label_only_for_the_active_enrollment(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $this->createConversationPolicy($school, ['allow_student_conversations' => true]);
        $actor = $this->createUserWithCapabilities($school, ['communications.send', 'communications.conversations.students']);
        $student = $this->createStudent($school, ['first_name' => 'Sara', 'last_name' => 'Khan']);
        $year = $this->createAcademicYear($school);
        $campus = $this->createCampus($school);
        $gradeLevel = $this->createGradeLevel($school, ['name' => 'Grade 5']);
        $section = $this->createSection($year, $campus, $gradeLevel, ['name' => 'A']);
        $this->createStudentEnrollment($student, $section, ['status' => 'active']);
        $this->activate($actor, $school);

        $response = $this->getJson('/app/communications/participants/search/students?q=Sara')->assertOk();

        $response->assertJsonFragment(['id' => $student->id, 'gradeSectionLabel' => 'Grade 5 - A']);
    }

    #[Test]
    public function student_search_never_returns_a_raw_student_model_field_beyond_the_narrow_dto(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $this->createConversationPolicy($school, ['allow_student_conversations' => true]);
        $actor = $this->createUserWithCapabilities($school, ['communications.send', 'communications.conversations.students']);
        $this->createStudent($school, ['first_name' => 'Sara', 'last_name' => 'Khan']);
        $this->activate($actor, $school);

        $response = $this->getJson('/app/communications/participants/search/students?q=Sara')->assertOk();

        $decoded = $response->json('students');
        $this->assertNotEmpty($decoded);
        $this->assertSame(['id', 'label', 'gradeSectionLabel', 'accountLinked'], array_keys($decoded[0]));
    }

    #[Test]
    public function no_eligible_linked_student_produces_an_honest_empty_result_not_a_fabricated_one(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $this->createConversationPolicy($school, ['allow_student_conversations' => true]);
        $actor = $this->createUserWithCapabilities($school, ['communications.send', 'communications.conversations.students']);
        $this->activate($actor, $school);

        $this->getJson('/app/communications/participants/search/students?q=nobody')
            ->assertOk()
            ->assertExactJson(['students' => []]);
    }

    // --- §12/§34: dual-role -----------------------------------------------------

    #[Test]
    public function a_staff_membership_linked_as_guardian_is_found_via_both_the_member_and_guardian_search(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $teacher = $this->createUser(['name' => 'Priya Rao']);
        $teacherMembership = $this->createMembership($teacher, $school);
        $this->assignSchoolRole($teacherMembership, 'principal');
        $guardian = $this->createGuardian($school, ['first_name' => 'Priya', 'last_name' => 'Rao']);
        $this->links()->linkGuardian($school, $guardian, $teacherMembership, $admin);
        $this->activate($admin, $school);

        $this->getJson('/app/communications/participants/search?q=Priya')
            ->assertOk()
            ->assertJsonFragment(['userId' => $teacher->id, 'name' => 'Priya Rao']);

        $this->getJson('/app/communications/participants/search/guardians?q=Priya')
            ->assertOk()
            ->assertJsonFragment(['id' => $guardian->id, 'label' => 'Priya Rao', 'accountLinked' => true]);
    }

    #[Test]
    public function after_creation_the_thread_detail_shows_the_correct_provenance_for_a_dual_role_membership(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $teacher = $this->createUser();
        $teacherMembership = $this->createMembership($teacher, $school);
        $this->assignSchoolRole($teacherMembership, 'principal');
        $guardian = $this->createGuardian($school);
        $this->links()->linkGuardian($school, $guardian, $teacherMembership, $admin);
        $this->activate($admin, $school);

        $create = $this->post('/app/communications/conversations', [
            'thread_type' => 'direct',
            'guardian_ids' => [$guardian->id],
        ]);
        $create->assertRedirect();
        $threadUrl = $create->headers->get('Location');

        $this->get($threadUrl)->assertInertia(fn ($page) => $page
            ->where('participants', fn ($participants) => collect($participants)
                ->firstWhere('userId', $teacher->id)['domainParticipantType'] === 'guardian')
        );
    }

    // --- §28: thread detail badges (non-provenance participant unaffected) ----

    #[Test]
    public function an_ordinary_staff_participant_has_a_null_domain_participant_type(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $teacher = $this->createUser();
        $this->createMembership($teacher, $school);
        $this->activate($admin, $school);

        $create = $this->post('/app/communications/conversations', [
            'thread_type' => 'direct',
            'participant_user_ids' => [$teacher->id],
        ]);
        $threadUrl = $create->headers->get('Location');

        $this->get($threadUrl)->assertInertia(fn ($page) => $page
            ->where('participants', fn ($participants) => collect($participants)
                ->firstWhere('userId', $teacher->id)['domainParticipantType'] === null)
        );
    }
}

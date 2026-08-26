<?php

namespace Tests\Feature\Communications;

use App\Domain\Communications\Application\ConversationParticipantAuthorizationService;
use App\Domain\Communications\Application\Exceptions\ConversationParticipantNotLinkedException;
use App\Domain\Communications\Application\Exceptions\ConversationPolicyDisabledException;
use App\Domain\Communications\Application\Exceptions\ConversationTargetNotFoundException;
use App\Domain\Communications\Application\Exceptions\UnrelatedGuardianStudentConversationException;
use App\Domain\Identity\Application\AccountLinkService;
use Illuminate\Auth\Access\AuthorizationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCommunicationFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 5D.1 §18/§44/§45/§46 -- direct coverage of the ONE centralized
 * "may $actor start a private conversation with this Guardian/Student"
 * decision path, independent of the HTTP layer (see
 * tests/Feature/App/CommunicationConversationParticipantHubTest.php for
 * the end-to-end HTTP coverage of the same rules).
 */
class ConversationParticipantAuthorizationServiceTest extends TestCase
{
    use CreatesCommunicationFixtures, CreatesTenancyFixtures;

    private function service(): ConversationParticipantAuthorizationService
    {
        return app(ConversationParticipantAuthorizationService::class);
    }

    private function links(): AccountLinkService
    {
        return app(AccountLinkService::class);
    }

    // --- Guardian ---------------------------------------------------------

    #[Test]
    public function an_authorized_actor_resolves_a_linked_guardian_to_its_membership(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $member = $this->createUser();
        $membership = $this->createMembership($member, $school);
        $guardian = $this->createGuardian($school);
        $this->links()->linkGuardian($school, $guardian, $membership, $admin);

        $resolved = $this->service()->resolveGuardianParticipant($school, $admin, $guardian->id);

        $this->assertSame($membership->id, $resolved->id);
    }

    #[Test]
    public function an_actor_lacking_the_guardian_conversation_capability_is_denied(): void
    {
        [, $school] = $this->createSchoolAdmin('school_admin');
        $actor = $this->createUserWithCapabilities($school, ['communications.send']);
        $guardian = $this->createGuardian($school);

        $this->expectException(AuthorizationException::class);
        $this->service()->resolveGuardianParticipant($school, $actor, $guardian->id);
    }

    #[Test]
    public function guardian_conversations_disabled_by_school_policy_deny_even_a_capable_actor(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $this->createConversationPolicy($school, ['allow_guardian_conversations' => false]);
        $member = $this->createUser();
        $membership = $this->createMembership($member, $school);
        $guardian = $this->createGuardian($school);
        $this->links()->linkGuardian($school, $guardian, $membership, $admin);

        $this->expectException(ConversationPolicyDisabledException::class);
        $this->service()->resolveGuardianParticipant($school, $admin, $guardian->id);
    }

    #[Test]
    public function an_unlinked_guardian_is_denied(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $guardian = $this->createGuardian($school);

        $this->expectException(ConversationParticipantNotLinkedException::class);
        $this->service()->resolveGuardianParticipant($school, $admin, $guardian->id);
    }

    #[Test]
    public function a_guardian_linked_to_an_inactive_membership_is_denied(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $member = $this->createUser();
        $membership = $this->createMembership($member, $school, 'suspended');
        $guardian = $this->createGuardian($school);
        $this->links()->linkGuardian($school, $guardian, $membership, $admin);

        $this->expectException(ConversationParticipantNotLinkedException::class);
        $this->service()->resolveGuardianParticipant($school, $admin, $guardian->id);
    }

    #[Test]
    public function a_cross_school_guardian_id_is_not_found(): void
    {
        [$admin, $schoolA] = $this->createSchoolAdmin('school_admin');
        $schoolB = $this->createSchool();
        $guardianB = $this->createGuardian($schoolB);

        $this->expectException(ConversationTargetNotFoundException::class);
        $this->service()->resolveGuardianParticipant($schoolA, $admin, $guardianB->id);
    }

    // --- Student ------------------------------------------------------------

    #[Test]
    public function student_conversations_are_disabled_by_default_even_with_the_capability_granted(): void
    {
        [, $school] = $this->createSchoolAdmin('school_admin');
        $actor = $this->createUserWithCapabilities($school, ['communications.send', 'communications.conversations.students']);
        $student = $this->createStudent($school);

        $this->expectException(ConversationPolicyDisabledException::class);
        $this->service()->resolveStudentParticipant($school, $actor, $student->id);
    }

    #[Test]
    public function an_actor_lacking_the_student_conversation_capability_is_denied_even_when_the_school_policy_allows_it(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $this->createConversationPolicy($school, ['allow_student_conversations' => true]);
        $student = $this->createStudent($school);

        $this->expectException(AuthorizationException::class);
        $this->service()->resolveStudentParticipant($school, $admin, $student->id);
    }

    #[Test]
    public function a_linked_student_is_resolved_once_capability_and_school_policy_both_allow_it(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $this->createConversationPolicy($school, ['allow_student_conversations' => true]);
        $actor = $this->createUserWithCapabilities($school, ['communications.send', 'communications.conversations.students']);
        $member = $this->createUser();
        $membership = $this->createMembership($member, $school);
        $student = $this->createStudent($school);
        $this->links()->linkStudent($school, $student, $membership, $admin);

        $resolved = $this->service()->resolveStudentParticipant($school, $actor, $student->id);

        $this->assertSame($membership->id, $resolved->id);
    }

    #[Test]
    public function an_unlinked_student_is_denied_even_when_fully_authorized(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $this->createConversationPolicy($school, ['allow_student_conversations' => true]);
        $actor = $this->createUserWithCapabilities($school, ['communications.send', 'communications.conversations.students']);
        $student = $this->createStudent($school);

        $this->expectException(ConversationParticipantNotLinkedException::class);
        $this->service()->resolveStudentParticipant($school, $actor, $student->id);
    }

    // --- Guardian/Student relationship eligibility ---------------------------

    #[Test]
    public function an_eligible_primary_guardian_student_pair_passes(): void
    {
        [, $school] = $this->createSchoolAdmin('school_admin');
        $student = $this->createStudent($school);
        $guardian = $this->createGuardian($school);
        $this->createStudentGuardianRelationship($student, $guardian, ['is_primary' => true, 'is_legal_guardian' => false]);

        $this->service()->assertGuardianStudentRelationshipsEligible($school, [$guardian->id], [$student->id]);
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function an_unrelated_guardian_and_student_pair_is_rejected(): void
    {
        [, $school] = $this->createSchoolAdmin('school_admin');
        $student = $this->createStudent($school);
        $guardian = $this->createGuardian($school);

        $this->expectException(UnrelatedGuardianStudentConversationException::class);
        $this->service()->assertGuardianStudentRelationshipsEligible($school, [$guardian->id], [$student->id]);
    }

    #[Test]
    public function an_emergency_contact_only_relationship_is_not_eligible(): void
    {
        [, $school] = $this->createSchoolAdmin('school_admin');
        $student = $this->createStudent($school);
        $guardian = $this->createGuardian($school);
        $this->createStudentGuardianRelationship($student, $guardian, [
            'is_primary' => false, 'is_legal_guardian' => false, 'is_emergency_contact' => true, 'is_authorized_pickup' => true,
        ]);

        $this->expectException(UnrelatedGuardianStudentConversationException::class);
        $this->service()->assertGuardianStudentRelationshipsEligible($school, [$guardian->id], [$student->id]);
    }
}

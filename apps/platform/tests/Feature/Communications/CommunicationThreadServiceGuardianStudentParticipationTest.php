<?php

namespace Tests\Feature\Communications;

use App\Domain\Communications\Application\CommunicationThreadService;
use App\Domain\Communications\Application\Exceptions\ConversationParticipantNotLinkedException;
use App\Domain\Communications\Application\Exceptions\UnrelatedGuardianStudentConversationException;
use App\Domain\Communications\Domain\CommunicationParticipantKind;
use App\Domain\Communications\Infrastructure\CommunicationThread;
use App\Domain\Identity\Application\AccountLinkService;
use App\Models\SchoolAuditEvent;
use App\Support\Tenancy\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCommunicationFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 5D.1 §19-§22/§37/§48 -- CommunicationThreadService::createThread()'s
 * Guardian/Student participation extension: authorization, provenance
 * persistence, deduplication, dual-role behavior, and the dedicated
 * safeguarding audit events (brief §37).
 */
class CommunicationThreadServiceGuardianStudentParticipationTest extends TestCase
{
    use CreatesCommunicationFixtures, CreatesTenancyFixtures;

    private function threads(): CommunicationThreadService
    {
        return app(CommunicationThreadService::class);
    }

    private function links(): AccountLinkService
    {
        return app(AccountLinkService::class);
    }

    private function participantsOf(CommunicationThread $thread)
    {
        return app(TenantContext::class)->withSchool($thread->school, fn () => $thread->participants()->get());
    }

    #[Test]
    public function a_thread_created_with_a_linked_guardian_records_guardian_provenance(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $member = $this->createUser();
        $membership = $this->createMembership($member, $school);
        $guardian = $this->createGuardian($school);
        $this->links()->linkGuardian($school, $guardian, $membership, $admin);

        $thread = $this->threads()->createThread($school, $admin, 'direct', 'Re: attendance', guardianIds: [$guardian->id]);

        $participant = $this->participantsOf($thread)->firstWhere('user_id', $member->id);
        $this->assertNotNull($participant);
        $this->assertSame(CommunicationParticipantKind::Guardian, $participant->participant_kind);
        $this->assertSame($guardian->id, $participant->guardian_id);
        $this->assertNull($participant->student_id);
    }

    #[Test]
    public function a_thread_created_with_a_linked_student_records_student_provenance_when_policy_allows_it(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $this->createConversationPolicy($school, ['allow_student_conversations' => true]);
        $actor = $this->createUserWithCapabilities($school, ['communications.send', 'communications.conversations.students']);
        $member = $this->createUser();
        $membership = $this->createMembership($member, $school);
        $student = $this->createStudent($school);
        $this->links()->linkStudent($school, $student, $membership, $admin);

        $thread = $this->threads()->createThread($school, $actor, 'direct', null, studentIds: [$student->id]);

        $participant = $this->participantsOf($thread)->firstWhere('user_id', $member->id);
        $this->assertNotNull($participant);
        $this->assertSame(CommunicationParticipantKind::Student, $participant->participant_kind);
        $this->assertSame($student->id, $participant->student_id);
    }

    #[Test]
    public function an_unlinked_guardian_target_aborts_thread_creation_entirely(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $guardian = $this->createGuardian($school);

        $countBefore = app(TenantContext::class)->withSchool($school, fn () => CommunicationThread::query()->count());

        try {
            $this->threads()->createThread($school, $admin, 'direct', null, guardianIds: [$guardian->id]);
            $this->fail('Expected ConversationParticipantNotLinkedException.');
        } catch (ConversationParticipantNotLinkedException) {
            // expected
        }

        $countAfter = app(TenantContext::class)->withSchool($school, fn () => CommunicationThread::query()->count());
        $this->assertSame($countBefore, $countAfter, 'No partial thread should ever be created.');
    }

    #[Test]
    public function the_same_underlying_membership_named_as_both_a_guardian_and_a_plain_participant_is_added_only_once(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $member = $this->createUser();
        $membership = $this->createMembership($member, $school);
        $guardian = $this->createGuardian($school);
        $this->links()->linkGuardian($school, $guardian, $membership, $admin);

        $thread = $this->threads()->createThread(
            $school, $admin, 'group', null,
            participantUserIds: [$member->id],
            guardianIds: [$guardian->id],
        );

        $participants = $this->participantsOf($thread)->where('user_id', $member->id);
        $this->assertCount(1, $participants, 'Must not create a duplicate participant row.');
        // Guardian provenance takes priority over a plain membership add.
        $this->assertSame(CommunicationParticipantKind::Guardian, $participants->first()->participant_kind);
    }

    #[Test]
    public function a_staff_membership_linked_as_guardian_can_participate_in_both_a_staff_context_and_a_guardian_context_thread(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $teacherUser = $this->createUser();
        $teacherMembership = $this->createMembership($teacherUser, $school);
        $this->assignSchoolRole($teacherMembership, 'principal');
        $guardian = $this->createGuardian($school);
        $this->links()->linkGuardian($school, $guardian, $teacherMembership, $admin);

        $staffThread = $this->threads()->createThread($school, $admin, 'direct', 'Staff matter', participantUserIds: [$teacherUser->id]);
        $guardianThread = $this->threads()->createThread($school, $admin, 'direct', 'Parent matter', guardianIds: [$guardian->id]);

        $staffParticipant = $this->participantsOf($staffThread)->firstWhere('user_id', $teacherUser->id);
        $guardianParticipant = $this->participantsOf($guardianThread)->firstWhere('user_id', $teacherUser->id);

        $this->assertSame(CommunicationParticipantKind::Membership, $staffParticipant->participant_kind);
        $this->assertSame(CommunicationParticipantKind::Guardian, $guardianParticipant->participant_kind);
        $this->assertSame($guardian->id, $guardianParticipant->guardian_id);
    }

    #[Test]
    public function guardian_and_student_together_requires_an_eligible_relationship(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $this->createConversationPolicy($school, ['allow_student_conversations' => true]);
        $actor = $this->createUserWithCapabilities($school, [
            'communications.send', 'communications.conversations.guardians', 'communications.conversations.students',
        ]);

        $student = $this->createStudent($school);
        $studentMembership = $this->createMembership($this->createUser(), $school);
        $this->links()->linkStudent($school, $student, $studentMembership, $admin);

        $unrelatedGuardian = $this->createGuardian($school);
        $unrelatedMembership = $this->createMembership($this->createUser(), $school);
        $this->links()->linkGuardian($school, $unrelatedGuardian, $unrelatedMembership, $admin);

        $this->expectException(UnrelatedGuardianStudentConversationException::class);
        $this->threads()->createThread($school, $actor, 'group', null, guardianIds: [$unrelatedGuardian->id], studentIds: [$student->id]);
    }

    #[Test]
    public function guardian_and_student_together_succeeds_for_an_eligible_relationship(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $this->createConversationPolicy($school, ['allow_student_conversations' => true]);
        $actor = $this->createUserWithCapabilities($school, [
            'communications.send', 'communications.conversations.guardians', 'communications.conversations.students',
        ]);

        $student = $this->createStudent($school);
        $studentMembership = $this->createMembership($this->createUser(), $school);
        $this->links()->linkStudent($school, $student, $studentMembership, $admin);

        $guardian = $this->createGuardian($school);
        $guardianMembership = $this->createMembership($this->createUser(), $school);
        $this->links()->linkGuardian($school, $guardian, $guardianMembership, $admin);
        $this->createStudentGuardianRelationship($student, $guardian, ['is_primary' => true]);

        $thread = $this->threads()->createThread($school, $actor, 'group', null, guardianIds: [$guardian->id], studentIds: [$student->id]);

        $participants = $this->participantsOf($thread);
        $this->assertNotNull($participants->firstWhere('user_id', $studentMembership->user_id));
        $this->assertNotNull($participants->firstWhere('user_id', $guardianMembership->user_id));
    }

    #[Test]
    public function starting_a_guardian_conversation_writes_a_dedicated_security_sensitive_audit_event(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $member = $this->createUser();
        $membership = $this->createMembership($member, $school);
        $guardian = $this->createGuardian($school);
        $this->links()->linkGuardian($school, $guardian, $membership, $admin);

        $thread = $this->threads()->createThread($school, $admin, 'direct', null, guardianIds: [$guardian->id]);

        $event = app(TenantContext::class)->withSchool($school, fn () => SchoolAuditEvent::query()
            ->where('event_type', 'communication.conversation.guardian_participant_added')
            ->first());

        $this->assertNotNull($event);
        $this->assertSame($thread->id, $event->metadata['threadId']);
        $this->assertSame($guardian->id, $event->metadata['guardianId']);
        $this->assertSame('guardian', $event->metadata['domainParticipantType']);
    }
}

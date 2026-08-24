<?php

namespace Tests\Feature\Communications;

use App\Domain\Communications\Application\CommunicationThreadService;
use App\Domain\Communications\Application\Exceptions\InvalidParticipantException;
use App\Models\SchoolAuditEvent;
use App\Support\Tenancy\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCommunicationFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 5A.1 §19/§25: domain-layer coverage for thread creation and
 * participant association -- through the real service, not raw model
 * writes, so authorization/tenancy invariants are actually exercised.
 */
class CommunicationThreadServiceTest extends TestCase
{
    use CreatesCommunicationFixtures, CreatesTenancyFixtures;

    #[Test]
    public function creating_a_thread_adds_the_creator_and_named_participants(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $teacher = $this->createUser();
        $this->createMembership($teacher, $school);

        $thread = app(CommunicationThreadService::class)->createThread(
            $school,
            $creator,
            'direct',
            'Attendance question',
            [$teacher->id],
        );

        $participantUserIds = app(TenantContext::class)->withSchool(
            $school,
            fn () => $thread->participants()->pluck('user_id')->all(),
        );

        $this->assertEqualsCanonicalizing([$creator->id, $teacher->id], $participantUserIds);
        $this->assertSame('Attendance question', $thread->subject);
    }

    #[Test]
    public function creating_a_thread_is_audited(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');

        $thread = app(CommunicationThreadService::class)->createThread($school, $creator, 'direct', null, []);

        $count = app(TenantContext::class)->withSchool(
            $school,
            fn () => SchoolAuditEvent::query()
                ->where('event_type', 'communication.thread.created')
                ->where('subject_id', $thread->id)
                ->count(),
        );

        $this->assertSame(1, $count);
    }

    #[Test]
    public function a_user_with_no_active_membership_cannot_be_added_as_a_participant(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $outsider = $this->createUser(); // no SchoolMembership at all

        $this->expectException(InvalidParticipantException::class);

        app(CommunicationThreadService::class)->createThread($school, $creator, 'direct', null, [$outsider->id]);
    }

    #[Test]
    public function removing_a_participant_sets_left_at_rather_than_deleting_the_row(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $teacher = $this->createUser();
        $this->createMembership($teacher, $school);

        $service = app(CommunicationThreadService::class);
        $thread = $service->createThread($school, $creator, 'direct', null, [$teacher->id]);

        $participant = app(TenantContext::class)->withSchool(
            $school,
            fn () => $thread->participants()->where('user_id', $teacher->id)->firstOrFail(),
        );

        $removed = $service->removeParticipant($participant, $creator);

        $this->assertNotNull($removed->left_at);
    }

    #[Test]
    public function a_school_a_thread_cannot_add_a_school_b_users_membership_as_a_participant(): void
    {
        [$creatorA, $schoolA] = $this->createSchoolAdmin('school_admin');
        $userB = $this->createUser();
        $schoolB = $this->createSchool();
        $this->createMembership($userB, $schoolB); // active membership, but in School B, not School A

        $this->expectException(InvalidParticipantException::class);

        app(CommunicationThreadService::class)->createThread($schoolA, $creatorA, 'direct', null, [$userB->id]);
    }
}

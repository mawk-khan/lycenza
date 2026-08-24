<?php

namespace Tests\Feature\Communications;

use App\Domain\Communications\Application\AnnouncementService;
use App\Domain\Communications\Application\Exceptions\EmergencyCannotBeScheduledException;
use App\Domain\Communications\Application\Exceptions\EmergencyJustificationRequiredException;
use App\Domain\Communications\Application\Exceptions\EmergencyMustBeRequiredException;
use App\Domain\Communications\Application\Exceptions\InvalidAnnouncementTransitionException;
use App\Domain\Communications\Domain\CommunicationAudienceType;
use App\Domain\Communications\Domain\CommunicationDispatchMode;
use App\Domain\Communications\Domain\CommunicationPriority;
use App\Domain\Communications\Domain\CommunicationRequirement;
use App\Models\School;
use App\Models\SchoolAuditEvent;
use App\Support\Tenancy\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCommunicationFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 5A.10 §6/§26-28/§49 -- domain-level invariants for the
 * STANDARD/EMERGENCY dispatch mode, exercised directly against
 * App\Domain\Communications\Application\AnnouncementService so an
 * Application-layer caller gets the same guarantee an HTTP caller does
 * (root CLAUDE.md rule 3's controller/service boundary).
 */
class AnnouncementEmergencyDomainTest extends TestCase
{
    use CreatesCommunicationFixtures, CreatesTenancyFixtures;

    private function service(): AnnouncementService
    {
        return app(AnnouncementService::class);
    }

    private function auditCount(string $schoolId, string $eventType, string $subjectId): int
    {
        $school = School::query()->find($schoolId);

        return app(TenantContext::class)->withSchool(
            $school,
            fn () => SchoolAuditEvent::query()->where('event_type', $eventType)->where('subject_id', $subjectId)->count(),
        );
    }

    #[Test]
    public function emergency_requires_required_and_is_rejected_when_optional(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');

        $this->expectException(EmergencyMustBeRequiredException::class);

        $this->service()->createDraft(
            $school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide,
            requirement: CommunicationRequirement::Optional,
            dispatchMode: CommunicationDispatchMode::Emergency,
            emergencyJustification: 'Building evacuation.',
        );
    }

    #[Test]
    public function emergency_with_required_and_justification_is_valid(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');

        $announcement = $this->service()->createDraft(
            $school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide,
            requirement: CommunicationRequirement::Required,
            dispatchMode: CommunicationDispatchMode::Emergency,
            emergencyJustification: 'Building evacuation.',
        );

        $this->assertTrue($announcement->isEmergency());
        $this->assertSame('Building evacuation.', $announcement->emergency_justification);
        $this->assertSame($creator->id, $announcement->emergency_declared_by_user_id);
        $this->assertNotNull($announcement->emergency_declared_at);
    }

    #[Test]
    public function standard_optional_matches_pre_5a10_behavior(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');

        $announcement = $this->service()->createDraft(
            $school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide,
            requirement: CommunicationRequirement::Optional,
        );

        $this->assertFalse($announcement->isEmergency());
        $this->assertSame('standard', $announcement->dispatch_mode);
        $this->assertNull($announcement->emergency_declared_by_user_id);
    }

    #[Test]
    public function standard_required_matches_pre_5a10_behavior(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');

        $announcement = $this->service()->createDraft(
            $school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide,
            requirement: CommunicationRequirement::Required,
        );

        $this->assertFalse($announcement->isEmergency());
        $this->assertSame('required', $announcement->requirement);
    }

    #[Test]
    public function emergency_without_justification_is_rejected(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');

        $this->expectException(EmergencyJustificationRequiredException::class);

        $this->service()->createDraft(
            $school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide,
            requirement: CommunicationRequirement::Required,
            dispatchMode: CommunicationDispatchMode::Emergency,
            emergencyJustification: '   ',
        );
    }

    #[Test]
    public function an_emergency_draft_cannot_be_scheduled(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');

        $announcement = $this->service()->createDraft(
            $school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide,
            requirement: CommunicationRequirement::Required,
            dispatchMode: CommunicationDispatchMode::Emergency,
            emergencyJustification: 'Evacuation.',
        );

        $this->expectException(EmergencyCannotBeScheduledException::class);
        $this->service()->schedule($announcement, $creator, now()->addHour());
    }

    #[Test]
    public function a_scheduled_standard_announcement_cannot_be_updated_into_emergency(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');

        $announcement = $this->service()->createDraft(
            $school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide,
        );
        $scheduled = $this->service()->schedule($announcement, $creator, now()->addHour());

        $this->expectException(EmergencyCannotBeScheduledException::class);
        $this->service()->updateDraft(
            $scheduled, $creator,
            requirement: CommunicationRequirement::Required,
            dispatchMode: CommunicationDispatchMode::Emergency,
            emergencyJustification: 'Evacuation.',
        );
    }

    #[Test]
    public function a_published_standard_announcement_cannot_be_edited_into_emergency(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $member = $this->createUser();
        $this->createMembership($member, $school);

        $announcement = $this->service()->createDraft(
            $school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide,
        );
        $published = $this->service()->publish($announcement, $creator);

        $this->expectException(InvalidAnnouncementTransitionException::class);
        $this->service()->updateDraft(
            $published, $creator,
            requirement: CommunicationRequirement::Required,
            dispatchMode: CommunicationDispatchMode::Emergency,
            emergencyJustification: 'Too late.',
        );
    }

    #[Test]
    public function a_published_emergency_announcement_cannot_be_edited_into_standard(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $member = $this->createUser();
        $this->createMembership($member, $school);

        $announcement = $this->service()->createDraft(
            $school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide,
            requirement: CommunicationRequirement::Required,
            dispatchMode: CommunicationDispatchMode::Emergency,
            emergencyJustification: 'Evacuation.',
        );
        $published = $this->service()->publish($announcement, $creator);
        $this->assertTrue($published->isEmergency());

        $this->expectException(InvalidAnnouncementTransitionException::class);
        $this->service()->updateDraft($published, $creator, dispatchMode: CommunicationDispatchMode::Standard);
    }

    #[Test]
    public function removing_emergency_on_a_draft_clears_justification_and_declaration_metadata(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');

        $announcement = $this->service()->createDraft(
            $school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide,
            requirement: CommunicationRequirement::Required,
            dispatchMode: CommunicationDispatchMode::Emergency,
            emergencyJustification: 'Evacuation.',
        );

        $updated = $this->service()->updateDraft($announcement, $creator, dispatchMode: CommunicationDispatchMode::Standard);

        $this->assertFalse($updated->isEmergency());
        $this->assertNull($updated->emergency_justification);
        $this->assertNull($updated->emergency_declared_by_user_id);
        $this->assertNull($updated->emergency_declared_at);
    }

    #[Test]
    public function editing_an_unrelated_field_on_an_emergency_draft_preserves_emergency_status(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');

        $announcement = $this->service()->createDraft(
            $school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide,
            requirement: CommunicationRequirement::Required,
            dispatchMode: CommunicationDispatchMode::Emergency,
            emergencyJustification: 'Evacuation.',
        );

        $updated = $this->service()->updateDraft($announcement, $creator, title: 'Updated title');

        $this->assertTrue($updated->isEmergency());
        $this->assertSame('Evacuation.', $updated->emergency_justification);
        $this->assertSame('Updated title', $updated->title);
    }

    #[Test]
    public function declaring_emergency_at_creation_is_audited(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');

        $announcement = $this->service()->createDraft(
            $school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide,
            requirement: CommunicationRequirement::Required,
            dispatchMode: CommunicationDispatchMode::Emergency,
            emergencyJustification: 'Evacuation.',
        );

        $this->assertSame(1, $this->auditCount($school->id, 'announcement.emergency_declared', $announcement->id));
    }

    #[Test]
    public function declaring_emergency_via_update_is_audited(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');

        $announcement = $this->service()->createDraft(
            $school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide,
        );

        $this->service()->updateDraft(
            $announcement, $creator,
            requirement: CommunicationRequirement::Required,
            dispatchMode: CommunicationDispatchMode::Emergency,
            emergencyJustification: 'Evacuation.',
        );

        $this->assertSame(1, $this->auditCount($school->id, 'announcement.emergency_declared', $announcement->id));
    }

    #[Test]
    public function publishing_an_emergency_announcement_emits_the_emergency_published_event(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $member = $this->createUser();
        $this->createMembership($member, $school);

        $announcement = $this->service()->createDraft(
            $school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide,
            requirement: CommunicationRequirement::Required,
            dispatchMode: CommunicationDispatchMode::Emergency,
            emergencyJustification: 'Evacuation.',
        );
        $published = $this->service()->publish($announcement, $creator);

        $this->assertSame(1, $this->auditCount($school->id, 'announcement.emergency_published', $published->id));
        $this->assertSame(1, $this->auditCount($school->id, 'announcement.published', $published->id));
    }

    #[Test]
    public function publishing_a_standard_announcement_never_emits_the_emergency_published_event(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $member = $this->createUser();
        $this->createMembership($member, $school);

        $announcement = $this->service()->createDraft(
            $school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide,
        );
        $published = $this->service()->publish($announcement, $creator);

        $this->assertSame(0, $this->auditCount($school->id, 'announcement.emergency_published', $published->id));
    }
}

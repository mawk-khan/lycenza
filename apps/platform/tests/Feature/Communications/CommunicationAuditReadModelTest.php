<?php

namespace Tests\Feature\Communications;

use App\Domain\Communications\Application\AnnouncementService;
use App\Domain\Communications\Application\Approval\CommunicationApprovalService;
use App\Domain\Communications\Application\CommunicationAuditReadModel;
use App\Domain\Communications\Domain\CommunicationAudienceType;
use App\Domain\Communications\Domain\CommunicationChannel;
use App\Domain\Communications\Domain\CommunicationDispatchMode;
use App\Domain\Communications\Domain\CommunicationPriority;
use App\Domain\Communications\Domain\CommunicationRequirement;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCommunicationFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 5A.11 §58 -- audit timeline projection correctness: real
 * historical events appear, presentation labels are normalized without
 * rewriting the stored event_type, and Emergency justification is
 * redacted for an unauthorized viewer.
 */
class CommunicationAuditReadModelTest extends TestCase
{
    use CreatesCommunicationFixtures, CreatesTenancyFixtures;

    private function announcements(): AnnouncementService
    {
        return app(AnnouncementService::class);
    }

    private function readModel(): CommunicationAuditReadModel
    {
        return app(CommunicationAuditReadModel::class);
    }

    #[Test]
    public function the_timeline_shows_created_and_published_events_in_order(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $this->createMembership($this->createUser(), $school);

        $announcement = $this->announcements()->createDraft(
            $school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide,
        );
        $published = $this->announcements()->publish($announcement, $creator);

        $timeline = $this->readModel()->timelineForAnnouncement($school, $published, includeEmergencyJustification: true);
        $events = collect($timeline->items())->pluck('event')->all();

        $this->assertSame(['announcement.created', 'announcement.published'], $events);
        $this->assertSame('Announcement created', $timeline->items()[0]->label);
        $this->assertSame($creator->name, $timeline->items()[0]->actorName);
        $this->assertFalse($timeline->items()[0]->isEmergency);
    }

    #[Test]
    public function emergency_declaration_is_a_distinct_flagged_event_with_justification_visible_when_authorized(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $this->createMembership($this->createUser(), $school);

        $announcement = $this->announcements()->createDraft(
            $school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide,
            requirement: CommunicationRequirement::Required,
            dispatchMode: CommunicationDispatchMode::Emergency,
            emergencyJustification: 'Severe weather warning.',
        );

        $timeline = $this->readModel()->timelineForAnnouncement($school, $announcement, includeEmergencyJustification: true);
        $declared = collect($timeline->items())->first(fn ($e) => $e->event === 'announcement.emergency_declared');

        $this->assertNotNull($declared);
        $this->assertTrue($declared->isEmergency);
        $this->assertSame('Marked Emergency', $declared->label);
        $this->assertSame('Severe weather warning.', $declared->metadata['justification']);
    }

    #[Test]
    public function emergency_justification_is_redacted_for_an_unauthorized_viewer(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');

        $announcement = $this->announcements()->createDraft(
            $school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide,
            requirement: CommunicationRequirement::Required,
            dispatchMode: CommunicationDispatchMode::Emergency,
            emergencyJustification: 'Severe weather warning.',
        );

        $timeline = $this->readModel()->timelineForAnnouncement($school, $announcement, includeEmergencyJustification: false);
        $declared = collect($timeline->items())->first(fn ($e) => $e->event === 'announcement.emergency_declared');

        $this->assertNotNull($declared);
        $this->assertArrayNotHasKey('justification', $declared->metadata);
    }

    #[Test]
    public function a_quiet_hours_bypass_event_appears_in_the_timeline(): void
    {
        $school = $this->createSchool(['timezone' => 'Asia/Kolkata']);
        $creator = $this->createUser();
        $creatorMembership = $this->createMembership($creator, $school);
        $this->assignSchoolRole($creatorMembership, 'school_admin');
        $this->createMembership($this->createUser(['email' => 'member@school-os.test']), $school);
        $this->createDeliveryTimingPolicy($school, [
            'channel' => 'email', 'enabled' => true,
            'quiet_hours_start' => '20:00:00', 'quiet_hours_end' => '07:00:00',
            'emergency_bypass_allowed' => true,
        ]);

        $this->travelTo(Carbon::parse('2026-08-23 22:00:00', 'Asia/Kolkata'));

        Config::set('communications.channels.email.enabled', true);
        Mail::fake();

        $announcement = $this->announcements()->createDraft(
            $school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide,
            channels: [CommunicationChannel::InApp, CommunicationChannel::Email],
            requirement: CommunicationRequirement::Required,
            dispatchMode: CommunicationDispatchMode::Emergency,
            emergencyJustification: 'Evacuation.',
        );
        $published = $this->announcements()->publish($announcement, $creator);

        $timeline = $this->readModel()->timelineForAnnouncement($school, $published, includeEmergencyJustification: true);
        $bypass = collect($timeline->items())->first(fn ($e) => $e->event === 'communication.emergency_quiet_hours_bypass_used');

        $this->assertNotNull($bypass);
        $this->assertSame('Quiet-hours bypass used', $bypass->label);
        $this->assertSame('email', $bypass->metadata['channel']);
        $this->assertTrue($bypass->isEmergency);
    }

    #[Test]
    public function school_a_cannot_see_school_bs_audit_timeline(): void
    {
        [$creatorA, $schoolA] = $this->createSchoolAdmin('school_admin');
        $announcementA = $this->announcements()->createDraft(
            $schoolA, $creatorA, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide,
        );

        [$creatorB, $schoolB] = $this->createSchoolAdmin('school_admin');

        $timeline = $this->readModel()->timelineForAnnouncement($schoolB, $announcementA, includeEmergencyJustification: true);

        $this->assertSame(0, $timeline->total());
    }

    /**
     * Phase 5A.12 §47/§91 -- the approval workflow's own events (requested,
     * approved, invalidated) become visible through THIS SAME Phase
     * 5A.11 audit surface -- no separate approval-specific audit view.
     */
    #[Test]
    public function approval_requested_approved_and_invalidated_events_appear_in_the_timeline(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $approver = $this->createUser();
        $approverMembership = $this->createMembership($approver, $school);
        $this->assignSchoolRole($approverMembership, 'principal');
        $this->createMembership($this->createUser(), $school);
        $this->createApprovalPolicy($school, ['require_school_wide_approval' => true]);

        $announcement = $this->announcements()->createDraft(
            $school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide,
        );
        $request = app(CommunicationApprovalService::class)->submit($announcement, $creator);
        app(CommunicationApprovalService::class)->approve($request, $approver, 'looks good');

        // Editing the now-approved announcement invalidates it.
        $this->announcements()->updateDraft($announcement, $creator, title: 'Changed');

        $timeline = $this->readModel()->timelineForAnnouncement($school, $announcement, includeEmergencyJustification: true);
        $events = collect($timeline->items())->pluck('event')->all();

        $this->assertContains('announcement.approval_requested', $events);
        $this->assertContains('announcement.approved', $events);
        $this->assertContains('announcement.approval_invalidated', $events);

        $approvedEntry = collect($timeline->items())->first(fn ($e) => $e->event === 'announcement.approved');
        $this->assertSame('Approved', $approvedEntry->label);
        $this->assertSame($approver->name, $approvedEntry->actorName);
        $this->assertSame('looks good', $approvedEntry->metadata['decisionNote']);
    }
}

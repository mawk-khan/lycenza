<?php

namespace Tests\Feature\Communications;

use App\Domain\Communications\Application\AnnouncementService;
use App\Domain\Communications\Application\Exceptions\EmptyAudienceException;
use App\Domain\Communications\Application\Exceptions\InvalidAnnouncementTransitionException;
use App\Domain\Communications\Application\Exceptions\InvalidAudienceMemberException;
use App\Domain\Communications\Domain\CommunicationAudienceType;
use App\Domain\Communications\Domain\CommunicationPriority;
use App\Domain\Communications\Infrastructure\CommunicationAnnouncementRecipient;
use App\Domain\Communications\Infrastructure\CommunicationDelivery;
use App\Domain\Communications\Infrastructure\CommunicationRecipient;
use App\Support\Tenancy\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCommunicationFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 5A.2 §27: announcement domain lifecycle, audience resolution,
 * deduplication, idempotent publish, and delivery-pipeline reuse.
 */
class AnnouncementServiceTest extends TestCase
{
    use CreatesCommunicationFixtures, CreatesTenancyFixtures;

    private function service(): AnnouncementService
    {
        return app(AnnouncementService::class);
    }

    #[Test]
    public function creating_a_draft_school_wide_announcement_persists_it_as_draft(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');

        $announcement = $this->service()->createDraft(
            $school, $creator, 'Annual Day', 'Rehearsal at 8:30 AM.',
            CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide,
        );

        $this->assertSame('draft', $announcement->status);
        $this->assertSame('school_wide', $announcement->audience_type);
        $this->assertNull($announcement->published_at);
    }

    #[Test]
    public function creating_a_draft_with_an_individual_audience_rejects_a_non_member_user_id(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $outsider = $this->createUser();

        $this->expectException(InvalidAudienceMemberException::class);

        $this->service()->createDraft(
            $school, $creator, 'Title', 'Body',
            CommunicationPriority::Normal, CommunicationAudienceType::Individual,
            [$outsider->id],
        );
    }

    #[Test]
    public function updating_a_draft_changes_its_content(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $announcement = $this->service()->createDraft($school, $creator, 'Old', 'Old body', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide);

        $updated = $this->service()->updateDraft($announcement, $creator, title: 'New');

        $this->assertSame('New', $updated->title);
        $this->assertSame('Old body', $updated->body);
    }

    #[Test]
    public function a_published_announcement_cannot_be_edited(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $this->createMembership($this->createUser(), $school);
        $announcement = $this->service()->createDraft($school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide);
        $published = $this->service()->publish($announcement, $creator);

        $this->expectException(InvalidAnnouncementTransitionException::class);

        $this->service()->updateDraft($published, $creator, title: 'Nope');
    }

    #[Test]
    public function a_published_announcement_cannot_be_cancelled(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $this->createMembership($this->createUser(), $school);
        $announcement = $this->service()->createDraft($school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide);
        $this->service()->publish($announcement, $creator);

        $this->expectException(InvalidAnnouncementTransitionException::class);

        $this->service()->cancel($announcement, $creator);
    }

    #[Test]
    public function cancelling_a_draft_marks_it_cancelled_and_cancelling_twice_is_idempotent(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $announcement = $this->service()->createDraft($school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide);

        $cancelled = $this->service()->cancel($announcement, $creator);
        $this->assertSame('cancelled', $cancelled->status);

        $cancelledAgain = $this->service()->cancel($cancelled, $creator);
        $this->assertSame('cancelled', $cancelledAgain->status);
    }

    #[Test]
    public function publishing_with_an_empty_resolved_audience_throws_and_leaves_the_announcement_a_draft(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        // No other members in this School -- school-wide resolves to empty.
        $announcement = $this->service()->createDraft($school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide);

        try {
            $this->service()->publish($announcement, $creator);
            $this->fail('Expected EmptyAudienceException.');
        } catch (EmptyAudienceException) {
            // expected
        }

        $fresh = app(TenantContext::class)->withSchool($school, fn () => $announcement->fresh());
        $this->assertSame('draft', $fresh->status);
    }

    #[Test]
    public function publishing_a_school_wide_announcement_creates_a_message_recipients_snapshot_and_in_app_deliveries(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $memberA = $this->createUser();
        $memberB = $this->createUser();
        $this->createMembership($memberA, $school);
        $this->createMembership($memberB, $school);

        $announcement = $this->service()->createDraft($school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide);
        $published = $this->service()->publish($announcement, $creator);

        $this->assertSame('published', $published->status);
        $this->assertSame(2, $published->recipient_count);
        $this->assertNotNull($published->message_id);

        $context = app(TenantContext::class);
        [$snapshotCount, $recipientCount, $deliveryCount] = $context->withSchool($school, fn () => [
            CommunicationAnnouncementRecipient::query()->where('announcement_id', $published->id)->count(),
            CommunicationRecipient::query()->where('message_id', $published->message_id)->count(),
            CommunicationDelivery::query()->whereIn('recipient_id', CommunicationRecipient::query()->where('message_id', $published->message_id)->pluck('id'))->count(),
        ]);

        $this->assertSame(2, $snapshotCount);
        $this->assertSame(2, $recipientCount);
        $this->assertSame(2, $deliveryCount);
    }

    #[Test]
    public function the_creator_is_excluded_from_their_own_school_wide_announcement_audience(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $this->createMembership($this->createUser(), $school);

        $announcement = $this->service()->createDraft($school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide);
        $preview = $this->service()->previewAudience($announcement);

        $this->assertNotContains($creator->id, $preview->userIds);
    }

    #[Test]
    public function an_inactive_member_is_excluded_from_a_school_wide_audience(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $activeMember = $this->createUser();
        $suspendedMember = $this->createUser();
        $this->createMembership($activeMember, $school, 'active');
        $this->createMembership($suspendedMember, $school, 'suspended');

        $announcement = $this->service()->createDraft($school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide);
        $preview = $this->service()->previewAudience($announcement);

        $this->assertContains($activeMember->id, $preview->userIds);
        $this->assertNotContains($suspendedMember->id, $preview->userIds);
    }

    #[Test]
    public function a_school_wide_audience_deduplicates_and_reports_a_deterministic_count(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $member = $this->createUser();
        $this->createMembership($member, $school);

        $announcement = $this->service()->createDraft($school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide);

        $first = $this->service()->previewAudience($announcement);
        $second = $this->service()->previewAudience($announcement);

        $this->assertSame(1, $first->count());
        $this->assertSame($first->userIds, $second->userIds);
        $this->assertSame(count($first->userIds), count(array_unique($first->userIds)));
    }

    #[Test]
    public function an_individual_audience_resolves_exactly_the_selected_active_members(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $selected = $this->createUser();
        $notSelected = $this->createUser();
        $this->createMembership($selected, $school);
        $this->createMembership($notSelected, $school);

        $announcement = $this->service()->createDraft(
            $school, $creator, 'T', 'B', CommunicationPriority::Normal,
            CommunicationAudienceType::Individual, [$selected->id],
        );
        $preview = $this->service()->previewAudience($announcement);

        $this->assertSame([$selected->id], $preview->userIds);
    }

    #[Test]
    public function publishing_the_same_announcement_twice_does_not_duplicate_recipients_or_deliveries(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $member = $this->createUser();
        $this->createMembership($member, $school);

        $announcement = $this->service()->createDraft($school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide);
        $firstPublish = $this->service()->publish($announcement, $creator);
        $secondPublish = $this->service()->publish($firstPublish, $creator);

        $this->assertSame($firstPublish->message_id, $secondPublish->message_id);
        $this->assertSame($firstPublish->published_at?->toIso8601String(), $secondPublish->published_at?->toIso8601String());

        $context = app(TenantContext::class);
        $snapshotCount = $context->withSchool(
            $school,
            fn () => CommunicationAnnouncementRecipient::query()->where('announcement_id', $announcement->id)->count(),
        );
        $this->assertSame(1, $snapshotCount);
    }
}

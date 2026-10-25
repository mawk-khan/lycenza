<?php

namespace Tests\Feature\Communications;

use App\Domain\Communications\Application\Approval\CommunicationApprovalService;
use App\Domain\Communications\Application\Exceptions\ApprovalAlreadyDecidedException;
use App\Domain\Communications\Application\Exceptions\ApprovalRequiredException;
use App\Domain\Communications\Application\Exceptions\InvalidAnnouncementTransitionException;
use App\Domain\Communications\Application\Exceptions\InvalidCommunicationEventException;
use App\Domain\Communications\Application\Exceptions\SelfApprovalNotAllowedException;
use App\Domain\Communications\Infrastructure\CommunicationApprovalRequest;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Feature\Communications\Concerns\CreatesCommunicationEventFixtures;
use Tests\TestCase;

/**
 * ORG.9D-3 (ADR 0076 §75): an Event's lifecycle on the existing
 * Communications approval workflow -- draft, submit, approve/reject,
 * publish, cancel -- with maker/checker, idempotent publish and cancel, a
 * material edit invalidating approval, database-immutable published terms,
 * publication-preserving cancellation, and audit without the body.
 */
class CommunicationEventLifecycleTest extends TestCase
{
    use CreatesCommunicationEventFixtures, CreatesTenancyFixtures;

    private School $school;

    private User $preparer;

    private User $approver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-01T06:00:00Z'));
        $this->school = $this->createSchool(['timezone' => 'Asia/Kolkata']);
        $this->preparer = $this->createUserWithCapabilities($this->school, ['communications.view', 'communications.announce', 'communications.approve']);
        $this->approver = $this->createUserWithCapabilities($this->school, ['communications.view', 'communications.approve']);
    }

    private function inSchool(callable $fn): mixed
    {
        return app(TenantContext::class)->withSchool($this->school, $fn);
    }

    #[Test]
    public function draft_submit_approve_publish_and_cancel_keep_one_coherent_record(): void
    {
        $event = $this->draftEvent($this->school, $this->preparer);
        $this->assertSame('draft', $event->status);

        $event = $this->events()->update($event, $this->preparer, $this->terms($this->school, ['title' => 'Annual sports day']));
        $this->assertSame('Annual sports day', $event->title);

        $this->assertThrows(fn () => $this->events()->publish($this->freshEvent($event), $this->preparer), ApprovalRequiredException::class);
        $request = $this->events()->submit($this->freshEvent($event), $this->preparer);
        $this->assertSame('pending_approval', $this->freshEvent($event)->status);
        $this->assertThrows(fn () => $this->events()->update($this->freshEvent($event), $this->preparer, $this->terms($this->school)), InvalidAnnouncementTransitionException::class);

        $approvals = app(CommunicationApprovalService::class);
        $this->assertThrows(fn () => $approvals->approve($request, $this->preparer), SelfApprovalNotAllowedException::class);
        $approvals->approve($request, $this->approver);
        $this->assertThrows(fn () => $approvals->approve($this->inSchool(fn () => $request->fresh()), $this->approver), ApprovalAlreadyDecidedException::class);
        $this->assertSame('approved', $this->freshEvent($event)->status);

        $published = $this->events()->publish($this->freshEvent($event), $this->preparer);
        $again = $this->events()->publish($this->freshEvent($event), $this->preparer);
        $this->assertSame('published', $published->status);
        $this->assertTrue($published->published_at->equalTo($again->published_at), 'A repeated publish is a no-op.');

        $cancelled = $this->events()->cancel($this->freshEvent($event), $this->preparer);
        $twice = $this->events()->cancel($this->freshEvent($event), $this->preparer);
        $this->assertSame('cancelled', $cancelled->status);
        $this->assertNotNull($cancelled->published_at, 'Cancellation keeps the publication record.');
        $this->assertTrue($cancelled->cancelled_at->equalTo($twice->cancelled_at));
        $this->assertSame(1, $this->inSchool(fn () => DB::table('school_audit_events')->where('event_type', 'communication_event.cancelled')->count()));
        $this->assertSame(1, $this->inSchool(fn () => DB::table('school_audit_events')->where('event_type', 'communication_event.published')->count()));

        // Nothing is ever sent.
        foreach (['communication_messages', 'communication_recipients', 'communication_deliveries', 'communication_announcements', 'email_messages'] as $table) {
            $this->assertSame(0, $this->inSchool(fn () => DB::table($table)->count()), $table);
        }
    }

    #[Test]
    public function the_preparer_never_decides_even_when_someone_else_submitted_and_rejection_returns_to_editing(): void
    {
        $manager = $this->createUserWithCapabilities($this->school, ['communications.view', 'communications.announce', 'communications.manage']);
        $event = $this->draftEvent($this->school, $this->preparer);
        $request = $this->events()->submit($event, $manager);
        $approvals = app(CommunicationApprovalService::class);

        $this->assertThrows(fn () => $approvals->approve($request, $this->preparer), SelfApprovalNotAllowedException::class);
        $this->assertThrows(fn () => $approvals->approve($request, $manager), SelfApprovalNotAllowedException::class);

        $approvals->reject($request, $this->approver, 'Wrong date');
        $this->assertSame('rejected', $this->freshEvent($event)->status);
        $this->assertThrows(fn () => $this->events()->publish($this->freshEvent($event), $this->preparer), ApprovalRequiredException::class);
        $edited = $this->events()->update($this->freshEvent($event), $this->preparer, $this->terms($this->school, ['starts_on' => '2026-11-21', 'ends_on' => '2026-11-21']));
        $this->assertSame('draft', $edited->status);

        $request = $this->events()->submit($edited, $this->preparer);
        $this->events()->withdraw($this->freshEvent($event), $this->preparer);
        $this->assertSame('draft', $this->freshEvent($event)->status);
        $this->assertSame('cancelled', $this->inSchool(fn () => CommunicationApprovalRequest::query()->find($request->id)->status));
    }

    #[Test]
    public function a_material_edit_after_approval_invalidates_it_and_publish_rechecks_the_fingerprint(): void
    {
        foreach ([['title' => 'Renamed'], ['body' => 'Different text'], ['location' => 'Hall B'], ['starts_on' => '2026-11-19'], ['date_mode' => 'timed', 'starts_at' => '2026-11-20T09:00', 'ends_at' => '2026-11-20T11:00'], ['audience_type' => 'staff']] as $change) {
            $event = $this->draftEvent($this->school, $this->preparer);
            $this->approveEvent($event, $this->preparer, $this->approver);
            $edited = $this->events()->update($this->freshEvent($event), $this->preparer, $this->terms($this->school, $change));

            $this->assertSame('draft', $edited->status, json_encode($change));
            $this->assertSame('invalidated', $this->inSchool(fn () => CommunicationApprovalRequest::query()->where('event_id', $event->id)->value('status')));
            $this->assertThrows(fn () => $this->events()->publish($this->freshEvent($event), $this->preparer), ApprovalRequiredException::class);
        }

        // Defence in depth: terms changed under an approval WITHOUT the service
        // (a raw write) still never publish.
        $event = $this->draftEvent($this->school, $this->preparer);
        $this->approveEvent($event, $this->preparer, $this->approver);
        $this->inSchool(fn () => DB::table('communication_events')->where('id', $event->id)->update(['title' => 'Changed behind the approval']));
        $this->assertThrows(fn () => $this->events()->publish($this->freshEvent($event), $this->preparer), ApprovalRequiredException::class);
    }

    #[Test]
    public function the_database_keeps_published_terms_immutable_and_cancellation_final(): void
    {
        $event = $this->publishedEvent($this->school, $this->preparer, $this->approver);
        $raw = fn (array $changes) => fn () => $this->inSchool(fn () => DB::transaction(fn () => DB::table('communication_events')->where('id', $event->id)->update($changes)));

        foreach ([['title' => 'Edited'], ['starts_on' => '2026-11-22', 'ends_on' => '2026-11-22'], ['audience_type' => 'staff'], ['status' => 'draft', 'published_at' => null, 'published_by_user_id' => null], ['published_at' => now()->addDay()]] as $change) {
            $this->assertThrows($raw($change), QueryException::class);
        }
        $this->assertThrows($raw(['status' => 'cancelled']), QueryException::class, 'cev_publication_check'); // cancellation needs its evidence

        $this->events()->cancel($event, $this->preparer);
        $this->assertThrows($raw(['status' => 'published', 'cancelled_at' => null, 'cancelled_by_user_id' => null]), QueryException::class, 'communication_events_published_guard');
        $this->assertSame('cancelled', $this->freshEvent($event)->status);
    }

    #[Test]
    public function an_ended_event_is_never_created_submitted_or_published_and_unauthorized_transitions_refuse(): void
    {
        $this->assertThrows(fn () => $this->draftEvent($this->school, $this->preparer, ['starts_on' => '2026-09-30', 'ends_on' => '2026-09-30']), InvalidCommunicationEventException::class);

        $event = $this->draftEvent($this->school, $this->preparer, ['starts_on' => '2026-10-02', 'ends_on' => '2026-10-02']);
        $this->approveEvent($event, $this->preparer, $this->approver);
        $this->travelTo(CarbonImmutable::parse('2026-10-03T06:00:00Z'));
        $this->assertThrows(fn () => $this->events()->publish($this->freshEvent($event), $this->preparer), InvalidCommunicationEventException::class);
        $this->assertSame('approved', $this->freshEvent($event)->status);

        $this->assertThrows(fn () => $this->events()->withdraw($this->freshEvent($event), $this->preparer), InvalidAnnouncementTransitionException::class);
        $cancelled = $this->events()->cancel($this->freshEvent($event), $this->preparer);
        $this->assertNull($cancelled->published_at);
        $this->assertThrows(fn () => $this->events()->submit($this->freshEvent($event), $this->preparer), InvalidAnnouncementTransitionException::class);
    }

    #[Test]
    public function audit_records_transitions_with_dates_and_audience_never_the_description(): void
    {
        $event = $this->publishedEvent($this->school, $this->preparer, $this->approver, ['body' => 'SECRET-BODY-TEXT']);
        $this->events()->cancel($event, $this->preparer);

        $rows = $this->inSchool(fn () => DB::table('school_audit_events')->where('subject_id', $event->id)->orderBy('occurred_at')->get(['event_type', 'metadata']));
        $this->assertEqualsCanonicalizing([
            'communication_event.created', 'communication_event.approval_requested', 'communication_event.approved',
            'communication_event.published', 'communication_event.cancelled',
        ], $rows->pluck('event_type')->all());
        $published = json_decode($rows->firstWhere('event_type', 'communication_event.published')->metadata, true);
        $this->assertEquals(['audienceType' => 'school_wide', 'dateMode' => 'all_day', 'startsOn' => '2026-11-20', 'endsOn' => '2026-11-20', 'startsAt' => null, 'endsAt' => null], $published);
        $this->assertStringNotContainsString('SECRET-BODY-TEXT', $rows->pluck('metadata')->implode(' '));
    }

    #[Test]
    public function the_database_refuses_a_shortcut_to_publication_and_a_hard_delete(): void
    {
        $draft = $this->draftEvent($this->school, $this->preparer);
        $raw = fn (array $changes) => fn () => $this->inSchool(fn () => DB::transaction(fn () => DB::table('communication_events')->where('id', $draft->id)->update($changes)));

        $this->assertThrows($raw(['status' => 'published', 'published_at' => now(), 'published_by_user_id' => $this->preparer->id]), QueryException::class, 'communication_events_published_guard');
        $this->assertThrows($raw(['status' => 'approved']), QueryException::class, 'communication_events_published_guard');
        $this->assertThrows($raw(['status' => 'cancelled', 'cancelled_at' => now(), 'cancelled_by_user_id' => $this->preparer->id, 'published_at' => now(), 'published_by_user_id' => $this->preparer->id]), QueryException::class);
        $this->assertThrows(fn () => $this->inSchool(fn () => DB::transaction(fn () => DB::table('communication_events')->insert([
            'id' => (string) Uuid::v7(), 'school_id' => $this->school->id, 'created_by_user_id' => $this->preparer->id, 'title' => 'Born published', 'body' => 'B',
            'date_mode' => 'all_day', 'starts_on' => '2026-11-20', 'ends_on' => '2026-11-20', 'audience_type' => 'school_wide', 'status' => 'published',
            'published_at' => now(), 'published_by_user_id' => $this->preparer->id, 'created_at' => now(), 'updated_at' => now(),
        ]))), QueryException::class, 'communication_events_published_guard');

        $published = $this->publishedEvent($this->school, $this->preparer, $this->approver);
        $this->assertThrows(fn () => $this->inSchool(fn () => DB::transaction(fn () => DB::table('communication_events')->where('id', $published->id)->delete())), QueryException::class);
        $this->assertNotNull($this->freshEvent($published));
        $this->assertSame(1, $this->inSchool(fn () => CommunicationApprovalRequest::query()->where('event_id', $published->id)->count()), 'Approval history stays.');
    }

    #[Test]
    public function anyone_who_edited_the_terms_cannot_approve_them(): void
    {
        $manager = $this->createUserWithCapabilities($this->school, ['communications.view', 'communications.announce', 'communications.manage', 'communications.approve']);
        $event = $this->draftEvent($this->school, $this->preparer);
        $this->events()->update($event, $manager, $this->terms($this->school, ['audience_type' => 'staff', 'title' => 'Edited by the manager']));
        $request = $this->events()->submit($this->freshEvent($event), $this->preparer);

        $this->assertThrows(fn () => app(CommunicationApprovalService::class)->approve($request, $manager), SelfApprovalNotAllowedException::class);
        app(CommunicationApprovalService::class)->approve($request, $this->approver);
        $this->assertSame('approved', $this->freshEvent($event)->status);
    }
}

<?php

namespace App\Domain\Communications\Application\Events;

use App\Domain\AcademicStructure\Application\CurrentAcademicYearResolver;
use App\Domain\AcademicStructure\Infrastructure\GradeLevel;
use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\Communications\Application\Exceptions\ApprovalAlreadyPendingException;
use App\Domain\Communications\Application\Exceptions\ApprovalRequiredException;
use App\Domain\Communications\Application\Exceptions\InvalidAnnouncementTransitionException;
use App\Domain\Communications\Application\Exceptions\InvalidCommunicationEventException;
use App\Domain\Communications\Infrastructure\CommunicationApprovalRequest;
use App\Domain\Communications\Infrastructure\CommunicationEvent;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Tenancy\SchoolTimezone;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * ORG.9D-3 (ADR 0076 §75, PV1-12) -- the ONE write path for School
 * calendar Events, on the existing Communications approval workflow (the
 * same `communication_approval_requests` table, queue and
 * CommunicationApprovalService::decide()):
 *
 *   draft --submit--> pending_approval --approve--> approved --publish--> published
 *                         |  withdraw -> draft          (an edit -> draft)      | cancel
 *                         '--reject--> rejected --edit--> draft            cancelled (final)
 *
 * - Approval is ALWAYS required (no auto-approval), and never decided by the
 *   requester or the preparer (CommunicationApprovalService::decide()).
 * - The approval binds a fingerprint of every material term (title, body,
 *   location, date mode, start, end, audience and its Grade/Section). An
 *   edit to an approved Event invalidates that approval and returns it to
 *   draft; publish re-checks the fingerprint under the row lock, so an edit
 *   racing publication can never publish unapproved terms.
 * - Every transition locks the Event row FOR UPDATE first; publish and
 *   cancel are idempotent (a repeat is a no-op, never a second transition).
 * - Publishing changes VISIBILITY only: no announcement, email, push, SMS
 *   or reminder is created. Visibility is resolved when a viewer reads the
 *   calendar (CommunicationEventCalendar), never snapshotted per person.
 * - A published Event is never edited or deleted (database-enforced); to
 *   change one, cancel it and publish a corrected Event. Cancellation keeps
 *   its publication record.
 * - An Event that has already ended cannot be created, edited or published.
 * - Audit: ids, audience type, date mode and range, transitions -- never the
 *   body or any audience member list.
 *
 * Who may act (creator or `communications.manage`; `communications.approve`
 * to decide) is the controller's check, as for announcements.
 */
class CommunicationEventService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditRecorder $audit,
        private readonly CurrentAcademicYearResolver $years,
    ) {}

    public function create(School $school, User $actor, CommunicationEventTerms $terms): CommunicationEvent
    {
        return $this->context->withSchool($school, fn () => DB::transaction(function () use ($school, $actor, $terms) {
            $this->assertTerms($school, $terms);
            $event = CommunicationEvent::query()->create([
                ...$terms->attributes(),
                'school_id' => $school->id,
                'created_by_user_id' => $actor->id,
                'status' => 'draft',
            ]);
            $this->audit->school($school, 'communication_event.created', actor: $actor, subject: $event, metadata: $this->facts($event));

            return $event->fresh();
        }));
    }

    public function update(CommunicationEvent $event, User $actor, CommunicationEventTerms $terms): CommunicationEvent
    {
        return $this->transition($event, function (CommunicationEvent $locked, School $school) use ($actor, $terms) {
            if (! $locked->isEditable()) {
                throw new InvalidAnnouncementTransitionException($locked->status, 'edit');
            }
            $this->assertTerms($school, $terms);
            $wasApproved = $locked->status === 'approved';
            $locked->fill([...$terms->attributes(), 'status' => 'draft'])->save();
            $this->audit->school($school, 'communication_event.updated', actor: $actor, subject: $locked, metadata: $this->facts($locked));

            if ($wasApproved) {
                $request = $this->approvedRequest($locked);
                if ($request !== null) {
                    CommunicationApprovalRequest::query()->whereKey($request->id)->where('status', 'approved')->update(['status' => 'invalidated']);
                    $this->audit->school($school, 'communication_event.approval_invalidated', actor: $actor, subject: $locked, metadata: [
                        'requestId' => $request->id,
                        'previousFingerprint' => $request->fingerprint,
                    ]);
                }
            }

            return $locked;
        });
    }

    public function submit(CommunicationEvent $event, User $actor): CommunicationApprovalRequest
    {
        return $this->transition($event, function (CommunicationEvent $locked, School $school) use ($actor) {
            if ($locked->status !== 'draft') {
                throw new InvalidAnnouncementTransitionException($locked->status, 'submit for approval');
            }
            $this->assertNotEnded($school, $locked);
            $locked->forceFill(['status' => 'pending_approval'])->save();
            $snapshot = $this->snapshot($locked);
            $hash = $this->hash($snapshot);

            try {
                $request = CommunicationApprovalRequest::query()->create([
                    'school_id' => $school->id,
                    'event_id' => $locked->id,
                    'requested_by_user_id' => $actor->id,
                    'requested_at' => now(),
                    'fingerprint' => $hash,
                    'snapshot' => $snapshot,
                    'status' => 'pending',
                ]);
            } catch (UniqueConstraintViolationException) {
                throw new ApprovalAlreadyPendingException;
            }
            $this->audit->school($school, 'communication_event.approval_requested', actor: $actor, subject: $locked, metadata: [
                'requestId' => $request->id,
                'fingerprint' => $hash,
            ]);

            return $request;
        });
    }

    public function withdraw(CommunicationEvent $event, User $actor): CommunicationEvent
    {
        return $this->transition($event, function (CommunicationEvent $locked, School $school) use ($actor) {
            if ($locked->status !== 'pending_approval') {
                throw new InvalidAnnouncementTransitionException($locked->status, 'withdraw approval for');
            }
            $pending = CommunicationApprovalRequest::query()->where('event_id', $locked->id)->where('status', 'pending')->first();
            CommunicationApprovalRequest::query()->where('event_id', $locked->id)->where('status', 'pending')
                ->update(['status' => 'cancelled', 'decided_by_user_id' => $actor->id, 'decided_at' => now()]);
            $locked->forceFill(['status' => 'draft'])->save();
            $this->audit->school($school, 'communication_event.approval_withdrawn', actor: $actor, subject: $locked, metadata: ['requestId' => $pending?->id]);

            return $locked;
        });
    }

    public function publish(CommunicationEvent $event, User $actor): CommunicationEvent
    {
        return $this->transition($event, function (CommunicationEvent $locked, School $school) use ($actor) {
            if ($locked->status === 'published') {
                return $locked; // idempotent replay: nothing more happens
            }
            if ($locked->status !== 'approved' || ! $this->approvalMatches($locked)) {
                throw new ApprovalRequiredException;
            }
            $this->assertNotEnded($school, $locked);
            $this->assertAudience($school, $locked->audience_type, $locked->grade_level_id, $locked->section_id);

            $locked->forceFill(['status' => 'published', 'published_at' => now(), 'published_by_user_id' => $actor->id])->save();
            $this->audit->school($school, 'communication_event.published', actor: $actor, subject: $locked, metadata: $this->facts($locked));

            return $locked;
        });
    }

    public function cancel(CommunicationEvent $event, User $actor): CommunicationEvent
    {
        return $this->transition($event, function (CommunicationEvent $locked, School $school) use ($actor) {
            if ($locked->status === 'cancelled') {
                return $locked; // idempotent replay
            }
            $wasPublished = $locked->wasPublished();
            CommunicationApprovalRequest::query()->where('event_id', $locked->id)->where('status', 'pending')
                ->update(['status' => 'cancelled', 'decided_by_user_id' => $actor->id, 'decided_at' => now()]);
            $locked->forceFill(['status' => 'cancelled', 'cancelled_at' => now(), 'cancelled_by_user_id' => $actor->id])->save();
            $this->audit->school($school, 'communication_event.cancelled', actor: $actor, subject: $locked, metadata: [
                'wasPublished' => $wasPublished,
            ]);

            return $locked;
        });
    }

    /**
     * The approval fingerprint's content: every material term, canonical.
     *
     * @return array<string, mixed>
     */
    public function snapshot(CommunicationEvent $event): array
    {
        return [
            'kind' => 'event',
            'title' => $event->title,
            'body' => $event->body,
            'location' => $event->location,
            'dateMode' => $event->date_mode,
            'startsOn' => $event->starts_on?->format('Y-m-d'),
            'endsOn' => $event->ends_on?->format('Y-m-d'),
            'startsAt' => $event->starts_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            'endsAt' => $event->ends_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            'audienceType' => $event->audience_type,
            'gradeLevelId' => $event->grade_level_id,
            'sectionId' => $event->section_id,
        ];
    }

    /** @param  array<string, mixed>  $snapshot */
    public function hash(array $snapshot): string
    {
        ksort($snapshot);

        return hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR));
    }

    /** True only when an approved request exists AND its fingerprint matches the Event's current terms. */
    public function approvalMatches(CommunicationEvent $event): bool
    {
        $request = $this->approvedRequest($event);

        return $request !== null && hash_equals($request->fingerprint, $this->hash($this->snapshot($event)));
    }

    /**
     * @template T
     *
     * @param  callable(CommunicationEvent, School): T  $step
     * @return T
     */
    private function transition(CommunicationEvent $event, callable $step): mixed
    {
        $school = $event->school;

        return $this->context->withSchool($school, fn () => DB::transaction(function () use ($event, $step, $school) {
            $locked = CommunicationEvent::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();

            return $step($locked, $school);
        }));
    }

    private function approvedRequest(CommunicationEvent $event): ?CommunicationApprovalRequest
    {
        return CommunicationApprovalRequest::query()->where('event_id', $event->id)->where('status', 'approved')
            ->orderByDesc('decided_at')->first();
    }

    private function assertTerms(School $school, CommunicationEventTerms $terms): void
    {
        $this->assertAudience($school, $terms->audienceType, $terms->gradeLevelId, $terms->sectionId);
        if ($this->hasEnded($school, $terms->dateMode, $terms->endsOn, $terms->endsAt)) {
            throw new InvalidCommunicationEventException('This event has already ended; the calendar is for current and upcoming events.', 'ends_on');
        }
    }

    private function assertNotEnded(School $school, CommunicationEvent $event): void
    {
        if ($this->hasEnded($school, $event->date_mode, $event->ends_on?->format('Y-m-d'), $event->ends_at)) {
            throw new InvalidCommunicationEventException('This event has already ended, so it can no longer be submitted or published.', 'ends_on');
        }
    }

    private function hasEnded(School $school, string $mode, ?string $endsOn, ?CarbonImmutable $endsAt): bool
    {
        return $mode === CommunicationEvent::ALL_DAY
            ? $endsOn < SchoolTimezone::today($school)
            : $endsAt !== null && $endsAt->lessThan(CarbonImmutable::now());
    }

    /** A Grade or Section of THIS School, active; a Section of the current academic year. */
    private function assertAudience(School $school, string $type, ?string $gradeLevelId, ?string $sectionId): void
    {
        if ($type === 'grade' && ! GradeLevel::query()->whereKey($gradeLevelId)->where('school_id', $school->id)->where('status', 'active')->exists()) {
            throw new InvalidCommunicationEventException('Choose an active Grade of this School.', 'grade_level_id');
        }
        if ($type === 'section') {
            $year = $this->years->tryResolve($school);
            if ($year === null || ! Section::query()->whereKey($sectionId)->where('school_id', $school->id)->where('status', 'active')->where('academic_year_id', $year->id)->exists()) {
                throw new InvalidCommunicationEventException('Choose an active Section of the current academic year.', 'section_id');
            }
        }
    }

    /** @return array<string, mixed> ids, audience type, date mode and range only -- never the body */
    private function facts(CommunicationEvent $event): array
    {
        return [
            'audienceType' => $event->audience_type,
            'dateMode' => $event->date_mode,
            'startsOn' => $event->starts_on?->format('Y-m-d'),
            'endsOn' => $event->ends_on?->format('Y-m-d'),
            'startsAt' => $event->starts_at?->utc()->toIso8601String(),
            'endsAt' => $event->ends_at?->utc()->toIso8601String(),
        ];
    }
}

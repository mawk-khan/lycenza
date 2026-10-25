<?php

namespace Tests\Feature\Communications\Concerns;

use App\Domain\Communications\Application\Approval\CommunicationApprovalService;
use App\Domain\Communications\Application\Events\CommunicationEventService;
use App\Domain\Communications\Application\Events\CommunicationEventTerms;
use App\Domain\Communications\Infrastructure\CommunicationApprovalRequest;
use App\Domain\Communications\Infrastructure\CommunicationEvent;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;

/**
 * ORG.9D-3 (ADR 0076 §75): School calendar Events built through the real
 * service and the real approval decision.
 */
trait CreatesCommunicationEventFixtures
{
    protected function events(): CommunicationEventService
    {
        return app(CommunicationEventService::class);
    }

    /** @param  array<string, mixed>  $overrides */
    protected function terms(School $school, array $overrides = []): CommunicationEventTerms
    {
        return CommunicationEventTerms::fromInput($school, [
            'title' => 'Sports day',
            'body' => 'The annual sports day on the main ground.',
            'location' => 'Main ground',
            'date_mode' => 'all_day',
            'starts_on' => '2026-11-20',
            'ends_on' => '2026-11-20',
            'audience_type' => 'school_wide',
            ...$overrides,
        ]);
    }

    /** @param  array<string, mixed>  $overrides */
    protected function draftEvent(School $school, User $preparer, array $overrides = []): CommunicationEvent
    {
        return $this->events()->create($school, $preparer, $this->terms($school, $overrides));
    }

    protected function approveEvent(CommunicationEvent $event, User $requester, User $approver): CommunicationApprovalRequest
    {
        $request = $this->events()->submit($event, $requester);

        return app(CommunicationApprovalService::class)->approve($request, $approver);
    }

    /** @param  array<string, mixed>  $overrides */
    protected function publishedEvent(School $school, User $preparer, User $approver, array $overrides = []): CommunicationEvent
    {
        $event = $this->draftEvent($school, $preparer, $overrides);
        $this->approveEvent($event, $preparer, $approver);

        return $this->events()->publish($this->freshEvent($event), $preparer);
    }

    protected function freshEvent(CommunicationEvent $event): CommunicationEvent
    {
        return app(TenantContext::class)->withSchool($event->school, fn () => $event->fresh());
    }
}

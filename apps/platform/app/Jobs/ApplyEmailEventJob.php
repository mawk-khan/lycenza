<?php

namespace App\Jobs;

use App\Support\Email\Events\EmailEventApplier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Phase 0O.9A (ADR 0055 section 11.2): applies ONE stored, normalized
 * provider event. The payload is the event id only. There is no School at
 * dispatch: EmailEventApplier finds it from the stored message
 * (email_provider_references) and sets that School's TenantContext itself
 * -- never from the provider's payload.
 *
 * Records provider evidence and suppression only; it never sends, so it
 * applies for a suspended School too (SchoolLifecycleArchitectureGuardTest).
 */
class ApplyEmailEventJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(public readonly string $eventId) {}

    public function handle(EmailEventApplier $applier): void
    {
        $applier->apply($this->eventId);
    }
}

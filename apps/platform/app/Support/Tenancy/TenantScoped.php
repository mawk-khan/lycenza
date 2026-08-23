<?php

namespace App\Support\Tenancy;

/**
 * Every tenant-scoped queued job uses this trait (root CLAUDE.md rule
 * 7). Captures the dispatching request/job's tenant context explicitly
 * into the job's own payload -- a queue worker has no ambient "current
 * tenant" of its own, so the job must carry everything it needs.
 *
 * Usage:
 *   class SomeJob implements ShouldQueue
 *   {
 *       use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, TenantScoped;
 *
 *       public function __construct(...)
 *       {
 *           $this->captureTenantContext();
 *       }
 *   }
 */
trait TenantScoped
{
    public ?string $contextSchoolId = null;

    public ?string $contextCampusId = null;

    public ?string $contextActorId = null;

    public ?string $contextRequestId = null;

    public ?string $contextCorrelationId = null;

    /**
     * Call from the job's constructor, while the dispatching
     * request/job still has TenantContext populated.
     */
    public function captureTenantContext(): static
    {
        $context = app(TenantContext::class);

        $this->contextSchoolId = $context->schoolId();
        $this->contextCampusId = $context->campus()?->id;
        $this->contextActorId = $context->actor()?->id;
        $this->contextRequestId = $context->requestId();
        $this->contextCorrelationId = $context->correlationId();

        return $this;
    }

    public function middleware(): array
    {
        return [new SetTenantContextForJob];
    }
}

<?php

namespace App\Domain\HR\Events;

use App\Support\Events\OutboxedEventDefaults;
use App\Support\Events\ShouldBeOutboxed;

/**
 * Phase 8A.1's one domain event -- required for the transactional
 * outbox / audit-adjacent trail every other Phase 0D module already
 * emits on creation (docs/modules/HR.md "Domain events"; see
 * App\Domain\AcademicStructure\Events\GradeLevelCreated for the exact
 * pattern followed here). Not registered in
 * App\Support\Webhooks\WebhookEventRegistry -- internal-only by
 * default, per docs/modules/HR.md's explicit deferral (no reviewed
 * external-integration need identified yet).
 *
 * Payload carries only references, never the full record (rule 44's
 * minimization principle) -- no `full_name` here even though it is
 * only Internal-tier data, since nothing downstream needs it yet and
 * the minimization principle is to include the least, not "whatever
 * happens to be low-sensitivity."
 */
class EmployeeCreated implements ShouldBeOutboxed
{
    use OutboxedEventDefaults;

    public function __construct(
        public readonly string $schoolId,
        public readonly string $employeeId,
        public readonly string $employeeNumber,
    ) {}

    public function eventType(): string
    {
        return 'employee.created.v1';
    }

    public function eventVersion(): int
    {
        return 1;
    }

    public function schoolId(): ?string
    {
        return $this->schoolId;
    }

    public function payload(): array
    {
        return ['employeeId' => $this->employeeId, 'employeeNumber' => $this->employeeNumber];
    }
}

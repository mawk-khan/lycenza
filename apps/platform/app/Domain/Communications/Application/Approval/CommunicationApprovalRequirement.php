<?php

namespace App\Domain\Communications\Application\Approval;

/**
 * Phase 5A.12 §50 -- the answer to "does this announcement require
 * approval, and why?" `$reasons` is a stable, machine-readable code
 * set (never free text) so audit metadata and UI copy stay in sync:
 * `school_wide`, `required_communication`, `non_privileged_sender`.
 * More than one reason may apply simultaneously.
 */
final class CommunicationApprovalRequirement
{
    /**
     * @param  array<int, string>  $reasons
     */
    public function __construct(
        public readonly bool $required,
        public readonly array $reasons,
    ) {}
}

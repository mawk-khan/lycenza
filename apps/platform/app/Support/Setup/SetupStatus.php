<?php

namespace App\Support\Setup;

/**
 * ORG.12A (ADR 0080 §7): the bounded configuration-readiness vocabulary.
 * Configuration readiness is never production readiness -- there is
 * deliberately no "production ready" status.
 *
 * - NOT_APPLICABLE: the setup area's optional module is not entitled for
 *   this School. Decided by OrganizationSetupReadService only, never a
 *   provider.
 * - NOT_CONFIGURED: none of the area's checks pass.
 * - PARTIALLY_CONFIGURED: some, not all, of the area's checks pass.
 * - CONFIGURED: every check passes (or the area needs no School setup).
 * - BLOCKED: the area cannot be completed yet -- a dependency is not
 *   satisfied, or an environment/legal gate keeps it closed.
 * - ATTENTION_REQUIRED: the area was configured but a current condition
 *   needs review (e.g. the active year has ended, a dependency regressed).
 */
enum SetupStatus: string
{
    case NOT_APPLICABLE = 'not_applicable';
    case NOT_CONFIGURED = 'not_configured';
    case PARTIALLY_CONFIGURED = 'partially_configured';
    case CONFIGURED = 'configured';
    case BLOCKED = 'blocked';
    case ATTENTION_REQUIRED = 'attention_required';

    /** Whether an area in this status lets the areas that depend on it proceed. */
    public function satisfiesDependents(): bool
    {
        return in_array($this, [self::CONFIGURED, self::ATTENTION_REQUIRED, self::NOT_APPLICABLE], true);
    }
}

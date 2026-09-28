<?php

namespace App\Domain\Platform\Application\Elevation;

use App\Models\SchoolElevation;

/**
 * Why an elevation stopped being active (ADR 0044 section 10), and the
 * terminal status each reason leads to. Mirrored by the
 * school_elevations_end_check constraint.
 */
enum ElevationEndReason: string
{
    case Exited = 'exited';
    case Logout = 'logout';
    case Expired = 'expired';
    case ActorDisabled = 'actor_disabled';
    case CapabilityRevoked = 'capability_revoked';
    case SchoolIneligible = 'school_ineligible';
    case MembershipConflict = 'membership_conflict';
    case MfaFactorRevoked = 'mfa_factor_revoked';
    // Phase 0N.5 (ADR 0045 section 10): Group-derived authority ended.
    case SchoolLeftGroup = 'school_left_group';
    case GroupAuthorityRevoked = 'group_authority_revoked';
    case GroupInactive = 'group_inactive';
    // Phase 0N.9 (ADR 0047 section 8): the School was suspended -- ended
    // eagerly in the suspension transaction, or on the next request.
    case SchoolSuspended = 'school_suspended';
    // Phase 0O.10A (ADR 0056 section 10.1): the actor's password was reset
    // (self-service recovery or operator reset).
    case CredentialReset = 'credential_reset';

    public function status(): string
    {
        return match ($this) {
            self::Exited, self::Logout => SchoolElevation::STATUS_ENDED,
            self::Expired => SchoolElevation::STATUS_EXPIRED,
            default => SchoolElevation::STATUS_TERMINATED,
        };
    }

    public function auditEvent(): string
    {
        return match ($this->status()) {
            SchoolElevation::STATUS_ENDED => ElevationAudit::ENDED,
            SchoolElevation::STATUS_EXPIRED => ElevationAudit::EXPIRED,
            default => ElevationAudit::TERMINATED,
        };
    }
}

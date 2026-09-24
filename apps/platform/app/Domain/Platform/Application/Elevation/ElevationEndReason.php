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

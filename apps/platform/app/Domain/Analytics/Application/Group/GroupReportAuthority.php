<?php

namespace App\Domain\Analytics\Application\Group;

use App\Models\User;

/**
 * The Group-report execution authority the Group layer hands to
 * GroupSafeReportGate (ADR 0048 section 7): who, under which ONE Group and
 * which grant, for which report. Constructed only by the Group reporting
 * service after its own authorization; the gate still re-checks the Group
 * capability itself. Carries identifiers only.
 */
final readonly class GroupReportAuthority
{
    public function __construct(
        public User $actor,
        public string $groupId,
        public string $grantId,
        public string $reportKey,
    ) {}
}

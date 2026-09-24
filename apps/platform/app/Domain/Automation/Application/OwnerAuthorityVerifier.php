<?php

namespace App\Domain\Automation\Application;

use App\Domain\Automation\Application\Catalog\AutomationRuleType;
use App\Models\School;
use App\Models\User;
use App\Support\Authorization\CapabilityResolver;

/**
 * ADR 0043 §5: an execution acts as its rule's accountable owner, and only
 * while that person still holds, right now, every capability the rule type
 * declares. Checked through Identity & Access's own CapabilityResolver
 * (which already refuses a disabled account and requires an active School
 * membership), with its cache dropped first so a revocation takes effect on
 * the very next execution. Returns null when authorized, otherwise a
 * stable reason code. Never falls back to another user or to system
 * authority.
 */
class OwnerAuthorityVerifier
{
    public const OWNER_MISSING = 'owner_missing';

    public const OWNER_DISABLED = 'owner_disabled';

    public const OWNER_NO_SCHOOL_AUTHORITY = 'owner_no_school_authority';

    public const OWNER_CAPABILITY_MISSING = 'owner_capability_missing';

    public function __construct(private readonly CapabilityResolver $capabilities) {}

    public function failureFor(?User $owner, AutomationRuleType $ruleType, School $school): ?string
    {
        if ($owner === null) {
            return self::OWNER_MISSING;
        }

        if ($owner->isDisabled()) {
            return self::OWNER_DISABLED;
        }

        $this->capabilities->forgetCache($owner, $school);
        $held = $this->capabilities->schoolCapabilities($owner, $school);

        if ($held === []) {
            return self::OWNER_NO_SCHOOL_AUTHORITY;
        }

        foreach ($ruleType->requiredCapabilities() as $capability) {
            if (! in_array($capability, $held, true)) {
                return self::OWNER_CAPABILITY_MISSING;
            }
        }

        return null;
    }
}

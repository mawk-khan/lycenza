<?php

namespace App\Support\Retention;

use Carbon\CarbonInterface;

/**
 * E21-D6 (docs/security/E21-RETENTION-DETERMINATION.md): authority facts
 * EMBEDDED in a retained resource, today the LMS owner Employee and Section
 * audience of a teacher-owned Learning Content or Assignment row (TCH.5B).
 *
 * They are never removed on their own: the owner and audience are
 * immutable, and nulling or deleting them would turn a teacher-owned row
 * into Offering-wide admin material. They leave only with their parent
 * resource. That parent's purge (E21.3D, LmsResourceRetention) must
 * hold BOTH its own period AND this minimum (longest period wins). Unset
 * means never.
 */
final class EmbeddedAuthorityRetention
{
    /**
     * @param  CarbonInterface  $authorityEndedAt  when the recorded authority last applied
     */
    public static function mayRemoveWithParent(CarbonInterface $authorityEndedAt): bool
    {
        $years = RetentionPeriod::years(config('retention.authority_history_years'));

        return $years !== null && $authorityEndedAt->lt(RetentionPeriod::yearsBeforeNow($years));
    }
}

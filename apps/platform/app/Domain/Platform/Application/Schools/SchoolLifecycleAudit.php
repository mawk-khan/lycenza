<?php

namespace App\Domain\Platform\Application\Schools;

/**
 * Phase 0N.9 (ADR 0047 section 13): the platform-ledger events of School
 * lifecycle and bootstrap administration. Identifiers and codes only in
 * metadata -- never names, slugs, emails or free text -- and never
 * duplicated into the School's own ledger.
 */
final class SchoolLifecycleAudit
{
    public const CREATED = 'platform.school.created';

    public const BOOTSTRAP_ADMIN_ASSIGNED = 'platform.school.bootstrap_admin_assigned';

    public const BOOTSTRAP_ADMIN_REPLACED = 'platform.school.bootstrap_admin_replaced';

    public const ACTIVATED = 'platform.school.activated';

    public const SUSPENDED = 'platform.school.suspended';

    public const RESUMED = 'platform.school.resumed';

    /** E21.2F (E21-D11). */
    public const CLOSED = 'platform.school.closed';

    public const REOPENED = 'platform.school.reopened';

    public const DENIED = 'platform.school.lifecycle_denied';
}

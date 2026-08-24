<?php

namespace App\Domain\HR\Application;

/**
 * Phase 8A.11 -- explicit, allow-listed input for
 * `EmployeeActivityTimelineService::get()`, mirroring
 * `EmployeeDirectoryQuery`'s exact validate-in-constructor shape.
 *
 * `category` is validated against a closed allow-list
 * (`EmployeeActivityTimelineService::CATEGORIES`); an unrecognized
 * value is treated as "no category filter" (never an exception, never
 * passed through to the query) -- same fallback-to-safe-default
 * convention `EmployeeDirectoryQuery::$sort` already established.
 * Critically, a *recognized* category the actor is not permitted to
 * see is NOT rejected here -- `EmployeeActivityTimelineService`
 * intersects the requested category against the actor's own visible-
 * category set, so requesting a forbidden category yields the exact
 * same empty result as requesting one that legitimately has zero
 * events for this Employee. Rejecting it here instead would itself be
 * the existence-oracle side channel checkpoint section 30 forbids.
 *
 * `occurredFrom`/`occurredTo` filter `SchoolAuditEvent.occurred_at`
 * (when the audited action happened) -- never a domain effective date
 * (`Employment.starts_on`, `Certification.issued_on`, ...). No
 * free-text search exists or is planned for this query object (root
 * CLAUDE.md rule 2 / this checkpoint's own "no raw-metadata search"
 * requirement) -- category and date range are the full filter surface.
 */
final class EmployeeActivityTimelineQuery
{
    public const int DEFAULT_PER_PAGE = 25;

    public readonly ?string $category;

    public readonly int $page;

    public readonly int $perPage;

    public function __construct(
        ?string $category = null,
        public readonly ?string $occurredFrom = null,
        public readonly ?string $occurredTo = null,
        int $page = 1,
        int $perPage = self::DEFAULT_PER_PAGE,
    ) {
        $this->category = in_array($category, EmployeeActivityTimelineService::CATEGORIES, true) ? $category : null;
        $this->page = max(1, $page);
        $this->perPage = max(1, min($perPage, EmployeeActivityTimelineService::MAX_PER_PAGE));
    }
}

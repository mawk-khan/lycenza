<?php

namespace App\Domain\Documents\Application;

/**
 * Phase 0E.4 -- explicit, allow-listed input for
 * `DocumentListingService::list()`/`listSensitive()`, mirroring
 * `App\Domain\HR\Application\EmployeeDirectoryQuery`'s/
 * `EmployeeActivityTimelineQuery`'s exact clamp-in-constructor shape
 * and the same `DEFAULT_PER_PAGE`/`MAX_PER_PAGE` values.
 *
 * Deliberately has no sort/filter fields beyond pagination -- no
 * classification filter (the operation itself, not caller input,
 * determines which tiers are queried; see
 * `DocumentListingService::list()`'s own docblock for why), no status
 * filter (archived Documents are never excluded, matching
 * `EmployeeProfileWorkspaceService`'s/`EmployeeSensitiveDocumentReadService`'s
 * own established precedent of never filtering by `status`), and no
 * free-text/filename search (this checkpoint's own explicit scope
 * boundary).
 */
final class DocumentListingQuery
{
    public const int DEFAULT_PER_PAGE = 25;

    public const int MAX_PER_PAGE = 100;

    public readonly int $page;

    public readonly int $perPage;

    public function __construct(
        int $page = 1,
        int $perPage = self::DEFAULT_PER_PAGE,
    ) {
        $this->page = max(1, $page);
        $this->perPage = max(1, min($perPage, self::MAX_PER_PAGE));
    }
}

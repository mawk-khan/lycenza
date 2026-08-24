<?php

namespace App\Domain\HR\Application;

/**
 * Phase 8A.8 -- explicit, allow-listed input for
 * `EmployeeDirectoryService::search()`. Never an arbitrary array that
 * becomes raw query column/value mappings -- every accepted field is
 * a fixed, typed property, and `sort`/`direction` are validated
 * against a closed allow-list in the constructor (invalid values fall
 * back to the deterministic default rather than being passed through
 * to `orderBy()`).
 *
 * `campusId`/`departmentId`/`positionId` are trusted, already-resolved
 * identifiers -- this class does not validate their format (e.g. UUID
 * shape) or existence. That is the responsibility of whatever future
 * controller (8A.9+) accepts caller input and resolves it to a real,
 * same-School record before constructing this object, matching every
 * other 8A.x Application-layer service's boundary (rule 19-style
 * trust boundary, extended to read-side filters). A filter value for
 * another School's record simply matches nothing -- see
 * `EmployeeDirectoryService`'s own docblock for why that is safe
 * without an extra existence check.
 *
 * `perPage` is clamped to `[1, EmployeeDirectoryService::MAX_PER_PAGE]`
 * here, not just at the service call site, so an out-of-range value
 * can never reach the query layer at all.
 */
final class EmployeeDirectoryQuery
{
    public const array ALLOWED_SORTS = ['full_name', 'employee_number'];

    public const string DEFAULT_SORT = 'full_name';

    public const string DEFAULT_DIRECTION = 'asc';

    public const int DEFAULT_PER_PAGE = 25;

    public readonly string $sort;

    public readonly string $direction;

    public readonly int $page;

    public readonly int $perPage;

    public function __construct(
        public readonly ?string $search = null,
        public readonly ?string $campusId = null,
        public readonly ?string $departmentId = null,
        public readonly ?string $positionId = null,
        public readonly bool $includeArchived = false,
        ?string $sort = null,
        ?string $direction = null,
        int $page = 1,
        int $perPage = self::DEFAULT_PER_PAGE,
    ) {
        $this->sort = in_array($sort, self::ALLOWED_SORTS, true) ? $sort : self::DEFAULT_SORT;
        $this->direction = in_array($direction, ['asc', 'desc'], true) ? $direction : self::DEFAULT_DIRECTION;
        $this->page = max(1, $page);
        $this->perPage = max(1, min($perPage, EmployeeDirectoryService::MAX_PER_PAGE));
    }
}

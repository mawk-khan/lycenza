<?php

namespace App\Domain\Platform\Application\Schools\Registry;

/**
 * ORG.3 (ADR 0076 §9.2): one validated School Registry list request. The
 * controller validates; this only carries the values.
 */
final class SchoolRegistryFilters
{
    public function __construct(
        public readonly ?string $search = null,
        public readonly ?string $status = null,
        public readonly ?string $groupId = null,
        public readonly ?string $domain = null,
        public readonly ?string $cursor = null,
        public readonly int $perPage = SchoolRegistryReadService::DEFAULT_PER_PAGE,
    ) {}
}

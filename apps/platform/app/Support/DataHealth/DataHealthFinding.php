<?php

namespace App\Support\DataHealth;

/**
 * One affected record. Display-safe only: a name or code, and a short
 * description built from non-sensitive fields (never a date of birth,
 * contact value or actor). Links are filtered by DataHealthService to
 * those the viewer may open.
 */
final readonly class DataHealthFinding
{
    /**
     * @param  list<DataHealthLink>  $links
     */
    public function __construct(
        public string $entityType,
        public string $entityId,
        public string $label,
        public string $detail,
        public array $links = [],
    ) {}
}

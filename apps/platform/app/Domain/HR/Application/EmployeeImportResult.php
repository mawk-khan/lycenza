<?php

namespace App\Domain\HR\Application;

/**
 * Phase 8A.12 -- the aggregate outcome of one `EmployeeImportService::import()`
 * call. `rows` preserves input order (checkpoint brief section 47:
 * "deterministic ordering: same as input row order").
 */
final class EmployeeImportResult
{
    /**
     * @param  array<int, EmployeeImportRowResult>  $rows
     */
    public function __construct(
        public readonly int $received,
        public readonly int $created,
        public readonly int $exactDuplicates,
        public readonly int $potentialDuplicates,
        public readonly int $failed,
        public readonly array $rows,
    ) {}

    /**
     * @return array{received: int, created: int, exact_duplicates: int, potential_duplicates: int, failed: int, rows: array<int, array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'received' => $this->received,
            'created' => $this->created,
            'exact_duplicates' => $this->exactDuplicates,
            'potential_duplicates' => $this->potentialDuplicates,
            'failed' => $this->failed,
            'rows' => array_map(fn (EmployeeImportRowResult $r) => $r->toArray(), $this->rows),
        ];
    }
}

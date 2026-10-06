<?php

namespace App\Domain\Examinations\Application\Marks;

/**
 * RES.2: one row of a per-paper batch write. `expectedVersion` is null for a
 * new mark and the version read for a change (optimistic concurrency, §6.3).
 * `value` is a decimal string (two places at most) or null.
 */
final readonly class StudentMarkEntry
{
    public function __construct(
        public string $studentId,
        public string $status,
        public ?string $value,
        public ?int $expectedVersion,
    ) {}
}

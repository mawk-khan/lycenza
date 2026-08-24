<?php

namespace App\Domain\HR\Application;

/**
 * Deterministic, stateless formatting of a raw allocated sequence value
 * into the human-facing employee number string. Deliberately separate
 * from App\Domain\HR\Application\EmployeeNumberAllocator (which owns
 * the concurrency-safe counter/allocation itself) -- see
 * docs/modules/HR.md ("Employee identifier strategy").
 *
 * Phase 8A.1 ships exactly one fixed format. A school-configurable
 * numbering engine is explicitly deferred (docs/modules/HR.md, Reuse
 * decisions table) -- do not extend this class with configuration
 * options without a real, reviewed requirement.
 */
class EmployeeNumberFormatter
{
    private const string PREFIX = 'EMP';

    private const int PAD_LENGTH = 6;

    public function format(int $sequenceValue): string
    {
        return self::PREFIX.'-'.str_pad((string) $sequenceValue, self::PAD_LENGTH, '0', STR_PAD_LEFT);
    }
}

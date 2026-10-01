<?php

namespace App\Domain\HR\Application\Retention;

/**
 * E21.2E (E21-D9): an Employee's final-separation state, as resolved by
 * EmployeeRetentionEligibility. Only `separated` carries a date.
 */
final class EmployeeSeparation
{
    public const CURRENT = 'current';

    public const SEPARATED = 'separated';

    public const UNRESOLVED = 'unresolved';

    private function __construct(
        public readonly string $state,
        public readonly ?string $separatedOn,
    ) {}

    public static function current(): self
    {
        return new self(self::CURRENT, null);
    }

    public static function unresolved(): self
    {
        return new self(self::UNRESOLVED, null);
    }

    public static function separated(string $separatedOn): self
    {
        return new self(self::SEPARATED, $separatedOn);
    }

    /** Strictly before the School-local cutoff date (a day exactly at it is kept). */
    public function separatedBefore(string $cutoffDate): bool
    {
        return $this->state === self::SEPARATED && $this->separatedOn < $cutoffDate;
    }
}

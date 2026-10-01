<?php

namespace App\Domain\Students\Application\Retention;

/**
 * E21.2D (E21-D7): a Student's departure state, as resolved by
 * StudentRetentionEligibility. Only `exited` carries a date.
 */
final class StudentExit
{
    public const CURRENT = 'current';

    public const EXITED = 'exited';

    public const UNRESOLVED = 'unresolved';

    private function __construct(
        public readonly string $state,
        public readonly ?string $exitDate,
    ) {}

    public static function current(): self
    {
        return new self(self::CURRENT, null);
    }

    public static function unresolved(): self
    {
        return new self(self::UNRESOLVED, null);
    }

    public static function exited(string $exitDate): self
    {
        return new self(self::EXITED, $exitDate);
    }

    /** Strictly before the School-local cutoff date (a day exactly at it is kept). */
    public function exitedBefore(string $cutoffDate): bool
    {
        return $this->state === self::EXITED && $this->exitDate < $cutoffDate;
    }
}

<?php

namespace App\Domain\Students\Application;

/**
 * SIS 11.3 (OD-SIS-6): one Section's operational occupancy -- its ADVISORY
 * capacity (Academic Structure's `sections.capacity`) against its strength
 * on an as-of date (StudentEnrollmentRosterReadService::sectionStrengthQuery).
 *
 * Operational, never analytics: a count against a seat number, never a
 * percentage, trend or breakdown. Advisory: nothing here refuses a
 * placement. A negative availability is shown as an overage, never clamped.
 */
final readonly class SectionOccupancy
{
    public const string UNCONFIGURED = 'unconfigured';

    public const string AVAILABLE = 'available';

    public const string AT_CAPACITY = 'at_capacity';

    public const string OVER_CAPACITY = 'over_capacity';

    /** A closed year: capacity is history; no current strength or vacancy. */
    public const string CLOSED_YEAR = 'closed_year';

    public const array STATE_LABELS = [
        self::UNCONFIGURED => 'Capacity not configured',
        self::AVAILABLE => 'Seats available',
        self::AT_CAPACITY => 'At advisory capacity',
        self::OVER_CAPACITY => 'Over advisory capacity',
        self::CLOSED_YEAR => 'Closed year',
    ];

    /**
     * @param  ?int  $strength  null for a closed year
     * @param  ?string  $asOf  the School-local date the strength counts (Y-m-d), null for a closed year
     */
    public function __construct(
        public ?int $capacity,
        public ?int $strength,
        public ?string $asOf,
    ) {}

    public function state(): string
    {
        return match (true) {
            $this->strength === null => self::CLOSED_YEAR,
            $this->capacity === null => self::UNCONFIGURED,
            $this->strength < $this->capacity => self::AVAILABLE,
            $this->strength === $this->capacity => self::AT_CAPACITY,
            default => self::OVER_CAPACITY,
        };
    }

    /** Capacity minus strength (negative = over), or null when either is unknown. */
    public function available(): ?int
    {
        return $this->capacity === null || $this->strength === null ? null : $this->capacity - $this->strength;
    }

    /** "28 / 30 · 2 seats available", "32 / 30 · Over advisory capacity by 2", "28 Students · Capacity not configured". */
    public function label(): string
    {
        return match ($this->state()) {
            self::CLOSED_YEAR => $this->capacity === null ? 'Closed year · capacity not configured' : "Closed year · capacity {$this->capacity}",
            self::UNCONFIGURED => self::students($this->strength).' · Capacity not configured',
            self::AVAILABLE => "{$this->strength} / {$this->capacity} · ".self::seats($this->available()),
            self::AT_CAPACITY => "{$this->strength} / {$this->capacity} · At advisory capacity",
            default => "{$this->strength} / {$this->capacity} · Over advisory capacity by ".abs((int) $this->available()),
        };
    }

    /** @return array{capacity: ?int, strength: ?int, asOf: ?string, available: ?int, state: string, stateLabel: string, label: string} */
    public function toArray(): array
    {
        return [
            'capacity' => $this->capacity,
            'strength' => $this->strength,
            'asOf' => $this->asOf,
            'available' => $this->available(),
            'state' => $this->state(),
            'stateLabel' => self::STATE_LABELS[$this->state()],
            'label' => $this->label(),
        ];
    }

    public static function seats(?int $n): string
    {
        return $n === 1 ? '1 seat available' : "{$n} seats available";
    }

    public static function students(?int $n): string
    {
        return $n === 1 ? '1 Student' : "{$n} Students";
    }
}

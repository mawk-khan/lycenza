<?php

namespace App\Domain\Fees\Application\Sources;

use InvalidArgumentException;

/**
 * OPF (ADR 0067 §8): which operational source asked FEE to record or withdraw
 * selection intent, and for which of its own rows. A closed catalogue: a
 * source module joins only with its own OPF slice. Fees records the source in
 * its audit trail and never reads the source module (§4).
 */
final readonly class FeeSelectionSource
{
    public const TRANSPORT = 'transport';

    /** OPF.2 (ADR 0067 §15): a Hostel residency. */
    public const HOSTEL = 'hostel';

    /** OPF.3 (ADR 0067 §16): a converted admission application (never an applicant). */
    public const ADMISSIONS = 'admissions';

    public const MODULES = [self::TRANSPORT, self::HOSTEL, self::ADMISSIONS];

    private function __construct(
        public string $module,
        public string $sourceId,
    ) {}

    public static function of(string $module, string $sourceId): self
    {
        if (! in_array($module, self::MODULES, true) || $sourceId === '') {
            throw new InvalidArgumentException("Unknown fee selection source: {$module}");
        }

        return new self($module, $sourceId);
    }

    public static function transport(string $assignmentId): self
    {
        return self::of(self::TRANSPORT, $assignmentId);
    }

    public static function hostel(string $residencyId): self
    {
        return self::of(self::HOSTEL, $residencyId);
    }

    public static function admissions(string $applicationId): self
    {
        return self::of(self::ADMISSIONS, $applicationId);
    }

    /** @return array{sourceModule: string, sourceId: string} */
    public function toAudit(): array
    {
        return ['sourceModule' => $this->module, 'sourceId' => $this->sourceId];
    }
}

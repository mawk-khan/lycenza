<?php

namespace App\Domain\Fees\Application\Sources;

/**
 * OPF (ADR 0067 §8): what selectForSource() did. `created` and `reused` carry
 * the active selection; `not_applicable` names why no selection could exist
 * (no current enrollment in the year, no active structure for it, no optional
 * line of the fee head, an inactive or unknown fee head).
 */
final readonly class FeeSourceSelectionResult
{
    public const CREATED = 'created';

    public const REUSED = 'reused';

    public const NOT_APPLICABLE = 'not_applicable';

    public const NO_ENROLLMENT = 'no_enrollment_in_year';

    public const NO_STRUCTURE = 'no_active_structure';

    public const NO_OPTIONAL_LINE = 'no_optional_line';

    public const FEE_HEAD_UNAVAILABLE = 'fee_head_unavailable';

    private function __construct(
        public string $outcome,
        public ?string $selectionId,
        public ?string $reason,
    ) {}

    public static function created(string $selectionId): self
    {
        return new self(self::CREATED, $selectionId, null);
    }

    public static function reused(string $selectionId): self
    {
        return new self(self::REUSED, $selectionId, null);
    }

    public static function notApplicable(string $reason): self
    {
        return new self(self::NOT_APPLICABLE, null, $reason);
    }

    public function hasSelection(): bool
    {
        return $this->selectionId !== null;
    }
}

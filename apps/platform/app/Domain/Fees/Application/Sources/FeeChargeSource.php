<?php

namespace App\Domain\Fees\Application\Sources;

/**
 * OPF.4 (ADR 0067 §8, D6): which operational source asked FEE to assess or
 * cancel one EVENT charge, and for which of its own rows. A closed catalogue,
 * separate from FeeSelectionSource: only Library fines are event charges.
 * Fees records the source in its audit trail and never reads the source
 * module (§4).
 */
final readonly class FeeChargeSource
{
    /** OPF.4 (ADR 0067 §17): a Library fine (the source id is the fine's id). */
    public const LIBRARY = 'library';

    public const KINDS = [self::LIBRARY];

    private function __construct(
        public string $kind,
        public string $sourceId,
    ) {}

    public static function libraryFine(string $fineId): self
    {
        return new self(self::LIBRARY, $fineId);
    }

    /** @return array{sourceKind: string, sourceId: string} */
    public function toAudit(): array
    {
        return ['sourceKind' => $this->kind, 'sourceId' => $this->sourceId];
    }
}

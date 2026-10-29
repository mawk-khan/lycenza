<?php

namespace App\Domain\Platform\Application\Groups;

/**
 * ORG.12F (ADR 0080 §11.7): the closed reasons for changing a Network brand.
 * Code-owned; no free text. Recorded on the platform ledger.
 */
enum NetworkBrandChangeReason: string
{
    case BRAND_LAUNCH = 'brand_launch';
    case REBRAND = 'rebrand';
    case ACCESSIBILITY = 'accessibility';
    case DATA_CORRECTION = 'data_correction';

    public function label(): string
    {
        return match ($this) {
            self::BRAND_LAUNCH => 'Setting up the Network brand',
            self::REBRAND => 'Rebranding',
            self::ACCESSIBILITY => 'Improving readability',
            self::DATA_CORRECTION => 'Correcting a mistake',
        };
    }

    /** @return list<array{code: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $r): array => ['code' => $r->value, 'label' => $r->label()], self::cases());
    }
}

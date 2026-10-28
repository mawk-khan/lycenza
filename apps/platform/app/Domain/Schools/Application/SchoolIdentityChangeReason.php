<?php

namespace App\Domain\Schools\Application;

/**
 * ORG.12C (ADR 0080 §8): the closed reasons for changing a School's
 * institutional identity (legal name, registration reference, affiliation
 * reference). Code-owned; no `other`, no free text.
 */
enum SchoolIdentityChangeReason: string
{
    case FIRST_RECORDED = 'first_recorded';
    case CORRECTION = 'correction';
    case LEGAL_CHANGE = 'legal_change';
    case AFFILIATION_CHANGE = 'affiliation_change';

    public function label(): string
    {
        return match ($this) {
            self::FIRST_RECORDED => 'Recording it for the first time',
            self::CORRECTION => 'Correcting a mistake',
            self::LEGAL_CHANGE => 'The legal entity or its registration changed',
            self::AFFILIATION_CHANGE => 'The affiliation changed',
        };
    }

    /** @return list<array{code: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $r): array => ['code' => $r->value, 'label' => $r->label()], self::cases());
    }
}

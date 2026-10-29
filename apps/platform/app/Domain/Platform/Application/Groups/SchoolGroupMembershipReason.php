<?php

namespace App\Domain\Platform\Application\Groups;

/**
 * ORG.12D (ADR 0080 §9): the closed reasons for a Group membership change.
 * Code-owned; no `other`, no free text. Recorded on the platform ledger.
 */
enum SchoolGroupMembershipReason: string
{
    case NETWORK_JOIN = 'network_join';
    case INITIAL_CLASSIFICATION = 'initial_classification';
    case OPERATIONAL_REORGANIZATION = 'operational_reorganization';
    case FRANCHISE_CONVERSION = 'franchise_conversion';
    case OWNERSHIP_CHANGE = 'ownership_change';
    case DATA_CORRECTION = 'data_correction';
    case NETWORK_EXIT = 'network_exit';

    public function label(): string
    {
        return match ($this) {
            self::NETWORK_JOIN => 'The School joins the network',
            self::INITIAL_CLASSIFICATION => 'Classifying it for the first time',
            self::OPERATIONAL_REORGANIZATION => 'Operational reorganization',
            self::FRANCHISE_CONVERSION => 'Franchise conversion',
            self::OWNERSHIP_CHANGE => 'Ownership change',
            self::DATA_CORRECTION => 'Correcting a mistake',
            self::NETWORK_EXIT => 'The School leaves the network',
        };
    }

    /** @return list<array{code: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $r): array => ['code' => $r->value, 'label' => $r->label()], self::cases());
    }
}

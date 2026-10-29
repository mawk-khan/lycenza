<?php

namespace App\Domain\Platform\Application\Groups;

/**
 * ORG.12E (ADR 0080 §10.5): the closed reasons for granting or revoking a
 * Network role. Code-owned; no `other`, no free text. Recorded on the
 * platform ledger.
 */
enum NetworkRoleChangeReason: string
{
    case APPOINTMENT = 'appointment';
    case OPERATIONAL_NEED = 'operational_need';
    case SCOPE_CHANGE = 'scope_change';
    case ROLE_ENDED = 'role_ended';
    case SECURITY_REVIEW = 'security_review';
    case DATA_CORRECTION = 'data_correction';

    public function label(): string
    {
        return match ($this) {
            self::APPOINTMENT => 'New appointment',
            self::OPERATIONAL_NEED => 'Operational need',
            self::SCOPE_CHANGE => 'Changing the Region or Zone scope',
            self::ROLE_ENDED => 'The person no longer needs the role',
            self::SECURITY_REVIEW => 'Security review',
            self::DATA_CORRECTION => 'Correcting a mistake',
        };
    }

    /** @return list<array{code: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $r): array => ['code' => $r->value, 'label' => $r->label()], self::cases());
    }
}

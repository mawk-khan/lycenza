<?php

namespace App\Support\Observability\Alerts;

/** ADR 0051 §14.2 (frozen terminology). */
enum Severity: string
{
    case Sev1 = 'SEV-1';
    case Sev2 = 'SEV-2';
    case Sev3 = 'SEV-3';

    public function label(): string
    {
        return match ($this) {
            self::Sev1 => 'critical',
            self::Sev2 => 'high',
            self::Sev3 => 'warning',
        };
    }

    public function rank(): int
    {
        return match ($this) {
            self::Sev1 => 1,
            self::Sev2 => 2,
            self::Sev3 => 3,
        };
    }
}

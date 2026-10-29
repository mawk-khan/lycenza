<?php

namespace App\Support\Integrations;

/**
 * ORG.12G (ADR 0080 §12): the Hub's closed sections, in display order. The
 * Overview is page-level and is not a section.
 */
enum IntegrationSection: string
{
    case COMMUNICATIONS = 'communications';
    case PAYMENTS = 'payments';
    case ATTENDANCE_DEVICES = 'attendance_devices';
    case TRANSPORT_TRACKING = 'transport_tracking';
    case PLATFORM_OTHER = 'platform_other';

    public function label(): string
    {
        return match ($this) {
            self::COMMUNICATIONS => 'Communications',
            self::PAYMENTS => 'Payments',
            self::ATTENDANCE_DEVICES => 'Attendance & access devices',
            self::TRANSPORT_TRACKING => 'Transport & tracking',
            self::PLATFORM_OTHER => 'Platform & other',
        };
    }
}

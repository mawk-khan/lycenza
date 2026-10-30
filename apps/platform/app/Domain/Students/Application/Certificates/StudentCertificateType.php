<?php

namespace App\Domain\Students\Application\Certificates;

/**
 * SIS.EXP-4 (OD-SIS-2): the two code-owned certificate types, mirrored by
 * `student_certificates_type_check`. Both certify the same canonical exit
 * (StudentCertificateEligibility) through one issuance architecture; a
 * Transfer Certificate additionally needs the closed exit reason
 * `transferred_out`. Schools cannot add a type.
 */
enum StudentCertificateType: string
{
    case LEAVING = 'leaving';
    case TRANSFER = 'transfer';

    public function label(): string
    {
        return match ($this) {
            self::LEAVING => 'Leaving Certificate',
            self::TRANSFER => 'Transfer Certificate',
        };
    }

    /** The display number: a stable label of the canonical serial (never security-relevant). */
    public function displayNumber(int $serial): string
    {
        return ($this === self::LEAVING ? 'LC-' : 'TC-').str_pad((string) $serial, 6, '0', STR_PAD_LEFT);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $t): string => $t->value, self::cases());
    }
}

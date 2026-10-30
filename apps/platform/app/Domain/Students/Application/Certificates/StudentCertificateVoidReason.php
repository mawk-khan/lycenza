<?php

namespace App\Domain\Students\Application\Certificates;

/**
 * SIS.EXP-4: why an issued certificate was voided. Closed and code-owned
 * (mirrored by `student_certificates_void_reason_check`); no free text.
 * `replaced_by_corrected_certificate` is used only by the reissue workflow,
 * which issues the linked replacement in the same transaction.
 */
enum StudentCertificateVoidReason: string
{
    case DATA_ERROR = 'data_error';
    case ISSUED_IN_ERROR = 'issued_in_error';
    case REPLACED_BY_CORRECTED_CERTIFICATE = 'replaced_by_corrected_certificate';

    public function label(): string
    {
        return match ($this) {
            self::DATA_ERROR => 'The certificate data was wrong',
            self::ISSUED_IN_ERROR => 'Issued in error',
            self::REPLACED_BY_CORRECTED_CERTIFICATE => 'Replaced by a corrected certificate',
        };
    }

    /** The reasons a plain void may use (a replacement goes through reissue). @return list<self> */
    public static function plainVoid(): array
    {
        return [self::DATA_ERROR, self::ISSUED_IN_ERROR];
    }
}

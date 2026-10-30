<?php

namespace App\Domain\Students\Application\Certificates;

use App\Domain\Students\Application\Exceptions\StudentCertificateUnavailableException;

/**
 * SIS.EXP-4 (OD-SIS-2.2): the legal production gate. The certificate
 * template is GENERIC development wording -- no statutory, board-prescribed
 * or jurisdiction-specific form has legal approval -- so issuing, voiding and
 * reissuing run only when `APP_ENV` is `local` or `testing` (the
 * StudentMarkAvailability pattern).
 *
 * Deliberately not configurable: no variable or flag opens it. Lifting it is
 * a reviewed code change made once the production wording, fields and
 * jurisdiction are legally approved (docs/modules/STUDENT-ENROLLMENT.md,
 * "Leaving and Transfer certificates"). Reading history and downloading an
 * already-issued file are not gated (none can exist in production).
 */
final class StudentCertificateAvailability
{
    /** @var list<string> */
    public const array ENVIRONMENTS = ['local', 'testing'];

    public static function isAvailable(): bool
    {
        return app()->environment(self::ENVIRONMENTS);
    }

    /** @throws StudentCertificateUnavailableException */
    public static function assertAvailable(): void
    {
        if (! self::isAvailable()) {
            throw new StudentCertificateUnavailableException;
        }
    }
}

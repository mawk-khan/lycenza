<?php

namespace App\Domain\Students\Application\Documents;

/**
 * SIS.EXP-3: why a Student document left active use. Closed and code-owned
 * (mirrored by `student_documents_archive_reason_check`); there is no free
 * text. Archive is final: a replacement is a new upload plus `superseded`.
 */
enum StudentDocumentArchiveReason: string
{
    case UPLOADED_IN_ERROR = 'uploaded_in_error';
    case SUPERSEDED = 'superseded';
    case NO_LONGER_REQUIRED = 'no_longer_required';
    /** SIS.EXP-4: set only when its certificate is voided (never chosen by staff). */
    case CERTIFICATE_VOIDED = 'certificate_voided';

    public function label(): string
    {
        return match ($this) {
            self::UPLOADED_IN_ERROR => 'Uploaded in error',
            self::SUPERSEDED => 'Replaced by a newer document',
            self::NO_LONGER_REQUIRED => 'No longer required',
            self::CERTIFICATE_VOIDED => 'Certificate voided',
        };
    }

    /** The reasons staff may choose. @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        $options = [];
        foreach (self::cases() as $reason) {
            if ($reason !== self::CERTIFICATE_VOIDED) {
                $options[] = ['value' => $reason->value, 'label' => $reason->label()];
            }
        }

        return $options;
    }

    /** @return list<string> */
    public static function choosableValues(): array
    {
        return array_column(self::options(), 'value');
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $r): string => $r->value, self::cases());
    }
}

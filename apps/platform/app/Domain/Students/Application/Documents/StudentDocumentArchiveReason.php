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

    public function label(): string
    {
        return match ($this) {
            self::UPLOADED_IN_ERROR => 'Uploaded in error',
            self::SUPERSEDED => 'Replaced by a newer document',
            self::NO_LONGER_REQUIRED => 'No longer required',
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $r): array => ['value' => $r->value, 'label' => $r->label()], self::cases());
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $r): string => $r->value, self::cases());
    }
}

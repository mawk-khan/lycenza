<?php

namespace App\Domain\Students\Application\Documents;

/**
 * SIS 11.5: why a document was not accepted -- closed codes only, never free
 * text (mirrored by `*_rejection_reason_check`). The fix is always a
 * replacement upload; the rejected record stays as history.
 */
enum DocumentRejectionReason: string
{
    case ILLEGIBLE = 'illegible';
    case WRONG_DOCUMENT = 'wrong_document';
    case INCOMPLETE = 'incomplete';
    case NOT_VALID = 'not_valid';
    case WRONG_PERSON = 'wrong_person';

    public function label(): string
    {
        return match ($this) {
            self::ILLEGIBLE => 'Cannot be read',
            self::WRONG_DOCUMENT => 'Not the requested document',
            self::INCOMPLETE => 'Pages or details missing',
            self::NOT_VALID => 'Not valid or not accepted',
            self::WRONG_PERSON => 'Belongs to someone else',
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

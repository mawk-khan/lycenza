<?php

namespace App\Domain\AcademicStructure\Application;

use App\Domain\AcademicStructure\Infrastructure\Section;

/**
 * SIS 11.H1 (SIS 11.R1 stale-form inventory): the optimistic-concurrency
 * token of a Section's editable fields -- a digest of exactly what the edit
 * paths write (name, code, advisory capacity, status), the same pattern as
 * StudentProfileVersion. A digest, not `updated_at` (second precision).
 * Unkeyed: every input is visible to anyone who can open the edit form.
 */
final class SectionVersion
{
    public static function of(Section $section): string
    {
        return hash('sha256', implode("\x1f", [
            'section:v1',
            $section->id,
            $section->name,
            $section->code,
            $section->capacity === null ? '' : (string) $section->capacity,
            $section->status,
        ]));
    }

    public static function matches(Section $section, string $expected): bool
    {
        return hash_equals(self::of($section), $expected);
    }
}

<?php

namespace App\Domain\AcademicStructure\Application;

use App\Domain\AcademicStructure\Infrastructure\Section;

/**
 * UAT-C4 (UAT-ATT-08; ADR 0075 section 17): the one way a staff page names a
 * Section where Sections of several Grades appear together -- "Grade 6 · A".
 * Section names repeat across Grades (6 A, 7 A, 8 A), so a name alone is
 * ambiguous. Load `gradeLevel` with the Section to avoid a query per row.
 * Pages scoped to one Grade (an Offering's own Sections) may keep the name.
 */
final class SectionLabel
{
    public static function of(?Section $section): ?string
    {
        if ($section === null) {
            return null;
        }
        $grade = $section->gradeLevel?->name;

        return $grade === null ? $section->name : "{$grade} · {$section->name}";
    }

    /** Sort key: Grade order, then Section name (natural). */
    public static function sortKey(?Section $section): string
    {
        return sprintf('%06d|%s', $section->gradeLevel->sequence ?? 999999, mb_strtolower($section->name ?? ''));
    }
}

<?php

namespace App\Domain\AcademicStructure\Application;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use Illuminate\Support\Collection;

/**
 * UAT-C4 (UAT-FIN-11, UAT-ADM-15/17; ADR 0075 section 17): how a form that
 * records something for the School's CURRENT year offers academic years.
 * The active year comes first and is the default; upcoming draft years
 * follow (soonest first) and stay selectable for advance work; closed years
 * come last (newest first). Every option says which kind it is, so a draft
 * next year is never picked by accident because it sorts later by date.
 *
 * Presentation only -- it never decides what a form may accept (each write
 * path keeps its own rules). Planning pages (fee structures, rollovers,
 * School setup) and report filters keep their own ordering.
 */
final class AcademicYearChoices
{
    /**
     * @param  Collection<int, AcademicYear>  $years  one School's years
     * @return Collection<int, AcademicYear>
     */
    public static function operationalOrder(Collection $years): Collection
    {
        return $years->sortBy(fn (AcademicYear $y) => match ($y->status) {
            'active' => [0, 0],
            'draft' => [1, $y->starts_on->getTimestamp()],
            default => [2, -$y->starts_on->getTimestamp()],
        })->values();
    }

    public static function label(AcademicYear $year): string
    {
        return $year->name.' — '.match ($year->status) {
            'active' => 'current year',
            'draft' => 'upcoming (draft)',
            'closed' => 'closed',
            default => $year->status,
        };
    }

    /** @param  Collection<int, AcademicYear>  $years */
    public static function defaultId(Collection $years): ?string
    {
        return $years->firstWhere('status', 'active')?->id;
    }

    /**
     * Options for a select: id, name, status and label, in operational order.
     *
     * @param  Collection<int, AcademicYear>  $years
     * @return list<array{id: string, name: string, code: string, status: string, label: string}>
     */
    public static function options(Collection $years): array
    {
        return self::operationalOrder($years)->map(fn (AcademicYear $y) => [
            'id' => $y->id,
            'name' => $y->name,
            'code' => $y->code,
            'status' => $y->status,
            'label' => self::label($y),
        ])->values()->all();
    }
}

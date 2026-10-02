<?php

namespace App\Domain\AcademicStructure\Application\Retention;

use App\Models\School;
use App\Support\Tenancy\SchoolTimezone;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;

/**
 * E21.3D (E21.2G A1, project-adopted, pending legal ratification): the one
 * year-end clock of year-bound academic operations. A row is past its
 * period when its AUTHORITATIVE Academic Year ended strictly before the
 * School-local date `$years` calendar years ago (`subYearsNoOverflow`: a
 * 29 February end becomes eligible on 1 March). The year comes only from
 * the row's own `academic_year_id` (enforced by its composite foreign keys
 * to its Section and SubjectOffering), never from a date, `created_at` or
 * the School's current year. `academic_years` dates are immutable
 * (trigger `academic_years_freeze_dates`), so the clock never moves.
 *
 * It decides the clock only. Dependencies, locks and deletes stay with the
 * owning module's retention service.
 */
final class AcademicYearRetention
{
    /** The School-local cutoff date: a year that ended strictly before it is past the period. */
    public static function cutoff(School $school, int $years): string
    {
        return CarbonImmutable::now(SchoolTimezone::resolve($school))->subYearsNoOverflow($years)->toDateString();
    }

    /** Narrows `$alias` (a row with `academic_year_id` and `school_id`) to rows whose Academic Year ended strictly before `$cutoff`. */
    public static function endedBefore(Builder $query, string $alias, string $cutoff): Builder
    {
        return $query->whereExists(fn (Builder $q) => $q->selectRaw('1')->from('academic_years as y')
            ->whereColumn('y.id', "{$alias}.academic_year_id")->whereColumn('y.school_id', "{$alias}.school_id")
            ->where('y.ends_on', '<', $cutoff));
    }
}

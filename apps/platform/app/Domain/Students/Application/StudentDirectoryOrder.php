<?php

namespace App\Domain\Students\Application;

use App\Domain\Students\Infrastructure\Student;
use Illuminate\Database\Eloquent\Builder;

/**
 * SIS 11.1: the Student Directory's closed sort contract (governed by the
 * SIS report catalogue, StudentReportRegistry). Request input only ever
 * picks a key; each key maps to a fixed ORDER BY with a unique tie-breaker,
 * so pagination is stable.
 */
final class StudentDirectoryOrder
{
    public const int PER_PAGE = 20;

    /** sort key => ordered columns (the first key is the default). */
    public const array SORTS = [
        'name' => ['students.first_name', 'students.last_name', 'students.id'],
        'student_number' => ['students.student_number', 'students.id'],
    ];

    public const array LABELS = ['name' => 'Name', 'student_number' => 'Student number'];

    /**
     * @param  Builder<Student>  $query
     * @return Builder<Student>
     */
    public static function apply(Builder $query, ?string $sort): Builder
    {
        foreach (self::SORTS[$sort ?? 'name'] ?? self::SORTS['name'] as $column) {
            $query->orderBy($column);
        }

        return $query;
    }
}

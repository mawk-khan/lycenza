<?php

namespace App\Domain\Students\Application;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * UAT-C4 (UAT-SRCH-01; ADR 0075 section 17): how staff find a Student by
 * what they type. One rule for the Students directory, the enrollment
 * directory and every same-School Student picker:
 *
 * - **Words, not fields.** The term is split on whitespace (at most five
 *   words); every word must appear (case-insensitive, anywhere) in the
 *   first, middle or last name -- so "Riya Kapoor", "Kapoor Riya" and
 *   "riya k" all find Riya Kapoor, and a middle name never gets in the way.
 *   Pickers also accept a word that appears in the Student number.
 * - **Number fragments.** A Student number matches on any part
 *   ("0103" finds LDS-0103), case-insensitive. The query is already narrowed
 *   to one School (`students` is RLS-scoped and every caller is School
 *   scoped), so a contains-match scans one School's Students at most --
 *   the same shape every picker already used.
 * - **Literal input.** `%`, `_` and `\` typed by the user match themselves,
 *   never act as wildcards; every value is a bound parameter.
 *
 * Matching only: it never widens WHO may be searched -- each caller keeps
 * its own capability, School, status and pagination rules. No fuzzy or
 * phonetic matching.
 */
final class StudentSearch
{
    public const MAX_WORDS = 5;

    private const NAME_COLUMNS = ['first_name', 'middle_name', 'last_name'];

    /**
     * Every word in a name field or the Student number (pickers).
     *
     * @template TQuery of EloquentBuilder|QueryBuilder
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    public static function anyField(EloquentBuilder|QueryBuilder $query, string $term, string $table = 'students'): EloquentBuilder|QueryBuilder
    {
        return self::words($query, $term, $table, withNumber: true);
    }

    /**
     * Every word in a name field (the directories' Name filter).
     *
     * @template TQuery of EloquentBuilder|QueryBuilder
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    public static function name(EloquentBuilder|QueryBuilder $query, string $term, string $table = 'students'): EloquentBuilder|QueryBuilder
    {
        return self::words($query, $term, $table, withNumber: false);
    }

    /**
     * A Student number containing the fragment (the directories' Number filter).
     *
     * @template TQuery of EloquentBuilder|QueryBuilder
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    public static function number(EloquentBuilder|QueryBuilder $query, string $fragment, string $table = 'students'): EloquentBuilder|QueryBuilder
    {
        $fragment = trim($fragment);

        return $fragment === '' ? $query : $query->where("{$table}.student_number", 'ilike', '%'.self::escape($fragment).'%');
    }

    /** @return list<string> */
    public static function wordsOf(string $term): array
    {
        $words = preg_split('/\s+/u', trim($term), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_slice($words, 0, self::MAX_WORDS);
    }

    /**
     * @template TQuery of EloquentBuilder|QueryBuilder
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    private static function words(EloquentBuilder|QueryBuilder $query, string $term, string $table, bool $withNumber): EloquentBuilder|QueryBuilder
    {
        foreach (self::wordsOf($term) as $word) {
            $like = '%'.self::escape($word).'%';
            $query->where(function ($q) use ($table, $like, $withNumber): void {
                foreach (self::NAME_COLUMNS as $column) {
                    $q->orWhere("{$table}.{$column}", 'ilike', $like);
                }
                if ($withNumber) {
                    $q->orWhere("{$table}.student_number", 'ilike', $like);
                }
            });
        }

        return $query;
    }

    /** PostgreSQL LIKE's default escape character is the backslash. */
    private static function escape(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}

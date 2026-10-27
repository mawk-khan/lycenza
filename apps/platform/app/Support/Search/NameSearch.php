<?php

namespace App\Support\Search;

use Illuminate\Contracts\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;

/**
 * SIS 2.2: THE person-name search rule, shared by Student search
 * (StudentSearch) and Guardian identity search. Explainable and
 * deterministic, never fuzzy:
 *
 * - the term is split on whitespace (extra spaces ignored), at most
 *   MAX_WORDS words;
 * - EVERY word must occur (case-insensitive `ilike`, as a substring) in at
 *   least ONE of the given columns, so "Ravi Kumar", "kumar ravi" and
 *   "Ravi  K" all find first = Ravi, middle = ..., last = Kumar;
 * - `%`, `_` and `\` in the term are literal.
 *
 * Only the columns the caller names are searched -- name columns, never an
 * encrypted contact value (ADR 0041). Tenancy and authorization stay the
 * caller's query (School scope, capability).
 */
final class NameSearch
{
    public const MAX_WORDS = 5;

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
     * @param  list<string>  $columns  qualified column names
     * @return TQuery
     */
    public static function everyWord(EloquentBuilder|QueryBuilder $query, string $term, array $columns): EloquentBuilder|QueryBuilder
    {
        foreach (self::wordsOf($term) as $word) {
            $like = '%'.self::escape($word).'%';
            $query->where(function ($q) use ($columns, $like): void {
                foreach ($columns as $column) {
                    $q->orWhere($column, 'ilike', $like);
                }
            });
        }

        return $query;
    }

    /** PostgreSQL LIKE's default escape character is the backslash. */
    public static function escape(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}

<?php

namespace App\Support\Authorization\Campus;

use Illuminate\Contracts\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Eloquent\Builder;

/**
 * ORG.7A (ADR 0076 §11.7): the answer to "where may this actor use this
 * capability in this School" -- none, the whole School, or a set of ACTIVE
 * Campus ids. Immutable; computed by CampusScopeResolver from persisted
 * grants only (never request input or TenantContext::campus()).
 *
 * - The whole School covers every Campus and records with no Campus.
 * - A Campus set covers exactly those Campuses -- never a NULL-Campus
 *   (School-wide) record.
 * - None covers nothing.
 */
final class CampusScope
{
    /** @param  list<string>  $campusIds */
    private function __construct(private readonly bool $wholeSchool, private readonly array $campusIds) {}

    public static function none(): self
    {
        return new self(false, []);
    }

    public static function wholeSchool(): self
    {
        return new self(true, []);
    }

    /** @param  list<string>  $campusIds */
    public static function campuses(array $campusIds): self
    {
        $ids = array_values(array_unique($campusIds));
        sort($ids);

        return $ids === [] ? self::none() : new self(false, $ids);
    }

    public function isNone(): bool
    {
        return ! $this->wholeSchool && $this->campusIds === [];
    }

    public function isWholeSchool(): bool
    {
        return $this->wholeSchool;
    }

    /** @return list<string> the Campus ids of a Campus-limited scope ([] for the whole School or none) */
    public function campusIds(): array
    {
        return $this->campusIds;
    }

    /** Whether a record of $campusId (NULL = School-wide) is within scope. */
    public function covers(?string $campusId): bool
    {
        if ($this->wholeSchool) {
            return true;
        }

        return $campusId !== null && in_array($campusId, $this->campusIds, true);
    }

    /**
     * Restricts a query to the scope, in SQL, before any row is returned:
     * whole School -> unchanged; Campus set -> `<column> IN (...)` (NULL
     * excluded); none -> no rows.
     *
     * @template T of Builder|QueryBuilder
     *
     * @param  T  $query
     * @return T
     */
    public function restrict(Builder|QueryBuilder $query, string $column = 'campus_id'): Builder|QueryBuilder
    {
        if ($this->wholeSchool) {
            return $query;
        }
        if ($this->campusIds === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn($column, $this->campusIds);
    }
}

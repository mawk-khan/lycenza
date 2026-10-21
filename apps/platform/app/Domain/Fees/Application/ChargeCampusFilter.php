<?php

namespace App\Domain\Fees\Application;

use Illuminate\Database\Eloquent\Builder;

/**
 * ORG.7G-1A (ADR 0076 §49): narrows Charges by their STORED historical Campus
 * (`charges.campus_id`), in SQL -- one Campus, or the unattributed
 * (whole-School) Charges. A request filter, never authority: it only ever
 * removes rows from what the caller was already authorized to read, and a
 * NULL-Campus Charge never matches a Campus.
 */
final class ChargeCampusFilter
{
    private function __construct(public readonly ?string $campusId) {}

    public static function campus(string $campusId): self
    {
        return new self($campusId);
    }

    public static function unattributed(): self
    {
        return new self(null);
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function apply(Builder $query, string $column = 'campus_id'): Builder
    {
        return $this->campusId === null ? $query->whereNull($column) : $query->where($column, $this->campusId);
    }
}

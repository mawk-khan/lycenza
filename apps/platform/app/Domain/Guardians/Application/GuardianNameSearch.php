<?php

namespace App\Domain\Guardians\Application;

use App\Support\Search\NameSearch;
use Illuminate\Contracts\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;

/**
 * SIS 2.2: Guardian IDENTITY search -- the one rule every Guardian name
 * search uses (the Guardian directory, web and API; the Student page's
 * "link an existing Guardian"; the Admissions conversion picker).
 *
 * Every word must occur in the first, middle or last name (NameSearch, the
 * same rule as Student search): "Aisha Khan" and "khan aisha" find
 * first = Aisha, middle = Noor, last = Khan. Names only: a mobile number or
 * an email address is never searched here -- contact values are encrypted
 * and found only by the exact-match HMAC lookup of a governed workflow
 * (GuardianContactService::findCandidatesBySchool, ADR 0041 / SRCH-02).
 * School scope and authorization stay the caller's query.
 */
final class GuardianNameSearch
{
    public const COLUMNS = ['guardians.first_name', 'guardians.middle_name', 'guardians.last_name'];

    /**
     * @template TQuery of EloquentBuilder|QueryBuilder
     *
     * @param  TQuery  $query  a guardians query
     * @return TQuery
     */
    public static function apply(EloquentBuilder|QueryBuilder $query, string $term): EloquentBuilder|QueryBuilder
    {
        return NameSearch::everyWord($query, $term, self::COLUMNS);
    }
}

<?php

namespace App\Support\Retention\Erasure;

use App\Models\School;

/**
 * E21.2F (E21-D10): one subject type's closed-list erasure adapter, owned by
 * its domain. The planner never deletes and never decides a retention
 * period: it asks the adapter. The adapter reuses its domain's canonical
 * eligibility and retention purges, so erasure can never shorten an
 * adopted period, bypass a hold or cascade into another domain.
 *
 * `$school` is null only for a platform-scope (User) case.
 */
interface ErasureSubjectAdapter
{
    public function subjectType(): string;

    /** Whether the subject exists in this scope (a School case sees only its School). */
    public function exists(?School $school, string $subjectId): bool;

    /**
     * Read-only.
     *
     * @return list<ErasureCategory>
     */
    public function plan(?School $school, string $subjectId): array;

    /**
     * Removes only the ELIGIBLE categories, through the domain's own locked
     * purges (which recheck eligibility under the lock), then plans again.
     *
     * @return list<ErasureCategory>
     */
    public function execute(?School $school, string $subjectId): array;
}

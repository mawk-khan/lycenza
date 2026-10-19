<?php

namespace App\Domain\AcademicStructure\Application;

use App\Domain\AcademicStructure\Application\Exceptions\NoActiveAcademicYearException;
use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Models\School;
use App\Support\Tenancy\TenantContext;

/**
 * Phase 0D section 60: the ONE authoritative way to find "the" active
 * Academic Year for a School. Future modules must call this rather
 * than each independently querying `where('status', 'active')` --
 * fails explicitly (never silently picks the latest-dated year) when
 * no year is active, so a caller can decide how to handle "School
 * hasn't activated a year yet" instead of silently operating against
 * the wrong year.
 *
 * Resolves authoritatively for the GIVEN School regardless of whatever
 * ambient TenantContext the caller currently has -- the same
 * `withSchool()` pattern as CapabilityResolver.
 */
class CurrentAcademicYearResolver
{
    public function __construct(private readonly TenantContext $context) {}

    public function resolve(School $school): AcademicYear
    {
        return $this->tryResolve($school) ?? throw new NoActiveAcademicYearException($school->id);
    }

    public function tryResolve(School $school): ?AcademicYear
    {
        return $this->context->withSchool(
            $school,
            fn () => AcademicYear::query()->where('school_id', $school->id)->where('status', 'active')->first(),
        );
    }

    /**
     * ORG.7D-4 (ADR 0076 §40): the active Academic Year's id, share-locked
     * (FOR SHARE) until the caller's transaction ends -- for a write whose
     * correctness depends on WHICH year is active. AcademicYearService's
     * activate() and close() update that row, so they wait for the caller to
     * commit. If one committed first, the active row no longer matches and
     * this answers null; it never guesses a newer year from a stale snapshot.
     * Must be called inside a transaction.
     */
    public function shareLockActiveId(School $school): ?string
    {
        return $this->context->withSchool(
            $school,
            fn () => AcademicYear::query()->where('school_id', $school->id)->where('status', 'active')->sharedLock()->value('id'),
        );
    }

    /**
     * OPF.1 (ADR 0067 D8): a named Academic Year of this School that is
     * still open -- `draft` (being prepared) or `active` -- or null (unknown,
     * another School's, or `closed`). Same School-authoritative resolution.
     */
    public function openYear(School $school, string $academicYearId): ?AcademicYear
    {
        return $this->context->withSchool(
            $school,
            fn () => AcademicYear::query()->where('school_id', $school->id)->whereIn('status', ['draft', 'active'])->find($academicYearId),
        );
    }

    /**
     * LC-3 (M3): the names ("2026-27") of this School's Academic Years among
     * the given ids, keyed by id, in one query -- for a module that holds a
     * year id and must show people a name. Another School's or an unknown id
     * is simply absent; the caller decides how an absent year reads.
     *
     * @param  list<string>  $academicYearIds
     * @return array<string, string>
     */
    public function namesFor(School $school, array $academicYearIds): array
    {
        if ($academicYearIds === []) {
            return [];
        }

        return $this->context->withSchool(
            $school,
            fn () => AcademicYear::query()->where('school_id', $school->id)->whereIn('id', array_values(array_unique($academicYearIds)))
                ->pluck('name', 'id')->all(),
        );
    }
}

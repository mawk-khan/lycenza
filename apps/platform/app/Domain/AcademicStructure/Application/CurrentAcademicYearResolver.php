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
}

<?php

namespace App\Domain\Guardians\Application;

use App\Domain\Guardians\Infrastructure\Guardian;
use App\Domain\Guardians\Infrastructure\StudentGuardianRelationship;
use App\Models\School;
use App\Support\Tenancy\TenantContext;

/**
 * POR (ADR 0070 §6): which Students a Guardian persona may reach through the
 * portal, derived LIVE from `student_guardian_relationships` -- never from
 * the account link, invitation history, names, contact data, membership,
 * role, or any cached Student id. School-scoped (TenantContext + RLS) and
 * never cached.
 *
 * The eligibility predicate is an ENGINEERING FAIL-CLOSED DEFAULT PENDING
 * LEGAL/PRIVACY DETERMINATION (POR-L1, ADR 0058 E46), not a legal
 * conclusion: a relationship recorded with `is_legal_guardian = true`, to a
 * Student whose status is `active`, by a Guardian whose status is `active`.
 * POR-L1 may broaden, narrow or replace it (an ADR 0070 amendment).
 *
 * POR.1 needs only the existence answer (ActingGuardian's "at least one
 * eligible relationship"); the per-Student read seam is POR.2.
 */
final class GuardianStudentScope
{
    public function __construct(private readonly TenantContext $context) {}

    public function isActiveGuardian(School $school, string $guardianId): bool
    {
        return $this->context->withSchool($school, fn (): bool => Guardian::query()
            ->where('school_id', $school->id)
            ->whereKey($guardianId)
            ->where('status', 'active')
            ->exists());
    }

    public function hasEligibleStudent(School $school, string $guardianId): bool
    {
        return $this->context->withSchool($school, fn (): bool => StudentGuardianRelationship::query()
            ->where('school_id', $school->id)
            ->where('guardian_id', $guardianId)
            ->where('is_legal_guardian', true)
            ->whereHas('student', fn ($q) => $q->where('school_id', $school->id)->where('status', 'active'))
            ->exists());
    }
}

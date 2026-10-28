<?php

namespace App\Domain\Students\Application;

use App\Domain\Students\Infrastructure\Student;
use App\Models\School;

/**
 * ORG.12A (ADR 0080 §10): whether any active Student of the School lacks a
 * legal-guardian relationship -- one EXISTS answer for setup readiness,
 * never a Student, a Guardian or a count. Lives in Students so a Guardians
 * caller never reads the Student model itself (CLAUDE.md rule 4).
 */
class StudentGuardianCoverage
{
    public function hasActiveStudentWithoutLegalGuardian(School $school): bool
    {
        return Student::query()->where('school_id', $school->id)->where('status', 'active')
            ->whereDoesntHave('guardianRelationships', fn ($q) => $q->where('school_id', $school->id)->where('is_legal_guardian', true))
            ->exists();
    }
}

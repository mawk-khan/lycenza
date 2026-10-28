<?php

namespace App\Domain\Guardians\Application\Setup;

use App\Domain\Guardians\Infrastructure\Guardian;
use App\Domain\Students\Application\StudentGuardianCoverage;
use App\Models\School;
use App\Support\Setup\SetupReadiness;
use App\Support\Setup\SetupReadinessProvider;
use App\Support\Setup\SetupStatus;

/**
 * ORG.12A (ADR 0080 §10): Guardian identity readiness -- an active Guardian
 * exists, and (asked of Students) no active Student lacks a legal-guardian
 * relationship. EXISTS answers only: no Guardian, contact or Student is
 * ever returned.
 */
class GuardianSetupReadiness implements SetupReadinessProvider
{
    public function __construct(private readonly StudentGuardianCoverage $coverage) {}

    public function keys(): array
    {
        return ['students.guardian_identity'];
    }

    public function evaluate(string $key, School $school): SetupReadiness
    {
        if (! Guardian::query()->where('school_id', $school->id)->where('status', 'active')->exists()) {
            return new SetupReadiness(SetupStatus::NOT_CONFIGURED, 'No active Guardians are on record yet.', 0, 2);
        }

        return $this->coverage->hasActiveStudentWithoutLegalGuardian($school)
            ? new SetupReadiness(SetupStatus::ATTENTION_REQUIRED, 'Some active Students have no legal guardian linked.', 1, 2)
            : new SetupReadiness(SetupStatus::CONFIGURED, 'Guardians are on record and every active Student has a legal guardian.', 2, 2);
    }
}

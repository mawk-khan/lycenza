<?php

namespace App\Domain\Students\Application\Setup;

use App\Domain\Students\Application\DataHealth\ActiveStudentWithoutCurrentPlacementCheck;
use App\Domain\Students\Infrastructure\Student;
use App\Models\School;
use App\Support\Setup\SetupReadiness;
use App\Support\Setup\SetupReadinessProvider;
use App\Support\Setup\SetupStatus;

/**
 * ORG.12A (ADR 0080 §10): Student records readiness. Two EXISTS answers,
 * never a count of Students (a count of people is Analytics, ADR 0040) and
 * never a Student. Placement uses the Data Health check's canonical
 * current-placement rule rather than a copy of it.
 */
class StudentRecordsSetupReadiness implements SetupReadinessProvider
{
    public function __construct(private readonly ActiveStudentWithoutCurrentPlacementCheck $unplaced) {}

    public function keys(): array
    {
        return ['students.student_records'];
    }

    public function evaluate(string $key, School $school): SetupReadiness
    {
        if (! Student::query()->where('school_id', $school->id)->where('status', 'active')->exists()) {
            return new SetupReadiness(SetupStatus::NOT_CONFIGURED, 'No active Students are on record yet.', 0, 2);
        }

        return $this->unplaced->hasFindings($school)
            ? new SetupReadiness(SetupStatus::ATTENTION_REQUIRED, 'Some active Students have no class placement in the active year. See Data Health.', 1, 2)
            : new SetupReadiness(SetupStatus::CONFIGURED, 'Active Students are on record and placed in the active year.', 2, 2);
    }
}

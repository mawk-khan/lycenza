<?php

namespace App\Domain\AcademicStructure\Application\Setup;

use App\Domain\AcademicStructure\Application\CurrentAcademicYearResolver;
use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\GradeLevel;
use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\AcademicStructure\Infrastructure\Subject;
use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use App\Models\School;
use App\Support\Setup\SetupReadiness;
use App\Support\Setup\SetupReadinessProvider;
use App\Support\Setup\SetupStatus;
use App\Support\Tenancy\SchoolTimezone;

/**
 * ORG.12A (ADR 0080 §10): academic-year and structure readiness, answered
 * by Academic Structure over its own tables. The active year comes from
 * CurrentAcademicYearResolver (the one authoritative resolver); "today" is
 * the School-local date.
 */
class AcademicSetupReadiness implements SetupReadinessProvider
{
    public function __construct(private readonly CurrentAcademicYearResolver $years) {}

    public function keys(): array
    {
        return ['academic.academic_year', 'academic.structure'];
    }

    public function evaluate(string $key, School $school): SetupReadiness
    {
        return $key === 'academic.academic_year' ? $this->year($school) : $this->structure($school);
    }

    private function year(School $school): SetupReadiness
    {
        $active = $this->years->tryResolve($school);
        if ($active !== null) {
            return $active->ends_on->toDateString() < SchoolTimezone::today($school)
                ? new SetupReadiness(SetupStatus::ATTENTION_REQUIRED, 'The active academic year has ended. Activate the next year.', 1, 2)
                : new SetupReadiness(SetupStatus::CONFIGURED, 'An academic year is active and covers today.', 2, 2);
        }

        return AcademicYear::query()->where('school_id', $school->id)->where('status', 'draft')->exists()
            ? new SetupReadiness(SetupStatus::PARTIALLY_CONFIGURED, 'A draft academic year exists. Activate it when it begins.', 0, 2)
            : new SetupReadiness(SetupStatus::NOT_CONFIGURED, 'Create and activate an academic year.', 0, 2);
    }

    private function structure(School $school): SetupReadiness
    {
        $yearId = $this->years->tryResolve($school)?->id;
        $inActiveYear = fn (string $model): bool => $yearId !== null
            && $model::query()->where('school_id', $school->id)->where('academic_year_id', $yearId)->where('status', 'active')->exists();

        return SetupReadiness::fromChecks(
            [
                'grade_levels' => GradeLevel::query()->where('school_id', $school->id)->where('status', 'active')->exists(),
                'subjects' => Subject::query()->where('school_id', $school->id)->where('status', 'active')->exists(),
                'sections' => $inActiveYear(Section::class),
                'subject_offerings' => $inActiveYear(SubjectOffering::class),
            ],
            configured: 'Grade levels, subjects, and sections and subject offerings for the active year are set up.',
            incomplete: 'Set up grade levels and subjects, then sections and subject offerings for the active year.',
        );
    }
}

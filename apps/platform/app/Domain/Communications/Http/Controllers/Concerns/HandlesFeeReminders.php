<?php

namespace App\Domain\Communications\Http\Controllers\Concerns;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\GradeLevel;
use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\Communications\Application\Audience\FeeReminder\FeeReminderAudience;
use App\Domain\Communications\Application\Audience\FeeReminder\FeeReminderSelection;
use App\Models\Campus;
use App\Models\School;
use App\Support\Tenancy\SchoolTimezone;
use Carbon\CarbonImmutable;
use Illuminate\Validation\Rule;

/**
 * ORG.9D-2 (ADR 0076 §74, PV1-11): the HTTP shape of a fee reminder's
 * Finance selection -- the Outstanding fees page's own filters, validated
 * against THIS School (every id must be one of its rows) and turned into a
 * FeeReminderSelection. Shape only: whether the actor may see those
 * balances is the port's decision (FeeReminderBalanceSource), never this.
 */
trait HandlesFeeReminders
{
    /** The value of `campus_id` that means "whole-School (unattributed) Charges", as on the Outstanding fees page. */
    private const string FEE_REMINDER_CAMPUS_UNATTRIBUTED = 'unavailable';

    /** @return array<string, list<mixed>> */
    private function feeReminderRules(School $school, string $prefix = ''): array
    {
        return [
            "{$prefix}status" => ['sometimes', 'nullable', Rule::in([FeeReminderSelection::OUTSTANDING, FeeReminderSelection::OVERDUE])],
            "{$prefix}academic_year_id" => ['sometimes', 'nullable', 'uuid', Rule::exists('academic_years', 'id')->where('school_id', $school->id)],
            "{$prefix}campus_id" => ['sometimes', 'nullable', 'string', Rule::in([self::FEE_REMINDER_CAMPUS_UNATTRIBUTED, ...Campus::query()->where('school_id', $school->id)->pluck('id')->map(fn ($id) => (string) $id)->all()])],
            "{$prefix}grade_level_id" => ['sometimes', 'nullable', 'uuid', Rule::exists('grade_levels', 'id')->where('school_id', $school->id)],
            "{$prefix}section_id" => ['sometimes', 'nullable', 'uuid', Rule::exists('sections', 'id')->where('school_id', $school->id), "prohibits:{$prefix}grade_level_id"],
        ];
    }

    /** @param  array<string, mixed>  $input */
    private function feeReminderSelectionFrom(array $input): FeeReminderSelection
    {
        $campus = $input['campus_id'] ?? null;

        return new FeeReminderSelection(
            balanceStatus: ($input['status'] ?? null) ?: FeeReminderSelection::OUTSTANDING,
            academicYearId: ($input['academic_year_id'] ?? null) ?: null,
            campusId: $campus !== null && $campus !== '' && $campus !== self::FEE_REMINDER_CAMPUS_UNATTRIBUTED ? $campus : null,
            campusUnattributed: $campus === self::FEE_REMINDER_CAMPUS_UNATTRIBUTED,
            gradeLevelId: ($input['grade_level_id'] ?? null) ?: null,
            sectionId: ($input['section_id'] ?? null) ?: null,
        );
    }

    /**
     * The selection as the composer submits it and as people read it --
     * names of this School's own rows only, never a Student or an amount.
     *
     * @return array{fields: array<string, string>, labels: array{status: string, academicYear: string, campus: string, class: string}}
     */
    private function presentFeeReminderSelection(FeeReminderSelection $selection): array
    {
        $section = $selection->sectionId === null ? null : Section::query()->with('gradeLevel:id,name')->find($selection->sectionId);

        return [
            'fields' => [
                'status' => $selection->balanceStatus,
                'academic_year_id' => $selection->academicYearId ?? '',
                'campus_id' => $selection->campusUnattributed ? self::FEE_REMINDER_CAMPUS_UNATTRIBUTED : ($selection->campusId ?? ''),
                'grade_level_id' => $selection->gradeLevelId ?? '',
                'section_id' => $selection->sectionId ?? '',
            ],
            'labels' => [
                'status' => $selection->balanceStatus === FeeReminderSelection::OVERDUE ? 'Students with overdue fees' : 'Students with outstanding fees',
                'academicYear' => $selection->academicYearId === null ? 'All years' : (string) AcademicYear::query()->whereKey($selection->academicYearId)->value('name'),
                'campus' => match (true) {
                    $selection->campusUnattributed => 'Whole-School fees (no Campus)',
                    $selection->campusId !== null => (string) Campus::query()->whereKey($selection->campusId)->value('name'),
                    default => 'All Campuses you can see',
                },
                'class' => match (true) {
                    $section !== null => trim("{$section->gradeLevel?->name} · {$section->name}"),
                    $selection->gradeLevelId !== null => (string) GradeLevel::query()->whereKey($selection->gradeLevelId)->value('name'),
                    default => 'All classes',
                },
            ],
        ];
    }

    /** @return array{students: int, studentsWithoutRecipient: int, totalOutstanding: string, currency: string, asOf: string} */
    private function presentFeeReminderPreview(FeeReminderAudience $audience, School $school): array
    {
        return [
            'students' => $audience->studentCount,
            'studentsWithoutRecipient' => $audience->studentsWithoutRecipient,
            'totalOutstanding' => $audience->totalOutstanding,
            'currency' => $audience->currency,
            'asOf' => CarbonImmutable::instance($audience->asOf)->setTimezone(SchoolTimezone::resolve($school))->format('j M Y, g:i A'),
        ];
    }
}

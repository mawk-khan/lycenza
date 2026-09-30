<?php

namespace App\Domain\LMS\Application\Ownership;

use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use App\Domain\LMS\Application\Exceptions\LmsAudienceSectionOutsideOfferingException;
use App\Domain\LMS\Application\Exceptions\LmsOwnerEmployeeInvalidException;
use App\Domain\LMS\Infrastructure\Assignment;
use App\Domain\LMS\Infrastructure\AssignmentSectionAudience;
use App\Domain\LMS\Infrastructure\LearningContent;
use App\Domain\LMS\Infrastructure\LearningContentSectionAudience;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * TCH.5B (ADR 0063 section 35) -- writes the Section audience of a
 * teacher-owned resource, in the SAME transaction that inserted the owned
 * parent row. The database refuses an audience written in any later
 * transaction, on an unowned row, or naming a Section outside the parent's
 * Offering context; this class answers the context case cleanly first
 * (active Sections of the Offering's AcademicYear, Campus and GradeLevel).
 *
 * Internal to LMS: called only by LearningContentService/AssignmentService
 * when they are handed a SectionAudience.
 */
class SectionAudienceWriter
{
    public function attach(LearningContent|Assignment $resource, SubjectOffering $offering, SectionAudience $audience): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('A Section audience must be written inside the transaction that creates its resource.');
        }

        $matching = Section::query()
            ->where('school_id', $offering->school_id)
            ->where('academic_year_id', $offering->academic_year_id)
            ->where('campus_id', $offering->campus_id)
            ->where('grade_level_id', $offering->grade_level_id)
            ->where('status', 'active')
            ->whereIn('id', $audience->sectionIds)
            ->pluck('id')
            ->all();

        if (count($matching) !== count($audience->sectionIds)) {
            throw new LmsAudienceSectionOutsideOfferingException;
        }

        foreach ($audience->sectionIds as $sectionId) {
            $row = $resource instanceof LearningContent ? new LearningContentSectionAudience : new AssignmentSectionAudience;
            $row->forceFill([
                'school_id' => $resource->school_id,
                $resource instanceof LearningContent ? 'learning_content_id' : 'assignment_id' => $resource->id,
                'subject_offering_id' => $offering->id,
                'academic_year_id' => $offering->academic_year_id,
                'campus_id' => $offering->campus_id,
                'grade_level_id' => $offering->grade_level_id,
                'section_id' => $sectionId,
            ])->save();
        }
    }

    /**
     * Runs the creating transaction, translating the database's refusal of
     * an owner Employee from another School (the composite
     * `<table>_owner_employee_fk`) into the LMS exception.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function refusingForeignOwner(string $table, callable $callback): mixed
    {
        try {
            return $callback();
        } catch (QueryException $e) {
            if ($e->getCode() === '23503' && str_contains($e->getMessage(), "{$table}_owner_employee_fk")) {
                throw new LmsOwnerEmployeeInvalidException;
            }

            throw $e;
        }
    }
}

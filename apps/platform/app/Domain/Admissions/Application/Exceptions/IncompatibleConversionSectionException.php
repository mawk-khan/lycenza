<?php

namespace App\Domain\Admissions\Application\Exceptions;

/**
 * Phase 1D.3: the Section chosen at conversion time must represent the
 * SAME School/AcademicYear/Campus/GradeLevel the accepted
 * AdmissionApplication already committed to (`docs/modules/
 * ADMISSIONS.md` §5 -- an application commits to AcademicYear + Campus
 * + GradeLevel only, Section is the conversion-time decision).
 * `App\Domain\Students\Application\StudentEnrollmentService::enroll()`
 * has no concept of "the Admission Application's intended academic
 * context" -- it derives academic_year_id/campus_id/grade_level_id
 * purely from whatever Section it is given, so THIS conversion
 * boundary is the only place that can catch a Section whose academic
 * context silently disagrees with the application's. Checked BEFORE
 * any canonical record is created (root CLAUDE.md rule 10's ordering
 * discipline, applied here to avoid doing real work for an obviously
 * incompatible reference), mirroring
 * App\Domain\Students\Application\Exceptions\IncompatibleSubjectOfferingException's
 * identical "one exception, several compatibility dimensions" shape.
 */
class IncompatibleConversionSectionException extends AdmissionsException
{
    public function __construct()
    {
        parent::__construct(
            422,
            'INCOMPATIBLE_CONVERSION_SECTION',
            'The selected Section does not match this Admission Application\'s School, Academic Year, Campus, and Grade Level.',
        );
    }
}

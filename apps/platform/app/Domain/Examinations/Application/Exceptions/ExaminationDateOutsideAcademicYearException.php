<?php

namespace App\Domain\Examinations\Application\Exceptions;

/**
 * An examination window must fall inside its AcademicYear's inclusive
 * [starts_on, ends_on]. A School cannot hold a 2026-27 examination
 * before that year begins or after it ends.
 *
 * The AcademicYear is NOT required to be currently active: defining a
 * future examination inside a `draft` year is legitimate and expected
 * planning work. This is deliberately unlike Curriculum Delivery's
 * active-year-on-create rule, which exists because a delivery records
 * what has already happened.
 */
class ExaminationDateOutsideAcademicYearException extends ExaminationException
{
    public function __construct(public readonly string $field, public readonly string $date)
    {
        parent::__construct(422, 'EXAMINATION_DATE_OUTSIDE_ACADEMIC_YEAR', "The {$field} date {$date} falls outside this AcademicYear.");
    }
}

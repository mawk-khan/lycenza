<?php

namespace App\Domain\Examinations\Application\Exceptions;

/**
 * RES.2 (ADR 0068 §6, §19.2 #12): a closed AcademicYear refuses ordinary marks entry (§7.4); corrections are RES.3. The message is fixed text: it never
 * carries a mark value, a Student name or a database message.
 */
class StudentMarkAcademicYearClosedException extends ExaminationException
{
    public function __construct(public readonly ?string $studentId = null)
    {
        parent::__construct(409, 'STUDENT_MARK_ACADEMIC_YEAR_CLOSED', 'The academic year is closed; marks cannot be entered or changed.');
    }
}

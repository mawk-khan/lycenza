<?php

namespace App\Domain\Students\Application;

/**
 * Phase 0H.2: one row of an as-of-date Section roster, projected
 * DELIBERATELY MINIMALLY -- exactly what Attendance operationally
 * needs to identify a Student on a register and nothing else.
 *
 * There is no date of birth, no Guardian data, no phone/email, no
 * address, no admission/enrollment history and no medical field here,
 * and none may be added: Attendance is Sensitive-tier
 * (docs/security/DATA-CLASSIFICATION.md) and every consumer of this
 * DTO renders it to an administrative Attendance surface. `fullName`
 * is composed server-side from the Student's name parts purely as a
 * display convenience -- `students` has no `full_name` column.
 */
final readonly class SectionRosterMember
{
    public function __construct(
        public string $studentEnrollmentId,
        public string $studentId,
        public string $rollNumber,
        public string $fullName,
    ) {}

    /**
     * @return array{studentEnrollmentId: string, studentId: string, rollNumber: string, fullName: string}
     */
    public function toArray(): array
    {
        return [
            'studentEnrollmentId' => $this->studentEnrollmentId,
            'studentId' => $this->studentId,
            'rollNumber' => $this->rollNumber,
            'fullName' => $this->fullName,
        ];
    }
}

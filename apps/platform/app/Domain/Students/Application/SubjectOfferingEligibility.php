<?php

namespace App\Domain\Students\Application;

/**
 * RES.1 (ADR 0068 §5, "P3"): the answer to "was this Student eligible to be
 * assessed in this SubjectOffering on this date, and on which placement?"
 * -- produced only by SubjectOfferingEligibilityReadService.
 *
 * Either ELIGIBLE, carrying its source (`required` / `elective`), the
 * qualifying placement (`student_enrollment_id`) with its Section, and for an
 * elective the qualifying `student_subject_enrollments` row; or NOT
 * ELIGIBLE, carrying one closed reason. Never a bare boolean: a future
 * StudentMark snapshots the placement as its provenance (ADR 0068 §6).
 *
 * Deliberately minimal (ids only): no name, date of birth, roll number or
 * other Student data, so the answer adds nothing to any caller's tier.
 */
final readonly class SubjectOfferingEligibility
{
    public const SOURCE_REQUIRED = 'required';

    public const SOURCE_ELECTIVE = 'elective';

    /** The Student is not a Student of the requesting School (or does not exist). */
    public const STUDENT_NOT_FOUND = 'student_not_found';

    /** The SubjectOffering is not an Offering of the requesting School (or does not exist). */
    public const OFFERING_NOT_FOUND = 'offering_not_found';

    /** The date falls outside the Offering's AcademicYear (inclusive bounds). */
    public const OUTSIDE_ACADEMIC_YEAR = 'outside_academic_year';

    /** No placement of the Student covers the date in the Offering's year, campus and grade. */
    public const NO_PLACEMENT = 'no_placement';

    /** An elective: no subject-enrollment row of the Student for this Offering covers the date. */
    public const NO_ELECTIVE_ENROLLMENT = 'no_elective_enrollment';

    /** An elective row covering the date has no placement anchor (legacy data): history cannot be established. */
    public const ELECTIVE_UNANCHORED = 'elective_unanchored';

    /** More than one placement or elective row covers the date: one authoritative answer does not exist. */
    public const AMBIGUOUS_HISTORY = 'ambiguous_history';

    /** The rows relied on disagree with each other (context, year or anchor): corrupt or inconsistent data. */
    public const INCONSISTENT_RECORD = 'inconsistent_record';

    public const REASONS = [
        self::STUDENT_NOT_FOUND, self::OFFERING_NOT_FOUND, self::OUTSIDE_ACADEMIC_YEAR, self::NO_PLACEMENT,
        self::NO_ELECTIVE_ENROLLMENT, self::ELECTIVE_UNANCHORED, self::AMBIGUOUS_HISTORY, self::INCONSISTENT_RECORD,
    ];

    /** Reasons that signal unusable data rather than a legitimate "not eligible". */
    public const INTEGRITY_REASONS = [self::ELECTIVE_UNANCHORED, self::AMBIGUOUS_HISTORY, self::INCONSISTENT_RECORD];

    private function __construct(
        public bool $eligible,
        public ?string $source,
        public ?string $studentEnrollmentId,
        public ?string $sectionId,
        public ?string $studentSubjectEnrollmentId,
        public ?string $reason,
    ) {}

    public static function required(string $studentEnrollmentId, string $sectionId): self
    {
        return new self(true, self::SOURCE_REQUIRED, $studentEnrollmentId, $sectionId, null, null);
    }

    public static function elective(string $studentEnrollmentId, string $sectionId, string $studentSubjectEnrollmentId): self
    {
        return new self(true, self::SOURCE_ELECTIVE, $studentEnrollmentId, $sectionId, $studentSubjectEnrollmentId, null);
    }

    public static function notEligible(string $reason): self
    {
        return new self(false, null, null, null, null, $reason);
    }

    public function isIntegrityFailure(): bool
    {
        return in_array($this->reason, self::INTEGRITY_REASONS, true);
    }
}

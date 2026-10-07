<?php

namespace App\Domain\Examinations\Application\Marks;

use App\Domain\Students\Application\SubjectOfferingEligibility;
use App\Domain\TeachingAssignments\Application\OwnedElectivePeriod;
use App\Domain\TeachingAssignments\Application\OwnedTeachingPeriod;

/**
 * RES.4 (ADR 0068 §25.4-§25.5): one teacher's ownership periods, for READS
 * and for the paper visibility check -- an unlocked snapshot. Writes decide
 * each Student again under lock (TeachingOwnership::holdOffering()).
 *
 * Ownership is judged on the paper's `scheduled_on` (owner-adopted
 * development rule pending RES-L2): a required Offering by the Student's P3
 * placement Section x the Offering (TeachingAssignment), an elective one
 * Offering-wide (TCH-E). Exactly one covering period counts, as hold() does;
 * co-teachers and short dated cover assignments are ordinary owners.
 */
final readonly class TeacherStudentMarkScope
{
    public const string SOURCE_TEACHING_ASSIGNMENT = 'teaching_assignment';

    public const string SOURCE_ELECTIVE_TEACHING_ASSIGNMENT = 'elective_teaching_assignment';

    /**
     * @param  list<OwnedTeachingPeriod>  $periods
     * @param  list<OwnedElectivePeriod>  $electivePeriods
     */
    public function __construct(
        public string $employeeId,
        private array $periods,
        private array $electivePeriods,
    ) {}

    /** Does the teacher own any part of the Offering on the date (any Section of a required one, or the elective)? */
    public function ownsOffering(string $subjectOfferingId, string $date): bool
    {
        foreach ([...$this->periods, ...$this->electivePeriods] as $period) {
            if ($period->subjectOfferingId === $subjectOfferingId && $period->covers($date)) {
                return true;
            }
        }

        return false;
    }

    /** Does the teacher own this eligible Student for the Offering on the date? */
    public function ownsStudent(string $subjectOfferingId, string $date, SubjectOfferingEligibility $eligibility): bool
    {
        if (! $eligibility->eligible) {
            return false;
        }
        $covering = $eligibility->source === SubjectOfferingEligibility::SOURCE_REQUIRED
            ? array_filter($this->periods, fn (OwnedTeachingPeriod $p) => $p->sectionId === $eligibility->sectionId && $p->subjectOfferingId === $subjectOfferingId && $p->covers($date))
            : array_filter($this->electivePeriods, fn (OwnedElectivePeriod $p) => $p->subjectOfferingId === $subjectOfferingId && $p->covers($date));

        return count($covering) === 1;
    }

    public static function sourceOf(SubjectOfferingEligibility $eligibility): string
    {
        return $eligibility->source === SubjectOfferingEligibility::SOURCE_REQUIRED
            ? self::SOURCE_TEACHING_ASSIGNMENT
            : self::SOURCE_ELECTIVE_TEACHING_ASSIGNMENT;
    }
}

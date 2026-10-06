<?php

namespace App\Domain\Students\Application;

use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Models\School;
use App\Support\Tenancy\TenantContext;

/**
 * RES.2 (ADR 0068 §19.2 #11): the minimal display of given placements --
 * exactly SectionRosterMember's projection (placement id, Student id, roll
 * number, composed display name) and nothing else: no date of birth, Guardian,
 * contact or history. Owned by Students/SIS so a consumer module never reads
 * this module's models (CLAUDE.md rule 4).
 *
 * Authorization-neutral (callers authorize first); ids outside the School are
 * simply absent.
 */
class StudentPlacementDisplayReadService
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * @param  list<string>  $studentEnrollmentIds
     * @return array<string, SectionRosterMember> keyed by placement id
     */
    public function forPlacements(School $school, array $studentEnrollmentIds): array
    {
        if ($studentEnrollmentIds === []) {
            return [];
        }

        return $this->context->withSchool($school, function () use ($school, $studentEnrollmentIds): array {
            $members = [];
            $placements = StudentEnrollment::query()->where('school_id', $school->id)->whereIn('id', $studentEnrollmentIds)
                ->with('student:id,school_id,first_name,middle_name,last_name')->get();
            foreach ($placements as $placement) {
                $student = $placement->student;
                $members[(string) $placement->id] = new SectionRosterMember(
                    studentEnrollmentId: (string) $placement->id,
                    studentId: (string) $placement->student_id,
                    rollNumber: (string) $placement->roll_number,
                    fullName: $student === null ? '' : trim(implode(' ', array_filter(
                        [$student->first_name, $student->middle_name, $student->last_name],
                        fn (?string $part) => $part !== null && $part !== '',
                    ))),
                );
            }

            return $members;
        });
    }
}

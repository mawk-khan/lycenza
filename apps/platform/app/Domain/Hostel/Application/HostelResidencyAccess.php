<?php

namespace App\Domain\Hostel\Application;

use App\Domain\Hostel\Application\Exceptions\HostelResidencyUnavailableException;
use App\Domain\Hostel\Infrastructure\Hostel;
use App\Domain\Hostel\Infrastructure\HostelBed;
use App\Domain\Hostel\Infrastructure\HostelResidencyAssignment;
use App\Domain\Hostel\Infrastructure\HostelRoom;
use App\Domain\Students\Application\StudentLookupCandidate;
use App\Domain\Students\Application\StudentPurposeLookup;
use App\Domain\Students\Application\StudentSearch;
use App\Domain\Students\Infrastructure\Student;
use App\Models\School;
use App\Support\Authorization\Campus\CampusScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;

/**
 * ORG.7D-3 (ADR 0076 §11.7, §39): the ONE place Hostel residency work reads
 * or targets residencies, beds and resident candidates under the actor's
 * CampusScope (`hostel.residency.view`/`.manage` are Campus-adopted; the
 * Hostel/Room/Bed directory `hostel.directory.*` stays whole-School).
 *
 * - Authority source: the Hostel's Campus (`hostels.campus_id` NOT NULL);
 *   a Room, Bed and residency reach it through their composite foreign
 *   keys. A Hostel's Campus never changes once it has Rooms, so a
 *   residency keeps the Campus it was recorded on.
 * - Candidates: for a Campus-scoped actor, StudentPurposeLookup with the
 *   actor's scope -- current operational Students only; a whole-School
 *   actor keeps the existing picker. id/displayName/studentNumber either way.
 * - v1 rule for a Campus-scoped actor: the Student's CURRENT operational
 *   Campus must be the Hostel's Campus, re-checked inside the write
 *   (HostelResidencyCampusGuard). No cross-Campus boarding from a Campus
 *   grant; whole-School holders are unchanged.
 * - Out of scope is NOT FOUND (404), like another School's record.
 */
final class HostelResidencyAccess
{
    public function __construct(private readonly StudentPurposeLookup $students) {}

    /** @return Builder<HostelResidencyAssignment> */
    public function residencies(CampusScope $scope): Builder
    {
        return $scope->isWholeSchool()
            ? HostelResidencyAssignment::query()
            : HostelResidencyAssignment::query()->whereIn('hostel_bed_id', $this->beds($scope)->select('hostel_beds.id'));
    }

    public function residency(CampusScope $scope, string $id): HostelResidencyAssignment
    {
        $found = Str::isUuid($id) ? $this->residencies($scope)->find($id) : null;

        return $found ?? throw (new ModelNotFoundException)->setModel(HostelResidencyAssignment::class, [$id]);
    }

    /** @return Builder<HostelBed> */
    public function beds(CampusScope $scope): Builder
    {
        return $scope->isWholeSchool()
            ? HostelBed::query()
            : HostelBed::query()->whereIn('hostel_room_id', HostelRoom::query()->whereIn('hostel_id', $scope->restrict(Hostel::query())->select('id'))->select('id'));
    }

    public function bed(CampusScope $scope, string $id): HostelBed
    {
        $found = Str::isUuid($id) ? $this->beds($scope)->find($id) : null;

        return $found ?? throw (new ModelNotFoundException)->setModel(HostelBed::class, [$id]);
    }

    /**
     * The Student a residency is requested for. A whole-School actor gets the
     * existing answer (unknown = NOT FOUND); a Campus-scoped actor gets the
     * same generic refusal for an unknown Student as for any ineligible one,
     * so the id never confirms that a Student exists elsewhere.
     */
    public function residentStudent(CampusScope $scope, string $id): Student
    {
        $student = Str::isUuid($id) ? Student::query()->find($id) : null;
        if ($student !== null) {
            return $student;
        }

        throw $scope->isWholeSchool()
            ? (new ModelNotFoundException)->setModel(Student::class, [$id])
            : new HostelResidencyUnavailableException;
    }

    /**
     * The resident picker. A whole-School actor keeps the existing picker
     * (any active Student of the School); a Campus-scoped actor gets the
     * Students-owned purpose lookup -- current operational Students of their
     * Campuses only. Both answer id, displayName and studentNumber.
     *
     * @return list<StudentLookupCandidate>
     */
    public function residentCandidates(School $school, CampusScope $scope, ?string $term): array
    {
        if (! $scope->isWholeSchool()) {
            return $this->students->search($school, $scope, $term, 10);
        }

        return Student::query()
            ->where('status', 'active')
            ->when(trim((string) $term) !== '', fn ($q) => StudentSearch::anyField($q, (string) $term))
            ->limit(10)
            ->get()
            ->map(fn (Student $s) => new StudentLookupCandidate((string) $s->id, trim($s->first_name.' '.$s->last_name), $s->student_number))
            ->values()->all();
    }

    /** The write-time Campus check; none for a whole-School actor (unchanged path). */
    public function writeGuard(School $school, CampusScope $scope): ?HostelResidencyCampusGuard
    {
        return $scope->isWholeSchool() ? null : new HostelResidencyCampusGuard($school, $scope);
    }
}

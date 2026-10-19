<?php

namespace App\Domain\Library\Application;

use App\Domain\Library\Application\Exceptions\LibraryCheckoutUnavailableException;
use App\Domain\Library\Infrastructure\LibraryCopy;
use App\Domain\Library\Infrastructure\LibraryLoan;
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
 * ORG.7D-5 (ADR 0076 §41): the ONE place Library circulation reads or targets
 * loans, lendable Copies and borrower candidates under the actor's
 * CampusScope (`library.circulation.view`/`.manage` are Campus-adopted; the
 * catalogue `library.catalogue.*` and fines `library.fines.*` stay
 * whole-School).
 *
 * - A loan's authority is its own frozen `library_loans.campus_id` -- the
 *   Copy's Campus at checkout -- never the borrower's current Campus and
 *   never the Copy's later Campus; history follows it.
 * - A lendable Copy's authority is its `library_copies.campus_id`.
 * - A NULL-Campus (School-shared) Copy or loan is whole-School only.
 * - Candidates: for a Campus-scoped actor, StudentPurposeLookup with the
 *   actor's scope (current operational Students only); a whole-School actor
 *   keeps the existing picker. id/displayName/studentNumber either way.
 * - Out of scope is NOT FOUND (404), like another School's record.
 */
final class LibraryCirculationAccess
{
    public function __construct(private readonly StudentPurposeLookup $students) {}

    /** @return Builder<LibraryLoan> */
    public function loans(CampusScope $scope): Builder
    {
        /** @var Builder<LibraryLoan> */
        return $scope->restrict(LibraryLoan::query(), 'library_loans.campus_id');
    }

    public function loan(CampusScope $scope, string $id): LibraryLoan
    {
        $found = Str::isUuid($id) ? $this->loans($scope)->find($id) : null;

        return $found ?? throw (new ModelNotFoundException)->setModel(LibraryLoan::class, [$id]);
    }

    /** @return Builder<LibraryCopy> */
    public function copies(CampusScope $scope): Builder
    {
        /** @var Builder<LibraryCopy> */
        return $scope->restrict(LibraryCopy::query(), 'library_copies.campus_id');
    }

    public function copy(CampusScope $scope, string $id): LibraryCopy
    {
        $found = Str::isUuid($id) ? $this->copies($scope)->find($id) : null;

        return $found ?? throw (new ModelNotFoundException)->setModel(LibraryCopy::class, [$id]);
    }

    /**
     * The borrower a checkout names. A whole-School actor gets the existing
     * answer (unknown = NOT FOUND); a Campus-scoped actor gets the same
     * generic refusal for an unknown Student as for any ineligible one.
     */
    public function borrower(CampusScope $scope, string $id): Student
    {
        $student = Str::isUuid($id) ? Student::query()->find($id) : null;
        if ($student !== null) {
            return $student;
        }

        throw $scope->isWholeSchool()
            ? (new ModelNotFoundException)->setModel(Student::class, [$id])
            : new LibraryCheckoutUnavailableException;
    }

    /**
     * The borrower picker: the existing School-wide active-Student search for
     * a whole-School actor; the Students-owned purpose lookup (current
     * operational Students of their Campuses) for a Campus-scoped actor.
     *
     * @return list<StudentLookupCandidate>
     */
    public function borrowerCandidates(School $school, CampusScope $scope, ?string $term): array
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
    public function writeGuard(School $school, CampusScope $scope): ?LibraryCirculationCampusGuard
    {
        return $scope->isWholeSchool() ? null : new LibraryCirculationCampusGuard($school, $scope);
    }
}

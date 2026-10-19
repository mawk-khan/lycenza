<?php

namespace App\Domain\Library\Application;

use App\Domain\Campuses\Application\ActiveCampus;
use App\Domain\Library\Application\Exceptions\LibraryCheckoutUnavailableException;
use App\Domain\Library\Infrastructure\LibraryCopy;
use App\Domain\Library\Infrastructure\LibraryLoan;
use App\Domain\Students\Application\StudentOperationalEligibility;
use App\Models\School;
use App\Support\Authorization\Campus\CampusScope;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * ORG.7D-5 (ADR 0076 §41): the Campus-scoped librarian's write-time checks,
 * run by LibraryLoanService inside its transaction.
 *
 * - Checkout (after the Copy row lock): the Copy's Campus must be set and in
 *   scope, and the borrower must be a current operational Student OF THAT
 *   Campus, proven under lock (StudentOperationalEligibility::lockOperationalOn(),
 *   which also share-locks the Campus as active). No cross-Campus lending:
 *   every refusal is the one generic LIBRARY_CHECKOUT_UNAVAILABLE.
 * - Check-in: the loan's frozen Campus must be in scope and still active
 *   (ActiveCampus share lock), else NOT FOUND -- never the borrower's or the
 *   Copy's current Campus. No cross-Campus return.
 *
 * Built only by LibraryCirculationAccess, from a resolved CampusScope.
 */
final class LibraryCirculationCampusGuard
{
    public function __construct(private readonly School $school, private readonly CampusScope $scope) {}

    public function beforeCheckout(LibraryCopy $lockedCopy, string $studentId): void
    {
        $campusId = $lockedCopy->campus_id;
        if (! is_string($campusId) || ! $this->scope->covers($campusId)
            || ! app(StudentOperationalEligibility::class)->lockOperationalOn($this->school, $studentId, $campusId)) {
            throw new LibraryCheckoutUnavailableException;
        }
    }

    public function beforeCheckIn(LibraryLoan $loan): void
    {
        $campusId = $loan->campus_id;
        if (! is_string($campusId) || ! $this->scope->covers($campusId) || app(ActiveCampus::class)->find($this->school, $campusId) === null) {
            throw (new ModelNotFoundException)->setModel(LibraryLoan::class, [$loan->id]);
        }
    }
}

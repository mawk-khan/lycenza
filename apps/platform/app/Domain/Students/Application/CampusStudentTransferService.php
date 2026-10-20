<?php

namespace App\Domain\Students\Application;

use App\Domain\Students\Application\Exceptions\CrossAcademicYearTransferException;
use App\Domain\Students\Application\Exceptions\CrossSchoolEnrollmentException;
use App\Domain\Students\Application\Exceptions\IntraYearGradeChangeException;
use App\Domain\Students\Application\Exceptions\InvalidEnrollmentDateRangeException;
use App\Domain\Students\Application\Exceptions\InvalidEnrollmentTransitionException;
use App\Domain\Students\Application\Exceptions\PlacementTargetUnavailableException;
use App\Domain\Students\Application\Exceptions\StudentTransferUnavailableException;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Models\School;
use App\Models\User;
use App\Support\Authorization\Campus\CampusScope;
use App\Support\Tenancy\SchoolTimezone;
use App\Support\Tenancy\TenantContext;
use Illuminate\Container\Attributes\Tag;
use Illuminate\Support\Facades\DB;

/**
 * ORG.7F-3 (ADR 0076 §47): THE Campus-scoped Student transfer -- the named
 * StudentEnrollmentService::transferPlacement() (ends the source placement,
 * creates the destination one; never a placement UPDATE), run for a Campus
 * actor with:
 *
 * - StudentAccess::lockedTransferTarget(): active year FOR SHARE -> Student FOR
 *   SHARE -> the CURRENT placement, its Campus in scope (else NOT FOUND);
 * - StudentTransferCampusGuard under the transfer's row locks: source AND
 *   destination Campus in scope and different, the active year, and no
 *   StudentTransferBlocker (Hostel residency, Transport rider, elective);
 * - the F-2 rule (destination Section and Campus active);
 * - effective today (School-local): a Campus transfer never backdates or
 *   schedules history.
 *
 * Every refusal on an in-scope Student is the one generic
 * StudentTransferUnavailableException; a taken roll number in the in-scope
 * destination Section is ordinary feedback. Nothing is moved, ended or
 * rewritten in Hostel, Transport, electives, Library, Canteen, Finance or
 * academic history (owner decisions F3-D6 to F3-D8, F3-D12).
 */
final class CampusStudentTransferService
{
    /** @param  iterable<StudentTransferBlocker>  $blockers */
    public function __construct(
        private readonly StudentAccess $access,
        private readonly StudentEnrollmentService $enrollments,
        private readonly TenantContext $context,
        #[Tag(StudentTransferBlocker::TAG)] private readonly iterable $blockers = [],
    ) {}

    /** @param  string|null  $expectedSourceId  the placement an API caller named (refused unless it is still the current one) */
    public function transfer(School $school, CampusScope $scope, string $studentId, string $targetSectionId, string $rollNumber, ?User $actor = null, ?string $expectedSourceId = null): StudentEnrollment
    {
        return $this->context->withSchool($school, fn () => DB::transaction(function () use ($school, $scope, $studentId, $targetSectionId, $rollNumber, $actor, $expectedSourceId): StudentEnrollment {
            $target = $this->access->lockedTransferTarget($school, $scope, $studentId, $targetSectionId, $expectedSourceId);
            if ($target['target'] === null) {
                throw new StudentTransferUnavailableException;
            }

            try {
                return $this->enrollments->transferPlacement(
                    $target['source'],
                    $target['target'],
                    $rollNumber,
                    SchoolTimezone::today($school),
                    $actor,
                    new StudentTransferCampusGuard($school, $scope, $target['activeYearId'], $this->blockers),
                );
            } catch (PlacementTargetUnavailableException|CrossAcademicYearTransferException|IntraYearGradeChangeException|CrossSchoolEnrollmentException|InvalidEnrollmentTransitionException|InvalidEnrollmentDateRangeException) {
                throw new StudentTransferUnavailableException;
            }
        }));
    }
}

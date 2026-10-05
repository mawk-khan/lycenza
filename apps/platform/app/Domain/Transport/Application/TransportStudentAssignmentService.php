<?php

namespace App\Domain\Transport\Application;

use App\Domain\Students\Infrastructure\Student;
use App\Domain\Transport\Application\Exceptions\ConcurrentStudentAssignmentConflictException;
use App\Domain\Transport\Application\Exceptions\RouteNotAvailableException;
use App\Domain\Transport\Application\Exceptions\StopNotOnRouteException;
use App\Domain\Transport\Application\Exceptions\StudentAlreadyAssignedException;
use App\Domain\Transport\Application\Exceptions\StudentAssignmentAlreadyEndedException;
use App\Domain\Transport\Application\Exceptions\StudentNotEligibleException;
use App\Domain\Transport\Infrastructure\TransportRoute;
use App\Domain\Transport\Infrastructure\TransportStop;
use App\Domain\Transport\Infrastructure\TransportStudentAssignment;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The only sanctioned write path for a Student's Transport assignment
 * (docs/modules/TRANSPORT.md "Student assignment model"). `assign()`
 * REJECTS if the Student already has an active assignment -- the
 * caller must explicitly end() the current one first -- mirroring
 * LibraryLoanService::checkout()'s explicit-action-required precedent,
 * not TransportRouteAssignmentService::assign()'s auto-replace
 * precedent above: a Student's Transport assignment is a discrete fact
 * worth an explicit transition, not administrative housekeeping.
 */
class TransportStudentAssignmentService
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly TransportFeeSelectionService $fees,
    ) {}

    /**
     * THE core invariant (checkpoint brief section 15): a Student must
     * never have two simultaneous active Transport assignments.
     * `lockForUpdate()` on the Student row itself serializes any two
     * concurrent assign() calls for the SAME Student before either
     * reaches the "already assigned" check -- the exact
     * LibraryLoanService::checkout() locking discipline applied to
     * Student instead of Copy. The database's partial unique index
     * (`transport_student_assignments_one_active_per_student`) remains
     * the authoritative, always-on backstop regardless of this
     * method's own locking -- proven under real two-process
     * concurrency in
     * tests/Feature/Transport/TransportStudentAssignmentConcurrencyTest.php.
     */
    public function assign(
        Student $student,
        TransportRoute $route,
        ?TransportStop $pickupStop,
        ?TransportStop $dropoffStop,
        ?User $actor = null,
    ): TransportStudentAssignment {
        if (! $student->isActive()) {
            throw new StudentNotEligibleException;
        }

        if (! $route->isActive()) {
            throw new RouteNotAvailableException;
        }

        if ($pickupStop !== null && $pickupStop->route_id !== $route->id) {
            throw new StopNotOnRouteException;
        }

        if ($dropoffStop !== null && $dropoffStop->route_id !== $route->id) {
            throw new StopNotOnRouteException;
        }

        try {
            return DB::transaction(function () use ($student, $route, $pickupStop, $dropoffStop, $actor) {
                $lockedStudent = Student::query()
                    ->where('id', $student->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if (
                    TransportStudentAssignment::query()
                        ->where('student_id', $lockedStudent->id)
                        ->where('status', 'active')
                        ->exists()
                ) {
                    throw new StudentAlreadyAssignedException;
                }

                $assignment = TransportStudentAssignment::query()->create([
                    'school_id' => $route->school_id,
                    'student_id' => $student->id,
                    'route_id' => $route->id,
                    'pickup_stop_id' => $pickupStop?->id,
                    'dropoff_stop_id' => $dropoffStop?->id,
                    'status' => 'active',
                    'starts_on' => now(),
                    'ends_on' => null,
                ]);

                $this->audit->school($route->school, 'transport.student_assignment.assigned', actor: $actor, subject: $assignment, metadata: [
                    'studentId' => $student->id,
                    'transportRouteId' => $route->id,
                    'pickupStopId' => $pickupStop?->id,
                    'dropoffStopId' => $dropoffStop?->id,
                ]);

                // OPF.1 (ADR 0067 §14): Transport fee intent for the active year, in this same transaction.
                $this->fees->recordForNewAssignment($assignment, $actor);

                return $assignment;
            });
        } catch (UniqueConstraintViolationException) {
            throw new ConcurrentStudentAssignmentConflictException;
        }
    }

    /**
     * A conditional `UPDATE ... WHERE status = 'active'` (not a blind
     * `$assignment->update(...)`), mirroring
     * LibraryLoanService::checkIn()'s same discipline.
     */
    public function end(TransportStudentAssignment $assignment, ?User $actor = null): TransportStudentAssignment
    {
        return DB::transaction(function () use ($assignment, $actor) {
            $affected = TransportStudentAssignment::query()
                ->where('id', $assignment->id)
                ->where('status', 'active')
                ->update(['status' => 'ended', 'ends_on' => now()]);

            if ($affected === 0) {
                throw new StudentAssignmentAlreadyEndedException;
            }

            $fresh = $assignment->refresh();

            $this->audit->school($assignment->school, 'transport.student_assignment.ended', actor: $actor, subject: $fresh, metadata: [
                'studentId' => $assignment->student_id,
                'transportRouteId' => $assignment->route_id,
            ]);

            // OPF.1 (ADR 0067 D5): future fee intent only; an assessed charge is never touched.
            $this->fees->withdrawForEndedAssignment($fresh, $actor);

            return $fresh;
        });
    }
}

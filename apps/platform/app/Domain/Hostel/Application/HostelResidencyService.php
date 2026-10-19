<?php

namespace App\Domain\Hostel\Application;

use App\Domain\Hostel\Application\Exceptions\BedAlreadyOccupiedException;
use App\Domain\Hostel\Application\Exceptions\BedNotAvailableException;
use App\Domain\Hostel\Application\Exceptions\ConcurrentResidencyConflictException;
use App\Domain\Hostel\Application\Exceptions\HostelResidencyUnavailableException;
use App\Domain\Hostel\Application\Exceptions\ResidencyAlreadyEndedException;
use App\Domain\Hostel\Application\Exceptions\StudentAlreadyResidentException;
use App\Domain\Hostel\Application\Exceptions\StudentNotEligibleException;
use App\Domain\Hostel\Infrastructure\HostelBed;
use App\Domain\Hostel\Infrastructure\HostelResidencyAssignment;
use App\Domain\Students\Infrastructure\Student;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The only sanctioned write path for a Student's Hostel residency
 * (docs/modules/HOSTEL.md "ResidencyAssignment lifecycle"). `assign()`
 * REJECTS if the Student already has an active residency OR the Bed
 * already has an active resident -- the caller must explicitly
 * `end()` the current residency first, mirroring
 * App\Domain\Transport\Application\TransportStudentAssignmentService::assign()'s
 * explicit-action-required precedent (never a silent transfer,
 * checkpoint brief section 18).
 */
class HostelResidencyService
{
    /**
     * ORG.7D-4 (ADR 0076 §40.5): the Student row is locked FOR NO KEY UPDATE --
     * exclusive against another assignment, a Student update and a share-lock
     * eligibility check, but compatible with the FOR KEY SHARE a concurrent
     * enrollment INSERT's foreign key takes. FOR UPDATE would deadlock a
     * Campus-scoped assignment (Student lock, then Enrollment share) against a
     * transfer or rollover (Enrollment lock, then the insert's Student key share).
     */
    public const STUDENT_LOCK = 'for no key update';

    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly HostelFeeSelectionService $fees,
    ) {}

    /**
     * THE two core invariants (checkpoint brief sections 15-16): a Bed
     * must never have two simultaneous active residents, and a
     * Student must never have two simultaneous active residencies.
     * The Student row is locked FIRST (self::STUDENT_LOCK), then the Bed row
     * -- a deliberate, FIXED lock order (checkpoint brief section 21)
     * so two concurrent `assign()` calls can never deadlock against
     * each other, regardless of which Student/Bed pairing they race
     * on: both always acquire locks in the same Student-then-Bed
     * order, so PostgreSQL serializes them on whichever row they
     * actually share, never in a cycle. The database's two partial
     * unique indexes
     * (`hostel_residency_assignments_one_active_per_bed`/
     * `_one_active_per_student`) remain the authoritative, always-on
     * backstop regardless of this method's own locking -- proven
     * under real two-process concurrency in
     * tests/Feature/Hostel/HostelResidencyConcurrencyTest.php.
     */
    public function assign(Student $student, HostelBed $bed, ?User $actor = null, ?HostelResidencyCampusGuard $guard = null): HostelResidencyAssignment
    {
        if (! $student->isActive()) {
            throw $guard !== null ? new HostelResidencyUnavailableException : new StudentNotEligibleException;
        }

        if (! $bed->isAvailableForAssignment()) {
            throw new BedNotAvailableException;
        }

        try {
            return DB::transaction(function () use ($student, $bed, $actor, $guard) {
                $lockedStudent = Student::query()
                    ->where('id', $student->id)
                    ->lock(self::STUDENT_LOCK)
                    ->firstOrFail();

                $lockedBed = HostelBed::query()
                    ->where('id', $bed->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                // ORG.7D-3 (ADR 0076 §39): a Campus-scoped actor's Campus and
                // current-Student check, at write time, under these locks.
                $guard?->beforeAssign($lockedStudent, $lockedBed);

                if (
                    HostelResidencyAssignment::query()
                        ->where('student_id', $lockedStudent->id)
                        ->where('status', 'active')
                        ->exists()
                ) {
                    // A Campus-scoped actor never learns that (or where) the
                    // Student already lives -- it may be another Campus.
                    throw $guard !== null ? new HostelResidencyUnavailableException : new StudentAlreadyResidentException;
                }

                if (
                    HostelResidencyAssignment::query()
                        ->where('hostel_bed_id', $lockedBed->id)
                        ->where('status', 'active')
                        ->exists()
                ) {
                    throw new BedAlreadyOccupiedException;
                }

                $assignment = HostelResidencyAssignment::query()->create([
                    'school_id' => $bed->school_id,
                    'student_id' => $student->id,
                    'hostel_bed_id' => $bed->id,
                    'status' => 'active',
                    'starts_on' => now(),
                    'ends_on' => null,
                ]);

                $this->audit->school($bed->school, 'hostel.residency.assigned', actor: $actor, subject: $assignment, metadata: [
                    'studentId' => $student->id,
                    'hostelBedId' => $bed->id,
                ]);

                // OPF.2 (ADR 0067 §15): fee intent for the active year, in the
                // same transaction; an unmapped or not-applicable case never
                // fails the residency.
                $this->fees->recordForNewResidency($assignment, $actor);

                return $assignment;
            });
        } catch (UniqueConstraintViolationException) {
            throw $guard !== null ? new HostelResidencyUnavailableException : new ConcurrentResidencyConflictException;
        }
    }

    /**
     * A conditional `UPDATE ... WHERE status = 'active'` (not a blind
     * `$assignment->update(...)`), mirroring
     * TransportStudentAssignmentService::end()'s/VisitorVisitService::checkOut()'s
     * same discipline -- this is what makes a repeated/retried end
     * request safe without needing route-level idempotency. The
     * original `starts_on` is never rewritten.
     */
    public function end(HostelResidencyAssignment $assignment, ?User $actor = null): HostelResidencyAssignment
    {
        return DB::transaction(function () use ($assignment, $actor) {
            $affected = HostelResidencyAssignment::query()
                ->where('id', $assignment->id)
                ->where('status', 'active')
                ->update(['status' => 'ended', 'ends_on' => now()]);

            if ($affected === 0) {
                throw new ResidencyAlreadyEndedException;
            }

            $fresh = $assignment->refresh();

            $this->audit->school($assignment->school, 'hostel.residency.ended', actor: $actor, subject: $fresh, metadata: [
                'studentId' => $assignment->student_id,
                'hostelBedId' => $assignment->hostel_bed_id,
            ]);

            // OPF.2: withdraws future fee intent only; no charge is touched.
            $this->fees->withdrawForEndedResidency($fresh, $actor);

            return $fresh;
        });
    }
}

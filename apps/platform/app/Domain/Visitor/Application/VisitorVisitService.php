<?php

namespace App\Domain\Visitor\Application;

use App\Domain\HR\Infrastructure\Employee;
use App\Domain\Visitor\Application\Exceptions\ConcurrentCheckInConflictException;
use App\Domain\Visitor\Application\Exceptions\HostEmployeeNotEligibleException;
use App\Domain\Visitor\Application\Exceptions\VisitAlreadyCheckedOutException;
use App\Domain\Visitor\Application\Exceptions\VisitorAlreadyCheckedInException;
use App\Domain\Visitor\Application\Exceptions\VisitorNotEligibleException;
use App\Domain\Visitor\Infrastructure\Visitor;
use App\Domain\Visitor\Infrastructure\VisitorVisit;
use App\Models\Campus;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The only sanctioned write path for a Visitor's check-in/check-out
 * lifecycle (docs/modules/VISITOR.md "Visit lifecycle"). `checkIn()`
 * REJECTS if the Visitor already has an active Visit -- the caller
 * must explicitly `checkOut()` the current one first -- mirroring
 * App\Domain\Transport\Application\TransportStudentAssignmentService::assign()'s
 * explicit-action-required precedent.
 */
class VisitorVisitService
{
    public function __construct(
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * THE core invariant (checkpoint brief section 14): a Visitor must
     * never have two simultaneous active Visits. `lockForUpdate()` on
     * the Visitor row itself serializes any two concurrent checkIn()
     * calls for the SAME Visitor before either reaches the
     * "already checked in" check -- the exact
     * TransportStudentAssignmentService::assign() locking discipline
     * applied to Visitor instead of Student. The database's partial
     * unique index (`visitor_visits_one_active_per_visitor`) remains
     * the authoritative, always-on backstop regardless of this
     * method's own locking -- proven under real two-process
     * concurrency in
     * tests/Feature/Visitor/VisitorVisitCheckInConcurrencyTest.php.
     */
    public function checkIn(
        Visitor $visitor,
        Campus $campus,
        ?Employee $host,
        string $purpose,
        ?string $gatePassNumber,
        ?User $actor = null,
    ): VisitorVisit {
        if (! $visitor->isActive()) {
            throw new VisitorNotEligibleException;
        }

        if ($host !== null && ! $host->isActive()) {
            throw new HostEmployeeNotEligibleException;
        }

        try {
            return DB::transaction(function () use ($visitor, $campus, $host, $purpose, $gatePassNumber, $actor) {
                $lockedVisitor = Visitor::query()
                    ->where('id', $visitor->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if (
                    VisitorVisit::query()
                        ->where('visitor_id', $lockedVisitor->id)
                        ->where('status', 'checked_in')
                        ->exists()
                ) {
                    throw new VisitorAlreadyCheckedInException;
                }

                $visit = VisitorVisit::query()->create([
                    'school_id' => $visitor->school_id,
                    'visitor_id' => $visitor->id,
                    'campus_id' => $campus->id,
                    'host_employee_id' => $host?->id,
                    'purpose' => $purpose,
                    'gate_pass_number' => $gatePassNumber,
                    'status' => 'checked_in',
                    'checked_in_at' => now(),
                    'checked_out_at' => null,
                ]);

                $this->audit->school($visitor->school, 'visitor.visit.checked_in', actor: $actor, subject: $visit, metadata: [
                    'visitorId' => $visitor->id,
                    'campusId' => $campus->id,
                    'hostEmployeeId' => $host?->id,
                    'gatePassNumber' => $gatePassNumber,
                ]);

                return $visit;
            });
        } catch (UniqueConstraintViolationException) {
            throw new ConcurrentCheckInConflictException;
        }
    }

    /**
     * A conditional `UPDATE ... WHERE status = 'checked_in'` (not a
     * blind `$visit->update(...)`), mirroring
     * TransportStudentAssignmentService::end()'s same discipline --
     * this is what makes a repeated/retried check-out safe without
     * needing route-level idempotency (VISITOR.md "Check-out
     * idempotency decision"). The original `checked_in_at` is never
     * rewritten.
     */
    public function checkOut(VisitorVisit $visit, ?User $actor = null): VisitorVisit
    {
        return DB::transaction(function () use ($visit, $actor) {
            $affected = VisitorVisit::query()
                ->where('id', $visit->id)
                ->where('status', 'checked_in')
                ->update(['status' => 'checked_out', 'checked_out_at' => now()]);

            if ($affected === 0) {
                throw new VisitAlreadyCheckedOutException;
            }

            $fresh = $visit->refresh();

            $this->audit->school($visit->school, 'visitor.visit.checked_out', actor: $actor, subject: $fresh, metadata: [
                'visitorId' => $visit->visitor_id,
                'campusId' => $visit->campus_id,
            ]);

            return $fresh;
        });
    }
}

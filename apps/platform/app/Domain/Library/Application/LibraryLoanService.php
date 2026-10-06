<?php

namespace App\Domain\Library\Application;

use App\Domain\Library\Application\Exceptions\ConcurrentCheckoutConflictException;
use App\Domain\Library\Application\Exceptions\CopyNotAvailableException;
use App\Domain\Library\Application\Exceptions\LoanAlreadyReturnedException;
use App\Domain\Library\Application\Exceptions\StudentNotEligibleException;
use App\Domain\Library\Infrastructure\LibraryCopy;
use App\Domain\Library\Infrastructure\LibraryLoan;
use App\Domain\Students\Infrastructure\Student;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The only sanctioned write path for Library circulation (checkpoint
 * brief section 11) -- never write LibraryLoan directly from a
 * controller. Mirrors
 * App\Domain\AcademicStructure\Application\AcademicYearService's
 * shape: validate -> write state -> audit, inside one transaction
 * (ADR 0025's outbox is NOT used here -- see
 * docs/modules/LIBRARY.md "Events" for why this checkpoint emits none).
 */
class LibraryLoanService
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly LibraryFineService $fines,
    ) {}

    /**
     * THE core invariant (checkpoint brief section 11): one physical
     * Copy must never have two simultaneous active Loans.
     * `lockForUpdate()` below locks the Copy row both a would-be racer
     * and this call target, so PostgreSQL itself serializes any two
     * concurrent checkout attempts on the SAME Copy before either
     * reaches the availability check -- proven under real two-process
     * concurrency in
     * tests/Feature/Library/LibraryLoanCheckoutConcurrencyTest.php,
     * where the loser is caught by the ordinary
     * CopyNotAvailableException below, not the catch clause here. The
     * database's partial unique index
     * (`library_loans_one_active_per_copy`) remains the authoritative,
     * always-on backstop regardless of this method's own locking
     * discipline -- proven directly (single-process, bypassing this
     * lock entirely via a privileged connection) in
     * tests/Feature/Postgres/LibraryLoansRlsIsolationTest.php -- and
     * this catch clause is what stands between a genuine constraint
     * violation (e.g. a future write path that does not take this
     * same lock) and a raw SQL error reaching the caller.
     */
    public function checkout(LibraryCopy $copy, Student $student, Carbon $dueAt, ?User $actor = null): LibraryLoan
    {
        if (! $student->isActive()) {
            throw new StudentNotEligibleException;
        }

        try {
            return DB::transaction(function () use ($copy, $student, $dueAt, $actor) {
                $lockedCopy = LibraryCopy::query()
                    ->where('id', $copy->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if (! $lockedCopy->isActive()) {
                    throw new CopyNotAvailableException('this copy is inactive');
                }

                if ($lockedCopy->loans()->where('status', 'active')->exists()) {
                    throw new CopyNotAvailableException('it is already on loan');
                }

                $checkedOutAt = now();

                $loan = LibraryLoan::query()->create([
                    'school_id' => $copy->school_id,
                    'library_copy_id' => $copy->id,
                    'student_id' => $student->id,
                    'status' => 'active',
                    'checked_out_at' => $checkedOutAt,
                    'due_at' => $dueAt,
                    'checked_in_at' => null,
                ]);

                $this->audit->school($copy->school, 'library.loan.checked_out', actor: $actor, subject: $loan, metadata: [
                    'libraryCopyId' => $copy->id,
                    'libraryTitleId' => $copy->library_title_id,
                    'studentId' => $student->id,
                    'dueAt' => $dueAt->toIso8601String(),
                ]);

                return $loan;
            });
        } catch (UniqueConstraintViolationException) {
            throw new ConcurrentCheckoutConflictException;
        }
    }

    /**
     * A conditional `UPDATE ... WHERE status = 'active'` (not a blind
     * `$loan->update(...)`) so a stale in-memory `$loan` that lost a
     * same-row race (already checked in between read and write, e.g. a
     * duplicate/retried request) is caught here rather than silently
     * re-processed -- the exact pattern
     * AcademicYearService::activate()'s own conditional update uses.
     */
    public function checkIn(LibraryLoan $loan, ?User $actor = null): LibraryLoan
    {
        return DB::transaction(function () use ($loan, $actor) {
            $checkedInAt = now();

            $affected = LibraryLoan::query()
                ->where('id', $loan->id)
                ->where('status', 'active')
                ->update(['status' => 'returned', 'checked_in_at' => $checkedInAt]);

            if ($affected === 0) {
                throw new LoanAlreadyReturnedException;
            }

            $fresh = $loan->refresh();

            $this->audit->school($loan->school, 'library.loan.checked_in', actor: $actor, subject: $fresh, metadata: [
                'libraryCopyId' => $loan->library_copy_id,
                'studentId' => $loan->student_id,
                'wasOverdue' => $checkedInAt->greaterThan($loan->due_at),
            ]);

            // OPF.4 (ADR 0067 §17): one overdue fine from the final duration, in
            // this transaction (the loan row lock is held); not applicable is
            // audited and never fails the return.
            $this->fines->assessOnCheckIn($fresh, $actor);

            return $fresh;
        });
    }
}

<?php

namespace App\Domain\HR\Application;

use App\Domain\HR\Infrastructure\HrEmployeeNumberCounter;
use App\Models\School;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Uid\UuidV7;

/**
 * The one sanctioned way to obtain the next raw sequence value for a
 * School's employee numbering (docs/modules/HR.md "Employee identifier
 * strategy"). Must be called from inside the SAME transaction that
 * inserts the Employee row (see App\Domain\HR\Application\EmployeeService)
 * so a rolled-back Employee creation also rolls back the increment --
 * this is what keeps numbering gap-free in the normal case, not a
 * separate gap-filling mechanism.
 *
 * Concurrency safety, in order:
 *
 * 1. `ensureCounterRow()` uses `insertOrIgnore()` (PostgreSQL
 *    `INSERT ... ON CONFLICT DO NOTHING`) to create the School's
 *    counter row if it doesn't exist yet. This never throws on a
 *    concurrent duplicate attempt, so it is safe to call unconditionally
 *    without poisoning the enclosing transaction the way catching a
 *    UniqueConstraintViolationException mid-transaction would (Postgres
 *    aborts the whole transaction on an uncaught statement error; a
 *    PHP try/catch alone does not issue the SAVEPOINT needed to
 *    recover and keep using the same transaction afterward).
 * 2. `allocate()` then locks that row with `lockForUpdate()` inside the
 *    caller's transaction -- a second concurrent caller for the same
 *    School blocks on the row lock until the first commits, so two
 *    concurrent allocations for the same School can never observe or
 *    return the same value. The final `unique(school_id, employee_number)`
 *    constraint on `employees` remains the authoritative database-level
 *    backstop (CLAUDE.md rule 30) -- but is not expected to ever be
 *    triggered by this allocator's normal path, so it is deliberately
 *    NOT caught/retried here; if it ever fires, that indicates a bug in
 *    this class, not a legitimate race to recover from.
 */
class EmployeeNumberAllocator
{
    public function allocate(School $school): int
    {
        return DB::transaction(function () use ($school): int {
            $this->ensureCounterRow($school);

            /** @var HrEmployeeNumberCounter $counter */
            $counter = HrEmployeeNumberCounter::query()
                ->where('school_id', $school->id)
                ->lockForUpdate()
                ->firstOrFail();

            $value = $counter->next_value;

            $counter->update(['next_value' => $value + 1]);

            return $value;
        });
    }

    private function ensureCounterRow(School $school): void
    {
        HrEmployeeNumberCounter::query()->insertOrIgnore([
            'id' => (string) new UuidV7,
            'school_id' => $school->id,
            'next_value' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

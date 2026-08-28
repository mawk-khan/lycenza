<?php

namespace App\Domain\Students\Application\Exceptions;

/**
 * Phase 1G.3: thrown internally by EnrollmentRolloverItemExecutionService
 * while applying subject-elective rollover -- ALWAYS still inside
 * execute()'s own `DB::transaction()` -- for either a defensive
 * pre-write validation failure (configuration/data drift since the
 * last successful dry-run; normally unreachable for a properly
 * revalidated Plan, see PHASE-1G-3 doc §4) or a genuine execution-time
 * race that cannot be reconciled (PHASE-1G-3 doc §5/§6). Never surfaced
 * to a caller of `execute()` -- letting it escape the transaction is
 * exactly what forces PostgreSQL to roll back every write this
 * execution attempt made (including an elective already inserted
 * earlier in the SAME attempt, and a freshly-created target
 * StudentEnrollment), never a partial commit. `execute()` itself
 * catches this, confirms the rollback already happened, then persists
 * the SAME `invalidateForDrift()` bookkeeping the placement side
 * already uses, in a fresh transaction.
 */
class RolloverSubjectMappingExecutionException extends StudentException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct(422, 'ROLLOVER_SUBJECT_MAPPING_EXECUTION_FAILED', "Subject elective rollover execution failed: {$reason}.");
    }
}

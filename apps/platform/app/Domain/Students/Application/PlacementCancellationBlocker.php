<?php

namespace App\Domain\Students\Application;

use App\Models\School;

/**
 * SIS 4.2: a record that already depends on a placement which has not started
 * yet, so cancelling the placement before its start would leave the record
 * pointing at a placement that was never effective. Students defines the
 * contract and asks inside the cancellation transaction, under the Section and
 * placement locks; implementations live in the owning modules (rule 4) or in
 * Students itself (electives). Registered under the container tag `TAG`.
 *
 * A blocker only answers; it never ends, voids or rewrites anything.
 */
interface PlacementCancellationBlocker
{
    public const TAG = 'students.placement_cancellation_blockers';

    /** Runs inside the cancellation transaction, under the School's TenantContext. */
    public function blocksCancellation(School $school, string $enrollmentId): bool;

    /** What the operator must resolve first, e.g. "the active elective(s) on this placement". */
    public function kind(): string;
}

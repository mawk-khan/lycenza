<?php

namespace App\Domain\Students\Application\Retention;

use App\Models\School;

/**
 * E21.3B (E21-D7 core, E21.2G P1/C4/C5/AD1): rows another module keeps
 * WITH the Student core record. They have no clock of their own: they go
 * in the core purge's one-Student transaction, after the Student was
 * locked and proven past its core period, and never earlier.
 *
 * Students never imports a participant. The retention orchestrator
 * (App\Support\Retention\StudentRetention) passes the closed list, so the
 * module dependency stays one-way (participant -> Students). A table a
 * participant does not name keeps blocking the Student (ReferencingRows).
 */
interface StudentCoreParticipant
{
    /** @return list<string> the tables (referencing a Student-rooted parent) whose rows of one Student this participant removes */
    public function tables(): array;

    /**
     * The first retained dependent that keeps this Student because of the
     * participant's own rows, or null. Read-only.
     *
     * @param  list<string>  $enrollmentIds  the Student's placements
     * @param  list<string>  $cleared  tables an earlier phase of the same run clears first (dry run)
     * @param  list<string>  $unitTables  every table the core unit removes itself
     */
    public function blocker(string $schoolId, string $studentId, array $enrollmentIds, array $cleared, array $unitTables): ?string;

    /** Removes the participant's rows of one Student, inside the core purge transaction (School context, Student locked). */
    public function purge(School $school, string $studentId, string $cutoffDate): void;
}

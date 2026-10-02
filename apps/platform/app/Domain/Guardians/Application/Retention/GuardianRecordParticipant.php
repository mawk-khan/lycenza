<?php

namespace App\Domain\Guardians\Application\Retention;

use App\Models\School;
use Carbon\CarbonInterface;

/**
 * E21.3C (E21.2G G1/C4/C5): rows another module keeps WITH a Guardian's
 * personal data. They go in the Guardian purge's one-Guardian
 * transaction, after the Guardian was locked and proven past its period,
 * never earlier. Guardians never imports a participant: the orchestrator
 * (App\Support\Retention\GuardianRetention) passes the closed list, so
 * the module dependency stays one-way. A table no participant names keeps
 * blocking the Guardian (ReferencingRows).
 */
interface GuardianRecordParticipant
{
    /** @return list<string> tables (referencing `guardians`) whose rows of one Guardian this participant removes */
    public function tables(): array;

    /**
     * The first retained dependent that keeps the Guardian because of the
     * participant's own rows, or null. Read-only.
     *
     * @param  list<string>  $unitTables  every table the Guardian unit removes itself
     */
    public function blocker(string $schoolId, string $guardianId, array $unitTables): ?string;

    /** Removes the participant's rows of one Guardian, inside the purge transaction (School context, Guardian locked). */
    public function purge(School $school, string $guardianId, CarbonInterface $cutoff): void;
}

<?php

namespace App\Domain\Communications\Application\Retention;

use App\Domain\Students\Application\Retention\StudentCoreParticipant;
use App\Models\School;
use App\Support\Retention\ReferencingRows;
use App\Support\Retention\RetentionExpiry;
use Illuminate\Support\Facades\DB;

/**
 * E21.3B (E21.2G C4/C5, E21-D7 core, project-adopted, pending legal
 * ratification): a Student subject's consent events and domain
 * preferences follow the Student record. Consent events are evidence of
 * the processing choice behind historical communications, so they are
 * kept as long as the record they explain: the Student core record (25
 * calendar years after final exit). They are not reduced to a transient
 * preference because a current-state table also exists. The current
 * domain preference follows the same rule.
 *
 * - They go in the core purge's one-Student transaction, never earlier.
 *   Consent events are append-only (no runtime DELETE): only their fixed,
 *   core-floored database function removes them (RetentionExpiry).
 * - Only the Student SUBJECT's rows. A Guardian subject's rows are
 *   Guardian personal data (E21.2G G1, mechanism E21.3C) and stay.
 * - Membership email preferences (`communication_preferences`) are an
 *   account preference with the membership's lifetime (E21.2G C6): not
 *   touched.
 * - Any other row referencing them keeps the Student.
 */
final class StudentConsentRetentionService implements StudentCoreParticipant
{
    private const EVENTS = 'communication_domain_consent_events';

    private const PREFERENCES = 'communication_domain_preferences';

    public function __construct(
        private readonly ReferencingRows $references,
        private readonly RetentionExpiry $expiry,
    ) {}

    public function tables(): array
    {
        return [self::EVENTS, self::PREFERENCES];
    }

    public function blocker(string $schoolId, string $studentId, array $enrollmentIds, array $cleared, array $unitTables): ?string
    {
        return $this->references->first(self::EVENTS, $schoolId, $this->ids(self::EVENTS, $schoolId, $studentId), $unitTables)
            ?? $this->references->first(self::PREFERENCES, $schoolId, $this->ids(self::PREFERENCES, $schoolId, $studentId), $unitTables);
    }

    public function purge(School $school, string $studentId, string $cutoffDate): void
    {
        if ($this->ids(self::EVENTS, $school->id, $studentId) !== []) {
            $this->expiry->studentCoreEvidence(RetentionExpiry::STUDENT_CONSENT_EVENT, $school, $studentId, $cutoffDate, false);
        }
        DB::table(self::PREFERENCES)->where('school_id', $school->id)->where('student_id', $studentId)->delete();
    }

    /** @return list<string> */
    private function ids(string $table, string $schoolId, string $studentId): array
    {
        return DB::table($table)->where('school_id', $schoolId)->where('student_id', $studentId)->orderBy('id')->pluck('id')->all();
    }
}

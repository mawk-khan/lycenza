<?php

namespace App\Domain\Communications\Application\Retention;

use App\Domain\Guardians\Application\Retention\GuardianRecordParticipant;
use App\Models\School;
use App\Support\Retention\ReferencingRows;
use App\Support\Retention\RetentionExpiry;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * E21.3C (E21.2G G1/C4/C5, project-adopted, pending legal ratification): a
 * Guardian subject's consent events and domain preferences are Guardian
 * personal data. They are the evidence of the Guardian's own processing
 * choices, so they are kept exactly as long as the Guardian (1 calendar
 * year after its last Student relationship ended, and only when nothing
 * retained needs the Guardian) and go in the Guardian purge's unit.
 *
 * - Retained Communications content naming the Guardian keeps the Guardian
 *   itself (its own FK, D3), so the consent that explains it stays too.
 * - Consent events are append-only (no runtime DELETE): only their fixed,
 *   Guardian-floored database function removes them (RetentionExpiry).
 * - A Student subject's rows go with the Student core record (E21.3B,
 *   StudentConsentRetentionService); membership email preferences follow
 *   the membership (C6). Neither is touched here.
 */
final class GuardianConsentRetentionService implements GuardianRecordParticipant
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

    public function blocker(string $schoolId, string $guardianId, array $unitTables): ?string
    {
        return $this->references->first(self::EVENTS, $schoolId, $this->ids(self::EVENTS, $schoolId, $guardianId), $unitTables)
            ?? $this->references->first(self::PREFERENCES, $schoolId, $this->ids(self::PREFERENCES, $schoolId, $guardianId), $unitTables);
    }

    public function purge(School $school, string $guardianId, CarbonInterface $cutoff): void
    {
        if ($this->ids(self::EVENTS, $school->id, $guardianId) !== []) {
            $this->expiry->guardianConsentEvents($school, $guardianId, $cutoff, false);
        }
        DB::table(self::PREFERENCES)->where('school_id', $school->id)->where('guardian_id', $guardianId)->delete();
    }

    /** @return list<string> */
    private function ids(string $table, string $schoolId, string $guardianId): array
    {
        return DB::table($table)->where('school_id', $schoolId)->where('guardian_id', $guardianId)->orderBy('id')->pluck('id')->all();
    }
}

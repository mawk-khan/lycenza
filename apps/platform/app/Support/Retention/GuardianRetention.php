<?php

namespace App\Support\Retention;

use App\Domain\Communications\Application\Retention\GuardianConsentRetentionService;
use App\Domain\Guardians\Application\Retention\GuardianRecordParticipant;
use App\Domain\Guardians\Application\Retention\GuardianRecordRetentionService;
use App\Models\School;
use Carbon\CarbonInterface;

/**
 * E21.3C (E21.2G G1): the ONE composition of the Guardian personal-data
 * purge, for the scheduled run (`platform:guardian-retention-prune`) and a
 * reviewed erasure case. It adds no rule of its own: eligibility
 * (GuardianRetentionEligibility) and every delete stay in the owning
 * module. It sits above Guardians so Communications (which depends on
 * Guardians) can take part without an inverted dependency. Adding a
 * participant is a classification decision (GuardianRetentionClassificationTest).
 */
final class GuardianRetention
{
    public function __construct(
        private readonly GuardianRecordRetentionService $records,
        private readonly GuardianConsentRetentionService $consent,
    ) {}

    /** @return array{eligible: int, deleted: int, unresolved: int, dependency_blocked: int, errors: int} */
    public function prune(School $school, CarbonInterface $cutoff, ?CarbonInterface $authorityCutoff, int $batch, bool $dryRun, ?string $only = null): array
    {
        return $this->records->prune($school, $cutoff, $authorityCutoff, $batch, $dryRun, $this->participants(), $only);
    }

    /** E21.3C (erasure planning, read-only): what keeps one Guardian, or null. */
    public function blockerFor(School $school, string $guardianId, ?CarbonInterface $authorityCutoff): ?string
    {
        return $this->records->blockerFor($school, $guardianId, $authorityCutoff, $this->participants());
    }

    /** @return list<GuardianRecordParticipant> */
    public function participants(): array
    {
        return [$this->consent];
    }
}

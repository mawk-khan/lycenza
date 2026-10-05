<?php

namespace App\Domain\Guardians\Application\Retention;

use App\Domain\Documents\Application\Retention\DocumentParentRetention;
use App\Models\School;
use App\Support\Retention\ReferencingRows;
use App\Support\Retention\RetentionExpiry;
use App\Support\Retention\RetentionUnit;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * E21.3C (E21.2G G1, project-adopted, pending legal ratification): Guardian
 * personal data goes GUARDIAN_RETENTION_YEARS (adopted 1) calendar years
 * after the Guardian last had a Student relationship
 * (GuardianRetentionEligibility), and only when nothing retained still
 * needs the Guardian. Only `platform:guardian-retention-prune` (and a
 * reviewed erasure case) reach this, through
 * App\Support\Retention\GuardianRetention.
 *
 * One Guardian per transaction:
 * 1. lock the Guardian row and recheck: no relationship, marker strictly
 *    before the cutoff (a re-link that committed first keeps everything);
 * 2. recheck the dependencies under the lock;
 * 3. delete, in order: its Documents (DocumentParentRetention; bytes after
 *    commit), the participants' rows (its consent events and domain
 *    preferences, Communications), its revoked account links past the D6
 *    authority period, its contacts, and the Guardian root LAST, so the
 *    root's own cascades reach nothing unverified.
 *
 * Kept (`dependency_blocked`), never cascaded: every other referencing row
 * (ReferencingRows, read from the FK catalog), e.g. retained Communications
 * content naming the Guardian (D3), a usable portal invitation, and any
 * table added later until it is classified. An ACTIVE account link, or a
 * revoked one younger than AUTHORITY_HISTORY_RETENTION_YEARS, keeps the
 * Guardian: retention never unlinks a User to make a deletion possible
 * (User-identity erasure stays unresolved, I5). A relationship named by a
 * Student's processing authorization is Student-core evidence (E21.3B), so
 * while it exists the Guardian is `related` and no clock runs.
 */
final class GuardianRecordRetentionService
{
    /** Dependents the unit removes itself (or verified above). */
    private const HANDLED = ['guardian_contacts', 'documents', 'student_guardian_account_links'];

    public function __construct(
        private readonly TenantContext $context,
        private readonly GuardianRetentionEligibility $guardians,
        private readonly ReferencingRows $references,
        private readonly DocumentParentRetention $documents,
    ) {}

    /**
     * @param  CarbonInterface  $cutoff  UTC: a Guardian without a relationship since strictly before it is eligible
     * @param  CarbonInterface|null  $authorityCutoff  UTC: a revoked account link unlinked before it is past D6; null = D6 unset, links block
     * @param  list<GuardianRecordParticipant>  $participants
     * @return array{eligible: int, deleted: int, unresolved: int, dependency_blocked: int, errors: int}
     */
    public function prune(School $school, CarbonInterface $cutoff, ?CarbonInterface $authorityCutoff, int $batch, bool $dryRun, array $participants, ?string $only = null): array
    {
        // E21-RH.6 (ADR 0066 §14): the whole unit as the retention identity, on its own connection.
        return app(RetentionExpiry::class)->retained('guardian_core', $dryRun, $school->id, ['eligible' => 0, 'deleted' => 0, 'unresolved' => 0, 'dependency_blocked' => 0, 'errors' => 0], fn (): array => $this->pruneUnit($school, $cutoff, $authorityCutoff, $batch, $dryRun, $participants, $only), recordedBefore: RetentionExpiry::recordedBefore($cutoff, $authorityCutoff));
    }

    /** @return array{eligible: int, deleted: int, unresolved: int, dependency_blocked: int, errors: int} */
    private function pruneUnit(School $school, CarbonInterface $cutoff, ?CarbonInterface $authorityCutoff, int $batch, bool $dryRun, array $participants, ?string $only = null): array
    {
        $at = $cutoff->copy()->utc()->format('Y-m-d H:i:s');
        $result = ['eligible' => 0, 'deleted' => 0, 'unresolved' => 0, 'dependency_blocked' => 0, 'errors' => 0];

        $result['unresolved'] = $this->guardians->endedBefore($school, $at, $batch, function (string $guardianId) use (&$result, $school, $cutoff, $at, $authorityCutoff, $dryRun, $participants): void {
            RetentionUnit::purge(
                $result,
                $dryRun,
                fn (): bool => $this->guardians->lockLifecycle($guardianId)?->endedBefore($at) === true,
                fn (): array => array_filter([$this->blocker($school->id, $guardianId, $authorityCutoff, $participants)]),
                function () use ($school, $guardianId, $cutoff, $participants): array {
                    $objects = $this->documents->purgeWithOwner('guardian', $guardianId);
                    foreach ($participants as $participant) {
                        $participant->purge($school, $guardianId, $cutoff);
                    }
                    DB::table('student_guardian_account_links')->where('school_id', $school->id)->where('guardian_id', $guardianId)->delete();
                    DB::table('guardian_contacts')->where('school_id', $school->id)->where('guardian_id', $guardianId)->delete();
                    DB::table('guardians')->where('school_id', $school->id)->where('id', $guardianId)->delete();

                    return $objects;
                },
            );
        }, $only);

        return $result;
    }

    /**
     * E21.3C (erasure planning, read-only): what keeps one Guardian, or null.
     *
     * @param  list<GuardianRecordParticipant>  $participants
     */
    public function blockerFor(School $school, string $guardianId, ?CarbonInterface $authorityCutoff, array $participants): ?string
    {
        return $this->context->withSchool($school, fn (): ?string => $this->blocker($school->id, $guardianId, $authorityCutoff, $participants));
    }

    /** @param  list<GuardianRecordParticipant>  $participants */
    private function blocker(string $schoolId, string $guardianId, ?CarbonInterface $authorityCutoff, array $participants): ?string
    {
        $unit = [...self::HANDLED, ...array_merge([], ...array_map(fn (GuardianRecordParticipant $p): array => $p->tables(), $participants))];

        $blocker = $this->references->first('guardians', $schoolId, [$guardianId], $unit);
        if ($blocker !== null) {
            return $blocker;
        }

        $liveLink = DB::table('student_guardian_account_links')->where('school_id', $schoolId)->where('guardian_id', $guardianId)
            ->where(fn (Builder $q) => $authorityCutoff === null
                ? $q->whereRaw('true')
                : $q->where('status', '!=', 'revoked')->orWhereNull('unlinked_at')->orWhere('unlinked_at', '>=', $authorityCutoff->copy()->utc()->format('Y-m-d H:i:s')))
            ->exists();
        if ($liveLink) {
            return 'student_guardian_account_links';
        }

        $blocker = $this->references->first('guardian_contacts', $schoolId, DB::table('guardian_contacts')->where('school_id', $schoolId)->where('guardian_id', $guardianId)->pluck('id')->all())
            ?? $this->references->first('student_guardian_account_links', $schoolId, DB::table('student_guardian_account_links')->where('school_id', $schoolId)->where('guardian_id', $guardianId)->pluck('id')->all())
            ?? $this->references->first('documents', $schoolId, $this->documents->idsOwnedBy('guardian', $guardianId));
        if ($blocker !== null) {
            return $blocker;
        }

        foreach ($participants as $participant) {
            $blocker = $participant->blocker($schoolId, $guardianId, $unit);
            if ($blocker !== null) {
                return $blocker;
            }
        }

        return null;
    }
}

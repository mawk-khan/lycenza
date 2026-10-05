<?php

namespace App\Domain\Identity\Application\Retention;

use App\Models\School;
use App\Support\Retention\ReferencingRows;
use App\Support\Retention\RetentionExpiry;
use App\Support\Retention\RetentionLocks;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * E21.3B (E21.2G I2, project-adopted, pending legal ratification): an
 * ENDED portal invitation (`identity_account_invitations`, a Guardian's
 * or, structurally, a Student's) is kept PORTAL_INVITATION_RETENTION_DAYS
 * (adopted 7) after it ended, then deleted. Only
 * `platform:portal-invitations-prune` calls this, never a request.
 *
 * The end is a canonical terminal timestamp, never `updated_at`:
 * - accepted: `accepted_at`;
 * - revoked (including a reissue): `revoked_at`;
 * - expired unaccepted: `expires_at` while still `pending` (the domain's
 *   derived "expired"; GuardianAccountInvitation::isExpired()).
 * A usable invitation (pending, not expired) is never eligible. A
 * terminal status without its timestamp is never eligible either.
 *
 * - The secret is already unusable from the moment the invitation ended
 *   (only its SHA-256 is stored); this only bounds how long the metadata
 *   stays. Nothing is reopened or extended.
 * - Acceptance and revocation lock the row and recheck it, and the delete
 *   re-applies the predicate on rows it locked `SKIP LOCKED`: an
 *   invitation being accepted or revoked right now is skipped and, once
 *   changed, has a new end; a deleted one simply no longer exists for them
 *   (acceptance then refuses, as for any unusable invitation).
 * - A row another table references is kept (`dependency_blocked`); none
 *   does today (ReferencingRows, fail-closed for a new one).
 * - The audit events of the invitation stay on their own D1 clock;
 *   nothing is copied into audit. Counts only, never an address or hash.
 * - Each batch is its own transaction, in the School's tenant context.
 */
final class PortalInvitationRetentionService
{
    private const TABLE = 'identity_account_invitations';

    public function __construct(
        private readonly TenantContext $context,
        private readonly ReferencingRows $references,
    ) {}

    /**
     * @param  CarbonInterface  $cutoff  UTC: an invitation that ended strictly before it is eligible
     * @return array{eligible: int, deleted: int, held: int, dependency_blocked: int, errors: int}
     */
    public function prune(School $school, CarbonInterface $cutoff, int $batch, bool $dryRun, bool $held): array
    {
        // E21-RH.6 (ADR 0066 §14): the whole unit as the retention identity, on its own connection.
        return app(RetentionExpiry::class)->retained('portal_invitation', $dryRun || $held, $school->id, ['eligible' => 0, 'deleted' => 0, 'held' => 0, 'dependency_blocked' => 0, 'errors' => 0], fn (): array => $this->pruneUnit($school, $cutoff, $batch, $dryRun, $held));
    }

    /** @return array{eligible: int, deleted: int, held: int, dependency_blocked: int, errors: int} */
    private function pruneUnit(School $school, CarbonInterface $cutoff, int $batch, bool $dryRun, bool $held): array
    {
        return $this->context->withSchool($school, function () use ($school, $cutoff, $batch, $dryRun, $held): array {
            $at = $cutoff->copy()->utc()->format('Y-m-d H:i:s');
            $ended = fn (Builder $q): Builder => $q->where('i.school_id', $school->id)->where(fn (Builder $e) => $e
                ->where(fn (Builder $a) => $a->where('i.status', 'accepted')->whereNotNull('i.accepted_at')->where('i.accepted_at', '<', $at))
                ->orWhere(fn (Builder $r) => $r->where('i.status', 'revoked')->whereNotNull('i.revoked_at')->where('i.revoked_at', '<', $at))
                ->orWhere(fn (Builder $p) => $p->where('i.status', 'pending')->where('i.expires_at', '<', $at)));

            $eligible = $ended(DB::table(self::TABLE.' as i'))->count();
            $blocked = $eligible === 0 ? 0 : $eligible - $this->unreferenced($ended(DB::table(self::TABLE.' as i')))->count();
            $result = ['eligible' => $eligible, 'deleted' => 0, 'held' => 0, 'dependency_blocked' => $blocked, 'errors' => 0];

            if ($held) {
                // Held rows are counted only, never reported as blocked or deletable.
                return ['eligible' => $eligible, 'deleted' => 0, 'held' => $eligible, 'dependency_blocked' => 0, 'errors' => 0];
            }
            if ($dryRun || $eligible === $blocked) {
                return $result;
            }

            do {
                try {
                    [$selected, $deleted] = DB::transaction(function () use ($ended, $batch): array {
                        // E21-RH.6: the retention identity locks through the lock-only definer (FOR UPDATE SKIP LOCKED).
                        $candidates = $this->unreferenced($ended(DB::table(self::TABLE.' as i')))
                            ->orderBy('i.id')->limit($batch)->pluck('i.id')->map(fn ($id) => (string) $id)->all();
                        $ids = RetentionLocks::lock(self::TABLE, $candidates, skipLocked: true);

                        return [count($candidates), $ids === [] ? 0 : $this->unreferenced($ended(DB::table(self::TABLE.' as i')))->whereIn('i.id', $ids)->delete()];
                    });
                } catch (QueryException) {
                    $result['errors']++;

                    break;
                }
                $result['deleted'] += $deleted;
            } while ($selected === $batch && $deleted > 0);

            return $result;
        });
    }

    /** Narrows `i` to invitations no row in any referencing table points at. */
    private function unreferenced(Builder $query): Builder
    {
        foreach ($this->references->to(self::TABLE) as $reference) {
            $query->whereNotExists(fn (Builder $q) => $q->selectRaw('1')->from($reference['table'])->whereColumn($reference['table'].'.'.$reference['column'], 'i.id'));
        }

        return $query;
    }
}

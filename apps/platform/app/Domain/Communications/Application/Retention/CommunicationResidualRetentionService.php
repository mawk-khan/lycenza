<?php

namespace App\Domain\Communications\Application\Retention;

use App\Models\School;
use App\Support\Retention\ReferencingRows;
use App\Support\Retention\RetentionExpiry;
use App\Support\Retention\RetentionLocks;
use App\Support\Retention\RetentionUnit;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * E21.3E (E21.2G C1/C2, project-adopted, pending legal ratification): the
 * Communications rows D3 never reaches because they were never sent. Kept
 * COMMUNICATIONS_ABANDONED_RETENTION_YEARS (adopted 1) calendar years after
 * their canonical end, then deleted. Only `platform:communications-prune`
 * (`--only=residual`) calls this, never a request. Sent content stays D3's
 * (CommunicationRetentionService): a row that was ever published, or has a
 * message, is never a residual.
 *
 * NEVER-SENT ANNOUNCEMENTS (C1), one per transaction:
 * - `cancelled` (from draft or scheduled, never after publication): its
 *   `cancelled_at`, set in the cancelling UPDATE;
 * - `rejected`: the `decided_at` of its latest approval request, which must
 *   itself be `rejected` (written in the same transaction as the status).
 *   A rejected announcement is still editable, and editing it returns it to
 *   draft (live working state), so the status is rechecked under the row
 *   lock: an edit that committed first keeps it.
 * - A cancelled row without `cancelled_at`, or a rejected one without its
 *   rejected request, is `unresolved` and kept. Never `updated_at`.
 * - It goes with its draft audience, channels, cohorts, approval requests and
 *   attachments (bytes after commit; a failed byte delete is left to the
 *   orphan run). Any other referencing row (a message, a recipient) keeps it.
 *
 * EMPTY THREADS (C2), one per transaction: a thread with no message and no
 * attachment (a thread starts with participants only, and no message is
 * ever deleted on its own, so it has never held content). The trigger is
 * `last_activity_at`, the documented activity time (its creation or last
 * change); NULL is `unresolved`. A message insert takes FOR KEY SHARE on the
 * thread, the purge locks it FOR UPDATE and rechecks, so a message that
 * committed first keeps the thread, and one that comes later fails on its
 * foreign key. Its participants go with it.
 *
 * A held School is counted only. Counts only, never content.
 */
final class CommunicationResidualRetentionService
{
    /** Children an announcement's own delete removes (ON DELETE CASCADE). */
    private const ANNOUNCEMENT_OWNED = [
        'communication_announcement_audience_members', 'communication_announcement_channels', 'communication_attachments',
        'communication_approval_requests', 'communication_announcement_domain_audience_members', 'communication_announcement_academic_cohorts',
    ];

    /** Children a thread's own delete removes (ON DELETE CASCADE). */
    private const THREAD_OWNED = ['communication_thread_participants'];

    public function __construct(
        private readonly TenantContext $context,
        private readonly ReferencingRows $references,
    ) {}

    /**
     * @param  CarbonInterface  $cutoff  UTC: a row whose end is strictly before it is eligible
     * @return array{eligible: int, deleted: int, held: int, unresolved: int, dependency_blocked: int, errors: int}
     */
    public function pruneNeverSent(School $school, CarbonInterface $cutoff, int $batch, bool $dryRun, bool $held): array
    {
        // E21-RH.6 (ADR 0066 §14): the whole unit as the retention identity, on its own connection.
        return app(RetentionExpiry::class)->retained('communication_never_sent', $dryRun || $held, $school->id, ['eligible' => 0, 'deleted' => 0, 'held' => 0, 'unresolved' => 0, 'dependency_blocked' => 0, 'errors' => 0], fn (): array => $this->pruneNeverSentUnit($school, $cutoff, $batch, $dryRun, $held));
    }

    /** @return array{eligible: int, deleted: int, held: int, unresolved: int, dependency_blocked: int, errors: int} */
    private function pruneNeverSentUnit(School $school, CarbonInterface $cutoff, int $batch, bool $dryRun, bool $held): array
    {
        return $this->context->withSchool($school, function () use ($school, $cutoff, $batch, $dryRun, $held): array {
            $at = $cutoff->copy()->utc()->format('Y-m-d H:i:s');
            $result = ['eligible' => 0, 'deleted' => 0, 'unresolved' => 0, 'dependency_blocked' => 0, 'errors' => 0];
            $base = fn (): Builder => DB::table('communication_announcements as a')->where('a.school_id', $school->id)
                ->whereNull('a.published_at')->whereNull('a.message_id');

            $result['unresolved'] = $base()->where(fn (Builder $q) => $q
                ->where(fn (Builder $c) => $c->where('a.status', 'cancelled')->whereNull('a.cancelled_at'))
                ->orWhere(fn (Builder $r) => $r->where('a.status', 'rejected')->whereRaw('('.$this->rejectedAt().') IS NULL')))->count();

            $this->ended($base(), $at)->select('a.id')->chunkById($batch, function ($rows) use (&$result, $school, $at, $dryRun, $held): void {
                foreach ($rows as $row) {
                    RetentionUnit::purge(
                        $result,
                        $dryRun || $held,
                        // E21-RH.6: lock (lock-only definer), then recheck under the lock.
                        fn (): bool => RetentionLocks::lockOne('communication_announcements', $row->id) && $this->ended(DB::table('communication_announcements as a')->where('a.id', $row->id)
                            ->whereNull('a.published_at')->whereNull('a.message_id'), $at)->first(['a.id']) !== null,
                        fn (): array => array_filter([$this->references->first('communication_announcements', $school->id, [$row->id], self::ANNOUNCEMENT_OWNED)]),
                        function () use ($row): array {
                            $objects = DB::table('communication_attachments')->where('communication_announcement_id', $row->id)->get(['storage_disk', 'storage_path'])->all();
                            DB::table('communication_announcements')->where('id', $row->id)->delete();

                            return $objects;
                        },
                    );
                }
            }, 'a.id', 'id');

            return $this->held($result, $held);
        });
    }

    /**
     * @param  CarbonInterface  $cutoff  UTC: a thread last active strictly before it is eligible
     * @return array{eligible: int, deleted: int, held: int, unresolved: int, dependency_blocked: int, errors: int}
     */
    public function pruneEmptyThreads(School $school, CarbonInterface $cutoff, int $batch, bool $dryRun, bool $held): array
    {
        // E21-RH.6 (ADR 0066 §14): the whole unit as the retention identity, on its own connection.
        return app(RetentionExpiry::class)->retained('communication_empty_thread', $dryRun || $held, $school->id, ['eligible' => 0, 'deleted' => 0, 'held' => 0, 'unresolved' => 0, 'dependency_blocked' => 0, 'errors' => 0], fn (): array => $this->pruneEmptyThreadsUnit($school, $cutoff, $batch, $dryRun, $held));
    }

    /** @return array{eligible: int, deleted: int, held: int, unresolved: int, dependency_blocked: int, errors: int} */
    private function pruneEmptyThreadsUnit(School $school, CarbonInterface $cutoff, int $batch, bool $dryRun, bool $held): array
    {
        return $this->context->withSchool($school, function () use ($school, $cutoff, $batch, $dryRun, $held): array {
            $at = $cutoff->copy()->utc()->format('Y-m-d H:i:s');
            $result = ['eligible' => 0, 'deleted' => 0, 'unresolved' => 0, 'dependency_blocked' => 0, 'errors' => 0];
            $empty = fn (Builder $q): Builder => $q
                ->whereNotExists(fn (Builder $m) => $m->selectRaw('1')->from('communication_messages as m')->whereColumn('m.thread_id', 't.id'));

            $result['unresolved'] = $empty(DB::table('communication_threads as t')->where('t.school_id', $school->id))->whereNull('t.last_activity_at')->count();

            $empty(DB::table('communication_threads as t')->where('t.school_id', $school->id))->where('t.last_activity_at', '<', $at)->select('t.id')
                ->chunkById($batch, function ($rows) use (&$result, $school, $at, $dryRun, $held, $empty): void {
                    foreach ($rows as $row) {
                        RetentionUnit::purge(
                            $result,
                            $dryRun || $held,
                            fn (): bool => RetentionLocks::lockOne('communication_threads', $row->id) && $empty(DB::table('communication_threads as t')->where('t.id', $row->id))->where('t.last_activity_at', '<', $at)->first(['t.id']) !== null,
                            fn (): array => array_filter([$this->references->first('communication_threads', $school->id, [$row->id], self::THREAD_OWNED)]),
                            fn (): ?array => DB::table('communication_threads')->where('id', $row->id)->delete() > 0 ? [] : null,
                        );
                    }
                }, 't.id', 'id');

            return $this->held($result, $held);
        });
    }

    /** Narrows never-sent `a` to cancelled/rejected rows whose canonical end is strictly before `$at`. */
    private function ended(Builder $query, string $at): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->where(fn (Builder $c) => $c->where('a.status', 'cancelled')->whereNotNull('a.cancelled_at')->where('a.cancelled_at', '<', $at))
            ->orWhere(fn (Builder $r) => $r->where('a.status', 'rejected')->whereRaw('('.$this->rejectedAt().') < ?', [$at])));
    }

    /** The rejection time: `decided_at` of the latest approval request, only when that request is `rejected`. */
    private function rejectedAt(): string
    {
        return "SELECT CASE WHEN r.status = 'rejected' THEN r.decided_at END FROM communication_approval_requests r
                 WHERE r.announcement_id = a.id AND r.school_id = a.school_id ORDER BY r.requested_at DESC, r.id DESC LIMIT 1";
    }

    /**
     * @param  array{eligible: int, deleted: int, unresolved: int, dependency_blocked: int, errors: int}  $result
     * @return array{eligible: int, deleted: int, held: int, unresolved: int, dependency_blocked: int, errors: int}
     */
    private function held(array $result, bool $held): array
    {
        return $held
            ? ['eligible' => $result['eligible'], 'deleted' => 0, 'held' => $result['eligible'], 'unresolved' => $result['unresolved'], 'dependency_blocked' => 0, 'errors' => 0]
            : $result + ['held' => 0];
    }
}

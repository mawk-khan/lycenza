<?php

namespace App\Domain\Communications\Application\Retention;

use App\Domain\AcademicStructure\Application\AcademicYearCalendar;
use App\Models\School;
use App\Support\Observability\StorageMetrics;
use App\Support\Retention\RetentionExpiry;
use App\Support\Tenancy\SchoolTimezone;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * E21-D3 (docs/security/E21-RETENTION-DETERMINATION.md, project-adopted,
 * pending legal ratification): Communications retention for ONE School,
 * always inside that School's own tenant context. Only
 * `platform:communications-prune` calls it, never a request.
 *
 * CONTENT is retained N calendar years after the END of the Academic Year in
 * which it was sent. The year comes from AcademicYearCalendar, never from
 * today's active year. The unit is one of:
 * - a published announcement: anchor `published_at`. It is purged with its
 *   message, recipients, deliveries, attempts, policy decisions, audience,
 *   channels, cohorts, approval requests and attachments;
 * - a whole thread: anchor every message's `created_at`, latest year wins.
 *   It is purged with its participants, messages and everything under
 *   them, and its attachments.
 * Fails closed:
 * - a sent date in no year, or in two, makes the unit `unresolved` (kept);
 * - a never-sent announcement and a thread with no message have no anchor
 *   (`skipped`, kept).
 *
 * DELIVERY TELEMETRY is retained N calendar years after its terminal
 * timestamp. That is `delivered_at`, `read_at` or `failed_at` (the latest),
 * for the terminal statuses only. A `cancelled` delivery records no
 * terminal time and is kept (`skipped`). Pending, queued, sending, accepted
 * and sent deliveries are never eligible. Policy decisions are append-only,
 * so they go through the narrow retention function (`created_at`; a
 * decision is final when written).
 *
 * Recipients and audience snapshots stay with their content. Telemetry
 * expiry never makes a retained communication unreadable.
 *
 * Every purge locks its unit row and recomputes eligibility AFTER the lock,
 * so a message posted concurrently into a thread keeps it. Attachment
 * bytes are deleted only AFTER the database commit. A failed byte delete
 * leaves an unreferenced object, which `platform:storage-orphans-prune`
 * removes later (`errors` counts them).
 */
final class CommunicationRetentionService
{
    /** Delivery statuses that are terminal AND record a terminal time. */
    public const TERMINAL_STATUSES = ['delivered', 'read', 'failed', 'bounced', 'rejected', 'expired'];

    public function __construct(
        private readonly TenantContext $context,
        private readonly AcademicYearCalendar $calendar,
        private readonly RetentionExpiry $expiry,
    ) {}

    /** @return array{eligible: int, deleted: int, skipped: int, errors: int} */
    public function pruneDeliveries(School $school, CarbonImmutable $cutoff, int $batch, bool $dryRun): array
    {
        $result = $this->context->withSchool($school, function () use ($school, $cutoff, $batch, $dryRun): array {
            $eligible = fn (): Builder => DB::table('communication_deliveries')
                ->where('school_id', $school->id)
                ->whereIn('status', self::TERMINAL_STATUSES)
                ->whereRaw('greatest(delivered_at, read_at, failed_at) < ?', [$cutoff->format('Y-m-d H:i:s')]);

            $skipped = DB::table('communication_deliveries')->where('school_id', $school->id)->where('status', 'cancelled')
                ->where('updated_at', '<', $cutoff->format('Y-m-d H:i:s'))->count();
            $count = $eligible()->count();
            $deleted = 0;

            if (! $dryRun) {
                do {
                    $removed = DB::transaction(function () use ($eligible, $batch): int {
                        $ids = $eligible()->orderBy('id')->limit($batch)->lock('for update skip locked')->pluck('id')->all();

                        return $ids === [] ? 0 : $eligible()->whereIn('id', $ids)->delete();
                    });
                    $deleted += $removed;
                } while ($removed === $batch);
            }

            return ['eligible' => $count, 'deleted' => $deleted, 'skipped' => $skipped, 'errors' => 0];
        });

        $decisions = $this->expiry->forSchool(RetentionExpiry::COMMUNICATION_POLICY_DECISION, $school, $cutoff, $batch, $dryRun);
        $result['eligible'] += $decisions['eligible'];
        $result['deleted'] += $decisions['deleted'];

        return $result;
    }

    /**
     * @param  string  $cutoffDate  School-local Y-m-d: content whose year ended BEFORE it is eligible
     * @return array{eligible: int, deleted: int, unresolved: int, skipped: int, errors: int}
     */
    public function pruneContent(School $school, string $cutoffDate, int $batch, bool $dryRun): array
    {
        return $this->context->withSchool($school, function () use ($school, $cutoffDate, $batch, $dryRun): array {
            $years = $this->calendar->years($school);
            $tz = SchoolTimezone::resolve($school);
            // A unit sent on or after the cutoff can never be eligible (its year ends later).
            $upper = CarbonImmutable::parse($cutoffDate, $tz)->addDay()->utc()->format('Y-m-d H:i:s');
            $result = ['eligible' => 0, 'deleted' => 0, 'unresolved' => 0, 'skipped' => 0, 'errors' => 0];

            $result['skipped'] += DB::table('communication_announcements')->where('school_id', $school->id)
                ->where(fn (Builder $q) => $q->whereNull('published_at')->orWhereNull('message_id'))->where('created_at', '<', $upper)->count();
            $result['skipped'] += DB::table('communication_threads as t')->where('t.school_id', $school->id)->where('t.created_at', '<', $upper)
                ->whereNotExists(fn (Builder $q) => $q->selectRaw('1')->from('communication_messages as m')->whereColumn('m.thread_id', 't.id'))->count();

            DB::table('communication_announcements')->where('school_id', $school->id)->whereNotNull('published_at')->whereNotNull('message_id')
                ->where('published_at', '<', $upper)->orderBy('id')
                ->chunkById($batch, function ($rows) use ($years, $tz, $cutoffDate, $dryRun, &$result): void {
                    foreach ($rows as $row) {
                        $verdict = $this->verdict($years, [$this->localDate($row->published_at, $tz)], $cutoffDate);
                        $this->tally($result, $verdict, $dryRun, fn () => $this->purgeAnnouncement($row->id, $years, $tz, $cutoffDate));
                    }
                });

            DB::table('communication_threads as t')->where('t.school_id', $school->id)
                ->whereExists(fn (Builder $q) => $q->selectRaw('1')->from('communication_messages as m')->whereColumn('m.thread_id', 't.id'))
                ->whereNotExists(fn (Builder $q) => $q->selectRaw('1')->from('communication_messages as m')->whereColumn('m.thread_id', 't.id')->where('m.created_at', '>=', $upper))
                ->select('t.id')->orderBy('t.id')
                ->chunkById($batch, function ($rows) use ($years, $tz, $cutoffDate, $dryRun, &$result): void {
                    foreach ($rows as $row) {
                        $verdict = $this->verdict($years, $this->threadDates($row->id, $tz), $cutoffDate);
                        $this->tally($result, $verdict, $dryRun, fn () => $this->purgeThread($row->id, $years, $tz, $cutoffDate));
                    }
                }, 't.id', 'id');

            return $result;
        });
    }

    /**
     * @param  array{eligible: int, deleted: int, unresolved: int, skipped: int, errors: int}  $result
     * @param  callable(): (array{deleted: bool, errors: int})  $purge
     */
    private function tally(array &$result, string $verdict, bool $dryRun, callable $purge): void
    {
        if ($verdict === 'unresolved') {
            $result['unresolved']++;

            return;
        }

        if ($verdict !== 'eligible') {
            return;
        }

        $result['eligible']++;

        if (! $dryRun) {
            $outcome = $purge();
            $result['deleted'] += $outcome['deleted'] ? 1 : 0;
            $result['errors'] += $outcome['errors'];
        }
    }

    /**
     * @param  array<int, array{starts_on: string, ends_on: string}>  $years
     * @param  list<string>  $sentDates  School-local Y-m-d
     * @return 'eligible'|'retained'|'unresolved'
     */
    private function verdict(array $years, array $sentDates, string $cutoffDate): string
    {
        $latestEnd = null;

        foreach ($sentDates as $date) {
            $end = AcademicYearCalendar::endOfYearContaining($years, $date);

            if ($end === null) {
                return 'unresolved';
            }

            $latestEnd = max($latestEnd ?? $end, $end);
        }

        return $latestEnd !== null && $latestEnd < $cutoffDate ? 'eligible' : 'retained';
    }

    /** @return list<string> */
    private function threadDates(string $threadId, \DateTimeZone $tz): array
    {
        return array_values(array_unique(array_map(
            fn ($createdAt) => $this->localDate($createdAt, $tz),
            DB::table('communication_messages')->where('thread_id', $threadId)->pluck('created_at')->all(),
        )));
    }

    private function localDate(string $utc, \DateTimeZone $tz): string
    {
        return CarbonImmutable::parse($utc, 'UTC')->setTimezone($tz)->toDateString();
    }

    /**
     * @param  array<int, array{starts_on: string, ends_on: string}>  $years
     * @return array{deleted: bool, errors: int}
     */
    private function purgeAnnouncement(string $id, array $years, \DateTimeZone $tz, string $cutoffDate): array
    {
        $objects = DB::transaction(function () use ($id, $years, $tz, $cutoffDate): ?array {
            $row = DB::table('communication_announcements')->where('id', $id)->lockForUpdate()->first();

            if ($row === null || $row->published_at === null || $row->message_id === null
                || $this->verdict($years, [$this->localDate($row->published_at, $tz)], $cutoffDate) !== 'eligible') {
                return null;
            }

            $objects = DB::table('communication_attachments')->where('communication_announcement_id', $id)->get(['storage_disk', 'storage_path'])->all();
            // Break the announcement <-> message RESTRICT cycle, then let the
            // cascades remove everything under each.
            DB::table('communication_announcements')->where('id', $id)->update(['message_id' => null]);
            DB::table('communication_messages')->where('id', $row->message_id)->delete();
            DB::table('communication_announcements')->where('id', $id)->delete();

            return $objects;
        });

        return $this->afterCommit($objects);
    }

    /**
     * @param  array<int, array{starts_on: string, ends_on: string}>  $years
     * @return array{deleted: bool, errors: int}
     */
    private function purgeThread(string $id, array $years, \DateTimeZone $tz, string $cutoffDate): array
    {
        $objects = DB::transaction(function () use ($id, $years, $tz, $cutoffDate): ?array {
            // FOR UPDATE conflicts with the FOR KEY SHARE a concurrent message
            // insert takes on its thread, so eligibility below is recomputed
            // after any such message has committed (and then keeps the thread).
            if (DB::table('communication_threads')->where('id', $id)->lockForUpdate()->first() === null) {
                return null;
            }

            $dates = $this->threadDates($id, $tz);
            if ($dates === [] || $this->verdict($years, $dates, $cutoffDate) !== 'eligible') {
                return null;
            }

            $objects = DB::table('communication_attachments')->where('communication_thread_id', $id)->get(['storage_disk', 'storage_path'])->all();
            DB::table('communication_threads')->where('id', $id)->delete();

            return $objects;
        });

        return $this->afterCommit($objects);
    }

    /**
     * @param  list<object{storage_disk: string, storage_path: string}>|null  $objects
     * @return array{deleted: bool, errors: int}
     */
    private function afterCommit(?array $objects): array
    {
        if ($objects === null) {
            return ['deleted' => false, 'errors' => 0];
        }

        $errors = 0;
        foreach ($objects as $object) {
            try {
                $ok = Storage::disk($object->storage_disk)->delete($object->storage_path);
            } catch (Throwable) {
                $ok = false;
            }

            if (! $ok) {
                StorageMetrics::failed('delete');
                $errors++;
            }
        }

        return ['deleted' => true, 'errors' => $errors];
    }
}

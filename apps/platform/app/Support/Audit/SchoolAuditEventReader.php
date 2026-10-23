<?php

namespace App\Support\Audit;

use App\Models\School;
use App\Models\SchoolAuditEvent;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The read contract for the School audit ledger (Phase 0L.4, ADR 0042
 * §2): the only sanctioned way for another module to read
 * `school_audit_events`. It sits next to AuditRecorder, the ledger's only
 * writer, and never writes.
 *
 * - One School per call, inside TenantContext::withSchool() on the normal
 *   runtime connection: SchoolScope and RLS both apply, plus an explicit
 *   `school_id` predicate. No platform ledger, no cross-School read.
 * - Selects the approved envelope columns only -- `metadata` is never
 *   loaded, so it can never be returned.
 * - Newest first, deterministic: `occurred_at DESC, id DESC` (ids are
 *   UUIDv7, so ties within one timestamp still have a stable order).
 * - Keyset pagination with a fixed page size. Offset pagination would
 *   repeat or skip rows here: every review of the ledger appends an
 *   access event of its own, shifting every offset by one.
 * - ORG.9B (ADR 0076 §68): an optional SchoolAuditEventFilter narrows the
 *   page in SQL (event type or family, subject class, occurred range); the
 *   keyset cursor works unchanged under it. `filterOptions()` lists the
 *   School's own event types and subject labels -- envelope columns only.
 */
class SchoolAuditEventReader
{
    public const PAGE_SIZE = 50;

    /** Envelope columns read from the ledger -- never `metadata`. */
    private const COLUMNS = ['id', 'occurred_at', 'actor_user_id', 'event_type', 'subject_type', 'subject_id', 'request_id'];

    public function __construct(private readonly TenantContext $context) {}

    /**
     * @param  string|null  $cursor  an opaque value from a previous page's
     *                               `nextCursor`; null for the newest page
     *
     * @throws InvalidArgumentException for a cursor this reader did not issue
     */
    public function page(School $school, ?string $cursor = null, ?SchoolAuditEventFilter $filter = null): SchoolAuditEventPage
    {
        $position = $cursor === null ? null : self::decodeCursor($cursor);

        return $this->context->withSchool($school, function () use ($school, $position, $filter): SchoolAuditEventPage {
            $query = SchoolAuditEvent::query()
                ->select(self::COLUMNS)
                ->where('school_id', $school->id)
                ->when($filter?->eventType !== null, fn ($q) => $q->where('event_type', $filter?->eventType))
                // A family is the exact type or anything under "<family>." --
                // starts_with(), never LIKE, so `_` and `%` are plain characters.
                ->when($filter?->eventFamily !== null, fn ($q) => $q->where(fn ($w) => $w->where('event_type', $filter?->eventFamily)->orWhereRaw('starts_with(event_type, ?)', [$filter?->eventFamily.'.'])))
                ->when($filter !== null && $filter->subjectTypes !== [], fn ($q) => $q->whereIn('subject_type', $filter === null ? [] : $filter->subjectTypes))
                ->when($filter?->fromUtc !== null, fn ($q) => $q->where('occurred_at', '>=', $filter?->fromUtc))
                ->when($filter?->beforeUtc !== null, fn ($q) => $q->where('occurred_at', '<', $filter?->beforeUtc))
                ->orderByDesc('occurred_at')
                ->orderByDesc('id')
                ->limit(self::PAGE_SIZE + 1);

            if ($position !== null) {
                $query->whereRaw('(occurred_at, id) < (?::timestamp, ?::uuid)', [$position['occurredAt'], $position['id']]);
            }

            $rows = $query->get();
            $hasMore = $rows->count() > self::PAGE_SIZE;
            $rows = $rows->take(self::PAGE_SIZE);

            $entries = $rows->map(fn (SchoolAuditEvent $event): SchoolAuditEventEntry => new SchoolAuditEventEntry(
                id: $event->id,
                occurredAt: $event->occurred_at->toIso8601String(),
                eventType: $event->event_type,
                actorUserId: $event->getAttribute('actor_user_id'),
                subjectType: $event->subject_type === null ? null : class_basename($event->subject_type),
                subjectId: $event->subject_id,
                requestId: $event->getAttribute('request_id'),
            ))->values()->all();

            $last = $rows->last();

            return new SchoolAuditEventPage(
                entries: $entries,
                nextCursor: $hasMore && $last instanceof SchoolAuditEvent
                    ? self::encodeCursor((string) $last->getRawOriginal('occurred_at'), $last->id)
                    : null,
            );
        });
    }

    /**
     * ORG.9B: the School's own event types (sorted) and subject labels -- a
     * label is the class basename the page already shows, mapped to every
     * stored class carrying it. Envelope columns only; never metadata.
     *
     * @return array{eventTypes: list<string>, eventFamilies: list<string>, subjectTypes: array<string, list<string>>}
     */
    public function filterOptions(School $school): array
    {
        return $this->context->withSchool($school, function () use ($school): array {
            // A loose index scan over the existing (school_id, event_type) and
            // (school_id, subject_type, subject_id) indexes: one index probe per
            // DISTINCT value, never a scan of the whole log (ORG.9B query plans:
            // ~0.3 ms and ~1 ms at 300,000 events versus ~56 ms and ~32 ms).
            $types = array_map(fn ($row) => (string) $row->value, DB::select(self::distinctSql('event_type', ''), [$school->id, $school->id]));
            $families = array_values(array_unique(array_map(fn (string $t) => explode('.', $t, 2)[0], $types)));
            sort($families);
            $subjects = [];
            foreach (DB::select(self::distinctSql('subject_type', ' AND subject_type IS NOT NULL'), [$school->id, $school->id]) as $row) {
                $subjects[class_basename((string) $row->value)][] = (string) $row->value;
            }
            ksort($subjects);

            return ['eventTypes' => $types, 'eventFamilies' => $families, 'subjectTypes' => $subjects];
        });
    }

    /** The distinct values of one indexed envelope column for one School, ascending (bindings: school, school). */
    private static function distinctSql(string $column, string $extra): string
    {
        return "WITH RECURSIVE t AS (
                (SELECT {$column} AS value FROM school_audit_events WHERE school_id = ?{$extra} ORDER BY {$column} LIMIT 1)
                UNION ALL
                SELECT (SELECT s.{$column} FROM school_audit_events s WHERE s.school_id = ? AND s.{$column} > t.value ORDER BY s.{$column} LIMIT 1) FROM t WHERE t.value IS NOT NULL
            ) SELECT value FROM t WHERE value IS NOT NULL";
    }

    public static function isValidCursor(string $cursor): bool
    {
        try {
            self::decodeCursor($cursor);

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    private static function encodeCursor(string $occurredAt, string $id): string
    {
        return rtrim(strtr(base64_encode($occurredAt.'|'.$id), '+/', '-_'), '=');
    }

    /** @return array{occurredAt: string, id: string} */
    private static function decodeCursor(string $cursor): array
    {
        $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);
        $parts = $decoded === false ? [] : explode('|', $decoded);

        if (count($parts) !== 2
            || preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(\.\d{1,6})?$/', $parts[0]) !== 1
            || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $parts[1]) !== 1) {
            throw new InvalidArgumentException('Invalid audit-log cursor.');
        }

        return ['occurredAt' => $parts[0], 'id' => $parts[1]];
    }
}

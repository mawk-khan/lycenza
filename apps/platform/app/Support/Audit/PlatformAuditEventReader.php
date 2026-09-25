<?php

namespace App\Support\Audit;

use App\Models\PlatformAuditEvent;
use InvalidArgumentException;

/**
 * The read contract for the platform audit ledger (Phase 0N.7, ADR 0046
 * section 7): the only sanctioned way to read `platform_audit_events`.
 * The platform counterpart of SchoolAuditEventReader -- deliberately a
 * separate reader over a separate ledger; neither reads the other's table
 * and there is no "all audit events" reader.
 *
 * - Platform ledger only; no TenantContext (the ledger has no `school_id`
 *   and no RLS), no School table, no join.
 * - Selects the seven approved envelope columns only -- `metadata`,
 *   `ip_address` and `user_agent` are never read from PostgreSQL, so they
 *   can never be returned.
 * - Newest first, deterministic: `occurred_at DESC, id DESC` (UUIDv7 ids
 *   break ties within one timestamp).
 * - Keyset pagination with a fixed page size and the same opaque cursor
 *   format as the School reader (offset paging would shift: every review
 *   appends an access event). An invalid cursor is refused.
 *
 * Index: the existing `platform_audit_events_occurred_at_index` serves this
 * order; a composite (occurred_at, id) index is only worth adding if a
 * production-sized ledger shows a sort in EXPLAIN (ADR 0046 section 7).
 */
class PlatformAuditEventReader
{
    public const PAGE_SIZE = 50;

    /** Envelope columns read from the ledger -- never metadata, IP or user agent. */
    private const COLUMNS = ['id', 'occurred_at', 'actor_user_id', 'event_type', 'subject_type', 'subject_id', 'request_id'];

    /**
     * @param  string|null  $cursor  an opaque value from a previous page's
     *                               `nextCursor`; null for the newest page
     *
     * @throws InvalidArgumentException for a cursor this reader did not issue
     */
    public function page(?string $cursor = null): PlatformAuditEventPage
    {
        $position = $cursor === null ? null : self::decodeCursor($cursor);

        $query = PlatformAuditEvent::query()
            ->select(self::COLUMNS)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit(self::PAGE_SIZE + 1);

        if ($position !== null) {
            $query->whereRaw('(occurred_at, id) < (?::timestamp, ?::uuid)', [$position['occurredAt'], $position['id']]);
        }

        $rows = $query->get();
        $hasMore = $rows->count() > self::PAGE_SIZE;
        $rows = $rows->take(self::PAGE_SIZE);

        $entries = $rows->map(fn (PlatformAuditEvent $event): PlatformAuditEventEntry => new PlatformAuditEventEntry(
            id: $event->id,
            occurredAt: $event->occurred_at->toIso8601String(),
            eventType: $event->event_type,
            actorUserId: $event->getAttribute('actor_user_id'),
            subjectType: $event->getAttribute('subject_type') === null ? null : class_basename((string) $event->getAttribute('subject_type')),
            subjectId: $event->getAttribute('subject_id'),
            requestId: $event->getAttribute('request_id'),
        ))->values()->all();

        $last = $rows->last();

        return new PlatformAuditEventPage(
            entries: $entries,
            nextCursor: $hasMore && $last instanceof PlatformAuditEvent
                ? self::encodeCursor((string) $last->getRawOriginal('occurred_at'), $last->id)
                : null,
        );
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

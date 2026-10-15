<?php

namespace App\Support\Audit;

use App\Models\School;
use App\Models\SchoolAuditEvent;
use App\Support\Tenancy\TenantContext;
use InvalidArgumentException;

/**
 * V11.2 (ADR 0075): the read contract for ONE entity's history in the
 * School audit ledger -- the shape ADR 0042 section 10 names ("an
 * `App\Support\Audit` read contract with an event-type metadata
 * allowlist"). It sits next to AuditRecorder and SchoolAuditEventReader,
 * never writes, and is separate from SchoolAuditEventReader on purpose:
 * that class is the School audit-log review contract and never loads
 * metadata at all (ComplianceArchitectureGuardTest pins it).
 *
 * - One School per call, inside TenantContext::withSchool() on the normal
 *   runtime connection, with an explicit `school_id` predicate (SchoolScope
 *   and RLS also apply). No platform ledger, no cross-School read.
 * - An event belongs to the entity when its subject IS the entity, or its
 *   metadata names the entity under the caller's link key (the same
 *   linkage the HR Activity Timeline uses). The actor never decides
 *   membership.
 * - CLOSED event-type allowlist: only the event types the caller names
 *   are read. For each, only the metadata keys the caller names are
 *   returned, and only scalar or list-of-scalar values. Anything else in
 *   the stored metadata never leaves this class.
 * - The actor is not returned. Who did what is audit evidence for the
 *   School audit-log review (`school.audit.view`, itself audited).
 * - Newest first (`occurred_at DESC, id DESC`), bounded by `$limit`;
 *   `truncated` says when older entries exist.
 */
class SchoolAuditSubjectHistoryReader
{
    public const int MAX_LIMIT = 500;

    public function __construct(private readonly TenantContext $context) {}

    /**
     * @param  class-string  $subjectType  the entity's model class (as AuditRecorder stores it)
     * @param  string  $linkKey  the metadata key other events use to name the entity (e.g. `studentId`)
     * @param  array<string, list<string>>  $allowlist  event type => metadata keys returned for it
     */
    public function history(School $school, string $subjectType, string $subjectId, string $linkKey, array $allowlist, int $limit = 200): SchoolAuditSubjectHistory
    {
        if (preg_match('/^[A-Za-z][A-Za-z0-9]*$/', $linkKey) !== 1) {
            throw new InvalidArgumentException('Invalid audit metadata link key.');
        }

        if ($allowlist === []) {
            return new SchoolAuditSubjectHistory([], false);
        }

        $limit = max(1, min($limit, self::MAX_LIMIT));

        return $this->context->withSchool($school, function () use ($school, $subjectType, $subjectId, $linkKey, $allowlist, $limit): SchoolAuditSubjectHistory {
            $rows = SchoolAuditEvent::query()
                ->select(['id', 'occurred_at', 'event_type', 'subject_type', 'subject_id', 'metadata'])
                ->where('school_id', $school->id)
                ->whereIn('event_type', array_keys($allowlist))
                ->where(function ($q) use ($subjectType, $subjectId, $linkKey) {
                    $q->where(fn ($s) => $s->where('subject_type', $subjectType)->where('subject_id', $subjectId))
                        ->orWhereRaw('metadata->>? = ?', [$linkKey, $subjectId]);
                })
                ->orderByDesc('occurred_at')
                ->orderByDesc('id')
                ->limit($limit + 1)
                ->get();

            $truncated = $rows->count() > $limit;

            $entries = $rows->take($limit)->map(function (SchoolAuditEvent $event) use ($allowlist): SchoolAuditSubjectHistoryEntry {
                $stored = is_array($event->metadata) ? $event->metadata : [];
                $details = [];

                foreach ($allowlist[$event->event_type] ?? [] as $key) {
                    if (array_key_exists($key, $stored) && self::isPlain($stored[$key])) {
                        $details[$key] = $stored[$key];
                    }
                }

                return new SchoolAuditSubjectHistoryEntry(
                    id: $event->id,
                    occurredAt: $event->occurred_at->toIso8601String(),
                    eventType: $event->event_type,
                    subjectType: $event->subject_type === null ? null : class_basename($event->subject_type),
                    subjectId: $event->subject_id,
                    details: $details,
                );
            })->values()->all();

            return new SchoolAuditSubjectHistory($entries, $truncated);
        });
    }

    /** A scalar, or a list of scalars -- never a nested structure. */
    private static function isPlain(mixed $value): bool
    {
        if ($value === null || is_scalar($value)) {
            return true;
        }

        return is_array($value) && array_is_list($value)
            && array_filter($value, fn ($v) => ! is_scalar($v)) === [];
    }
}

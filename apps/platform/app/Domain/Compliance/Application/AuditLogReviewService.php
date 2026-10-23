<?php

namespace App\Domain\Compliance\Application;

use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Audit\SchoolAuditEventEntry;
use App\Support\Audit\SchoolAuditEventFilter;
use App\Support\Audit\SchoolAuditEventReader;
use App\Support\Authorization\AuthorizesCapability;

/**
 * Phase 0L.4 -- the School audit-log review (ADR 0042 §10), Compliance's
 * first surface. In order:
 *
 * 1. `school.audit.view` in THIS School for THIS actor (403 otherwise).
 * 2. One newest-first page through SchoolAuditEventReader, the audit
 *    ledger's read contract -- never the ledger model or table.
 * 3. One `compliance.audit_log.viewed` event recording the review itself:
 *    whether a later page was requested and how many rows were returned,
 *    never any content of the rows shown.
 *
 * ORG.9B (ADR 0076 §68), 9B-4: optional filters -- an event type or family,
 * a subject label and a School-local date range -- validated against the
 * School's OWN event types and subject labels (after the capability check,
 * so options never leak to a non-holder) and applied in SQL by the reader.
 * They only narrow: the same `school.audit.view`, the same envelope fields,
 * never metadata, IP or user agent, no Campus dimension. The access event
 * records which filters were used, never their values.
 *
 * Read-only: that access event is Compliance's only write. The ledger is
 * treated as Highly Sensitive (the approved v1 treatment) and only the
 * approved envelope fields leave this service; the metadata allowlist is
 * empty.
 */
class AuditLogReviewService
{
    use AuthorizesCapability;

    public const VIEW_CAPABILITY = 'school.audit.view';

    public const ACCESS_EVENT = 'compliance.audit_log.viewed';

    public function __construct(
        private readonly SchoolAuditEventReader $reader,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * @param  array{event?: ?string, subject?: ?string, from?: ?string, to?: ?string}  $filters  School-local dates
     * @return array{fields: list<string>, entries: list<array<string, string|null>>, nextCursor: string|null, pageSize: int, filterOptions: array{events: list<string>, eventFamilies: list<string>, subjects: list<string>}}
     *
     * @throws InvalidAuditLogFilterException for an event or subject the School's log does not contain
     */
    public function review(School $school, User $actor, ?string $cursor = null, array $filters = []): array
    {
        $this->authorizeCapabilityFor($actor, self::VIEW_CAPABILITY, $school);

        $options = $this->reader->filterOptions($school);
        $filter = $this->filter($school, $filters, $options);
        $page = $this->reader->page($school, $cursor, $filter);

        $this->audit->school($school, self::ACCESS_EVENT, actor: $actor, metadata: [
            'paged' => $cursor !== null,
            'resultCount' => count($page->entries),
            'filters' => $filter?->applied() ?? [],
        ]);

        return [
            'fields' => SchoolAuditEventEntry::FIELDS,
            'entries' => array_map(fn (SchoolAuditEventEntry $entry): array => $entry->toArray(), $page->entries),
            'nextCursor' => $page->nextCursor,
            'pageSize' => SchoolAuditEventReader::PAGE_SIZE,
            'filterOptions' => [
                'events' => $options['eventTypes'],
                'eventFamilies' => $options['eventFamilies'],
                'subjects' => array_keys($options['subjectTypes']),
            ],
        ];
    }

    /**
     * @param  array{event?: ?string, subject?: ?string, from?: ?string, to?: ?string}  $filters
     * @param  array{eventTypes: list<string>, eventFamilies: list<string>, subjectTypes: array<string, list<string>>}  $options
     */
    private function filter(School $school, array $filters, array $options): ?SchoolAuditEventFilter
    {
        $event = $filters['event'] ?? null;
        $subject = $filters['subject'] ?? null;
        $from = $filters['from'] ?? null;
        $to = $filters['to'] ?? null;
        if ($event === null && $subject === null && $from === null && $to === null) {
            return null;
        }

        $eventType = $eventFamily = null;
        if ($event !== null) {
            if (in_array($event, $options['eventTypes'], true)) {
                $eventType = $event;
            } elseif (in_array($event, $options['eventFamilies'], true)) {
                $eventFamily = $event;
            } else {
                throw new InvalidAuditLogFilterException('event');
            }
        }
        if ($subject !== null && ! isset($options['subjectTypes'][$subject])) {
            throw new InvalidAuditLogFilterException('subject');
        }

        return SchoolAuditEventFilter::forSchoolDays($school, $eventType, $eventFamily, $subject === null ? [] : $options['subjectTypes'][$subject], $from, $to);
    }
}

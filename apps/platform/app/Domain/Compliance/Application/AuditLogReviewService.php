<?php

namespace App\Domain\Compliance\Application;

use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Audit\SchoolAuditEventEntry;
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
     * @return array{fields: list<string>, entries: list<array<string, string|null>>, nextCursor: string|null, pageSize: int}
     */
    public function review(School $school, User $actor, ?string $cursor = null): array
    {
        $this->authorizeCapabilityFor($actor, self::VIEW_CAPABILITY, $school);

        $page = $this->reader->page($school, $cursor);

        $this->audit->school($school, self::ACCESS_EVENT, actor: $actor, metadata: [
            'paged' => $cursor !== null,
            'resultCount' => count($page->entries),
        ]);

        return [
            'fields' => SchoolAuditEventEntry::FIELDS,
            'entries' => array_map(fn (SchoolAuditEventEntry $entry): array => $entry->toArray(), $page->entries),
            'nextCursor' => $page->nextCursor,
            'pageSize' => SchoolAuditEventReader::PAGE_SIZE,
        ];
    }
}

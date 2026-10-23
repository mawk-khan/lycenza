<?php

namespace App\Support\Audit;

use App\Models\School;
use App\Support\Tenancy\SchoolTimezone;

/**
 * ORG.9B (ADR 0076 §68), 9B-4: optional narrowing of a School audit-log page.
 * Every field only removes rows; none widens what `school.audit.view`
 * already shows, and none touches `metadata`.
 *
 * - `eventType`: one exact event type; `eventFamily`: every type whose
 *   first dot-segment is that family (e.g. `fee_concession` for
 *   `fee_concession.approved`). Both come from the School's own types.
 * - `subjectTypes`: the stored subject classes behind one safe label.
 * - `fromUtc` (inclusive) / `beforeUtc` (exclusive): School-local day
 *   bounds as UTC wall-clock strings (SchoolTimezone).
 */
final readonly class SchoolAuditEventFilter
{
    /** @param  list<string>  $subjectTypes */
    public function __construct(
        public ?string $eventType = null,
        public ?string $eventFamily = null,
        public array $subjectTypes = [],
        public ?string $fromUtc = null,
        public ?string $beforeUtc = null,
    ) {}

    /**
     * From School-local days (`$from`/`$to` inclusive, Y-m-d): the read
     * contract owns the day-to-instant conversion (SchoolTimezone), so a
     * caller never builds its own bounds.
     *
     * @param  list<string>  $subjectTypes
     */
    public static function forSchoolDays(School $school, ?string $eventType, ?string $eventFamily, array $subjectTypes, ?string $from, ?string $to): self
    {
        return new self(
            eventType: $eventType,
            eventFamily: $eventFamily,
            subjectTypes: $subjectTypes,
            fromUtc: $from === null ? null : SchoolTimezone::startOfDayUtc($school, $from),
            beforeUtc: $to === null ? null : SchoolTimezone::startOfNextDayUtc($school, $to),
        );
    }

    /** @return list<string> the names of the filters applied (never their values) */
    public function applied(): array
    {
        return array_values(array_filter([
            $this->eventType !== null ? 'eventType' : null,
            $this->eventFamily !== null ? 'eventFamily' : null,
            $this->subjectTypes !== [] ? 'subjectType' : null,
            $this->fromUtc !== null ? 'from' : null,
            $this->beforeUtc !== null ? 'to' : null,
        ]));
    }
}

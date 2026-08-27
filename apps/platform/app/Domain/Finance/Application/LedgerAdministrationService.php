<?php

namespace App\Domain\Finance\Application;

use App\Domain\Finance\Application\Exceptions\JournalEntryNotFoundException;
use App\Domain\Finance\Infrastructure\JournalEntry;
use App\Models\School;
use App\Models\User;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Tenancy\TenantContext;

/**
 * Phase 0G.3 -- the authorized ADMINISTRATIVE entry point for posting
 * and reversing journal entries that a future HTTP/UI layer will call
 * (docs/modules/FINANCE.md "0G.3 as-built", "Authorization
 * architecture"). Deliberately separate from
 * `App\Domain\Finance\Application\LedgerService`, which 0G.2
 * established as a TRUSTED CORE mutation kernel with no authorization
 * of its own (`LedgerService`'s own docblock: "$actor is passed through
 * to AuditRecorder purely for WHO-did-this provenance ... this service
 * performs no capability/permission check of any kind").
 *
 * This split exists so a future TRUSTED internal domain caller (e.g.
 * Payroll settlement, a payment-provider reconciliation job, or any
 * other system-to-system Finance integration -- none of which exist
 * yet, and none of which are implemented here) can call
 * `LedgerService` directly through its own explicit, reviewed
 * integration boundary later, WITHOUT being forced to hold or
 * impersonate a human staff member's Finance capability grant. A human
 * administrator (or, later, an HTTP controller acting on one's behalf)
 * always goes through THIS class instead -- never `LedgerService`
 * directly. No transport may expose `LedgerService` directly; a future
 * controller depends on this class.
 *
 * Does not duplicate any of `LedgerService`'s validation, account
 * lookup, Money arithmetic, posting, reversal, audit, or outbox logic
 * -- it authorizes, resolves what needs resolving under the trusted
 * School's TenantContext, and delegates. There remains exactly one
 * production posting/reversal implementation in this codebase.
 */
class LedgerAdministrationService
{
    use AuthorizesCapability;

    public function __construct(
        private readonly LedgerService $ledger,
        private readonly TenantContext $context,
    ) {}

    /**
     * Requires `finance.ledger.post` at $school, checked before
     * `LedgerService::post()` is ever invoked -- a denied caller causes
     * zero journal entries, zero journal lines, zero audit events, and
     * zero outbox events (Gate::authorize() throws
     * Illuminate\Auth\Access\AuthorizationException before this method
     * body reaches the delegation call at all).
     */
    public function post(School $school, PostJournalEntryData $data, User $actor): JournalEntryResult
    {
        $this->authorizeCapabilityFor($actor, 'finance.ledger.post', $school);

        return $this->ledger->post($school, $data, $actor);
    }

    /**
     * Requires `finance.ledger.reverse` at $school -- a SEPARATE
     * capability from `finance.ledger.post` (docs/modules/FINANCE.md
     * "0G.3 as-built", "Post vs reverse authorization"): a caller
     * holding only `finance.ledger.post` is denied here, exactly like a
     * view-only or non-member caller.
     *
     * Takes a caller-supplied journal entry id, never a caller-supplied
     * `JournalEntry` model -- the entry is resolved fresh, under the
     * trusted $school's own TenantContext, via a School-scoped query
     * (`where('school_id', $school->id)->find($journalEntryId)`).
     * Nonexistent and cross-School ids produce the IDENTICAL
     * `JournalEntryNotFoundException`, both before `LedgerService::
     * reverse()` is ever invoked and before that resolution attempt can
     * be used as an existence oracle for another School's ledger.
     */
    public function reverse(School $school, string $journalEntryId, User $actor, ?string $reason = null): JournalEntryResult
    {
        $this->authorizeCapabilityFor($actor, 'finance.ledger.reverse', $school);

        $original = $this->context->withSchool(
            $school,
            fn () => JournalEntry::query()->where('school_id', $school->id)->find($journalEntryId),
        );

        if ($original === null) {
            throw new JournalEntryNotFoundException($journalEntryId);
        }

        return $this->ledger->reverse($original, $actor, $reason);
    }
}

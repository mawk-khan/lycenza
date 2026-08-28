<?php

namespace App\Domain\Finance\Application;

use App\Domain\Finance\Application\Exceptions\JournalEntryNotFoundException;
use App\Domain\Finance\Domain\JournalSide;
use App\Domain\Finance\Infrastructure\JournalEntry;
use App\Domain\Finance\Infrastructure\JournalLine;
use App\Domain\Finance\Infrastructure\LedgerAccount;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Tenancy\TenantContext;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Phase 0G.3 -- the sole authorized read path for the Finance Ledger
 * (docs/modules/FINANCE.md "0G.3 as-built"). A DISCLOSURE BOUNDARY,
 * exactly like `App\Domain\HR\Application\EmployeeDirectoryService`:
 * every result is a typed DTO (`LedgerAccountSummary`/
 * `JournalEntrySummary`/`JournalEntryDetail`/`JournalLineDetail`),
 * never a raw `LedgerAccount`/`JournalEntry`/`JournalLine` Eloquent
 * model -- `posting_txid` remains structurally unreachable through this
 * class too, the same result-boundary discipline 0G.2's
 * `JournalEntryResult` already established for the write side.
 *
 * Single capability, `finance.ledger.view`, gates every read operation
 * here (section 9's "avoid one capability per DTO" guidance) --
 * checked BEFORE any query runs, via `AuthorizesCapability::
 * authorizeCapabilityFor()` (the same `Gate::define('capability', ...)`
 * mechanism every other module's Application-layer authorization
 * already uses; no parallel authorization engine). A caller lacking
 * the capability learns nothing about $school's Finance data, not even
 * a count -- there is no code path in this class that queries
 * `ledger_accounts`/`journal_entries`/`journal_lines` before the
 * capability check has already succeeded.
 *
 * Runs entirely under the caller's own `TenantContext`/RLS -- no
 * privileged/admin database connection, no `pgsql_admin`. A
 * caller-supplied `ledgerAccountId` (as a list filter) or journal entry
 * id belonging to a DIFFERENT School is never distinguished from one
 * that does not exist at all: the account filter simply matches zero
 * `journal_lines` rows, and a mismatched entry id resolves to
 * `JournalEntryNotFoundException` -- identical to a genuinely
 * nonexistent id (the same cross-School "no oracle" principle
 * `LedgerAccountNotFoundException`/`DocumentNotFoundException` already
 * establish, applied here to the read side).
 *
 * Every successful read is audited: FINANCE.md's already-committed
 * "Data classification" section classifies every Finance table as
 * Highly Sensitive by default (`DATA-CLASSIFICATION.md`'s "Financial
 * data" row) -- this checkpoint does not reopen or narrow that
 * decision for the current no-Fees/Payroll/Payment-linkage ledger
 * kernel, since doing so would itself be a real architecture
 * contradiction (rule "STOP" territory), not a refinement this
 * checkpoint is scoped to make. Highly Sensitive's own documented
 * engineering control ("access to Sensitive/Highly Sensitive data is
 * itself auditable, not just changes to it") therefore applies as
 * written. One audit event per successful call -- never per row --
 * mirroring `App\Domain\Documents\Application\DocumentListingService`'s
 * `document.sensitive_list_viewed` (a list call, one event) and
 * `DocumentReadService`'s `document.sensitive_metadata_viewed` (a
 * single-record call, one event) precedents exactly:
 * `ledger_account.list_viewed`, `journal_entry.list_viewed`,
 * `journal_entry.detail_viewed`. Metadata stays minimal (a result/line
 * count, or the single viewed entry's own id) -- never a full row/line
 * dump, never `posting_txid`, never SQL/filter internals, never
 * another School's id (section 42's audit-privacy rule). Nothing is
 * audited on denial -- `AuditRecorder` is never reached before
 * `authorizeCapabilityFor()` has already succeeded.
 */
class LedgerReadService
{
    use AuthorizesCapability;

    public const int MAX_PER_PAGE = 100;

    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * Ledger Account directory: deliberately unpaginated, matching this
     * repository's established convention for small, bounded reference
     * data (e.g. GradeLevel/Subject, returned via a plain `->get()`,
     * never a paginator) -- a School's chart of accounts is reference
     * data of the same shape and size class, not a growing history like
     * `journal_entries`. Ordered by normalized account code (`code` is
     * already uppercased at write time by `App\Support\NormalizesCode`,
     * so a plain `orderBy('code')` is already deterministic), with
     * `id` as a defensive tie-break.
     *
     * @return Collection<int, LedgerAccountSummary>
     */
    public function listAccounts(School $school, User $actor): Collection
    {
        $this->authorizeCapabilityFor($actor, 'finance.ledger.view', $school);

        return $this->context->withSchool($school, function () use ($school, $actor) {
            $summaries = LedgerAccount::query()
                ->where('school_id', $school->id)
                ->orderBy('code')
                ->orderBy('id')
                ->get()
                ->map(fn (LedgerAccount $account) => LedgerAccountSummary::fromModel($account))
                ->values();

            $this->audit->school($school, 'ledger_account.list_viewed', actor: $actor, metadata: [
                'resultCount' => $summaries->count(),
            ]);

            return $summaries;
        });
    }

    /**
     * Journal history: paginated, stable-ordered `posted_at DESC, id
     * DESC` (id is UUIDv7 -- time-ordered -- so this is also a
     * deterministic tie-break for entries sharing an identical
     * `posted_at` timestamp, exactly like 0G.2's own line-ordering fix
     * documented in FINANCE.md). Every filter is applied at the SQL
     * level, before pagination -- never loaded into PHP and filtered
     * there.
     *
     * Line count and reversed-by status are both computed in the SAME
     * single query as the page of entries -- `withCount('lines')` (one
     * correlated COUNT subquery) and an `addSelect` correlated scalar
     * subquery for the reversing entry's id -- never one query per row
     * regardless of page size.
     *
     * @return LengthAwarePaginator<int, JournalEntrySummary>
     */
    public function listJournalEntries(School $school, JournalEntryQuery $query, User $actor): LengthAwarePaginator
    {
        $this->authorizeCapabilityFor($actor, 'finance.ledger.view', $school);

        return $this->context->withSchool($school, function () use ($school, $query, $actor) {
            $builder = JournalEntry::query()
                ->select('journal_entries.*')
                ->where('school_id', $school->id)
                ->withCount('lines')
                ->addSelect(['reversed_by_journal_entry_id' => DB::table('journal_entries as reverser')
                    ->select('reverser.id')
                    ->where('reverser.school_id', $school->id)
                    ->whereColumn('reverser.reversal_of_journal_entry_id', 'journal_entries.id')
                    ->limit(1),
                ])
                ->when($query->postedFrom !== null, fn ($q) => $q->where('posted_at', '>=', $query->postedFrom))
                ->when($query->postedTo !== null, fn ($q) => $q->where('posted_at', '<=', $query->postedTo))
                ->when($query->ledgerAccountId !== null, fn ($q) => $q->whereExists(function ($sub) use ($school, $query) {
                    $sub->selectRaw('1')
                        ->from('journal_lines')
                        ->whereColumn('journal_lines.journal_entry_id', 'journal_entries.id')
                        ->where('journal_lines.school_id', $school->id)
                        ->where('journal_lines.ledger_account_id', $query->ledgerAccountId);
                }))
                ->when($query->reversedOnly === true, fn ($q) => $q->whereNotNull('reversal_of_journal_entry_id'))
                ->when($query->reversedOnly === false, fn ($q) => $q->whereNull('reversal_of_journal_entry_id'))
                ->when($query->search !== null && trim($query->search) !== '', function ($q) use ($query) {
                    $needle = addcslashes(trim($query->search), '%_\\');
                    $q->where('description', 'ilike', '%'.$needle.'%');
                })
                ->orderByDesc('posted_at')
                ->orderByDesc('id');

            $paginator = $builder->paginate($query->perPage, ['*'], 'page', $query->page);

            $entries = $paginator->getCollection()->map(fn (JournalEntry $entry) => new JournalEntrySummary(
                journalEntryId: $entry->id,
                currency: $entry->currency,
                description: $entry->description,
                postedAt: $entry->posted_at,
                reversalOfJournalEntryId: $entry->reversal_of_journal_entry_id,
                reversedByJournalEntryId: $entry->reversed_by_journal_entry_id,
                lineCount: (int) $entry->lines_count,
            ));

            $this->audit->school($school, 'journal_entry.list_viewed', actor: $actor, metadata: [
                'resultCount' => $entries->count(),
            ]);

            return new LengthAwarePaginator(
                $entries,
                $paginator->total(),
                $paginator->perPage(),
                $paginator->currentPage(),
                ['path' => $paginator->path()],
            );
        });
    }

    /**
     * Journal entry detail: bounded query count regardless of line
     * count (2, 20, or 100 lines) -- one query to resolve the entry, one
     * to load its lines, one (Eloquent's own batched `whereIn` eager
     * load) to load every referenced `LedgerAccount`, one to resolve
     * `reversedByJournalEntryId` -- never one account lookup per line.
     */
    public function getJournalEntryDetail(School $school, string $journalEntryId, User $actor): JournalEntryDetail
    {
        $this->authorizeCapabilityFor($actor, 'finance.ledger.view', $school);

        return $this->context->withSchool($school, function () use ($school, $journalEntryId, $actor) {
            $entry = JournalEntry::query()->where('school_id', $school->id)->find($journalEntryId);

            if ($entry === null) {
                throw new JournalEntryNotFoundException($journalEntryId);
            }

            $entry->load(['lines.ledgerAccount']);

            $reversedByJournalEntryId = JournalEntry::query()
                ->where('school_id', $school->id)
                ->where('reversal_of_journal_entry_id', $entry->id)
                ->value('id');

            $lines = $entry->lines->map(fn (JournalLine $line) => new JournalLineDetail(
                journalLineId: $line->id,
                ledgerAccountId: $line->ledger_account_id,
                accountCode: $line->ledgerAccount->code,
                accountName: $line->ledgerAccount->name,
                side: $line->isDebit() ? JournalSide::Debit : JournalSide::Credit,
                amount: $line->isDebit() ? $line->debit() : $line->credit(),
                currency: $line->currency,
            ))->values()->all();

            $this->audit->school($school, 'journal_entry.detail_viewed', actor: $actor, subject: $entry, metadata: [
                'journalEntryId' => $entry->id,
                'lineCount' => count($lines),
            ]);

            return new JournalEntryDetail(
                journalEntryId: $entry->id,
                currency: $entry->currency,
                description: $entry->description,
                postedAt: $entry->posted_at,
                reversalOfJournalEntryId: $entry->reversal_of_journal_entry_id,
                reversedByJournalEntryId: $reversedByJournalEntryId,
                lines: $lines,
            );
        });
    }
}

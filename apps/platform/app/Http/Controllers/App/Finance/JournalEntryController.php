<?php

namespace App\Http\Controllers\App\Finance;

use App\Domain\Finance\Application\Exceptions\FinanceException;
use App\Domain\Finance\Application\Exceptions\InvalidJournalCurrencyException;
use App\Domain\Finance\Application\Exceptions\JournalEntryAlreadyReversedException;
use App\Domain\Finance\Application\Exceptions\JournalEntryNotFoundException;
use App\Domain\Finance\Application\JournalEntryDetail;
use App\Domain\Finance\Application\JournalEntryQuery;
use App\Domain\Finance\Application\JournalEntrySummary;
use App\Domain\Finance\Application\JournalLineData;
use App\Domain\Finance\Application\JournalLineDetail;
use App\Domain\Finance\Application\LedgerAccountSummary;
use App\Domain\Finance\Application\LedgerAdministrationService;
use App\Domain\Finance\Application\LedgerReadService;
use App\Domain\Finance\Application\PostJournalEntryData;
use App\Domain\Finance\Domain\JournalSide;
use App\Http\Controllers\Controller;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Money\Exceptions\InvalidMoneyException;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Phase 0G.7: session-authenticated Inertia pages for the Ledger --
 * journal history/detail, posting, and reversal. Follows the same
 * convention every other App/Finance controller in this namespace
 * follows (NOT the Bearer-token JSON API under /api/v1 that 0G.6
 * built). Delegates every read/write to `LedgerReadService`/
 * `LedgerAdministrationService` -- the exact same Application-layer
 * services the JSON API controller calls -- never `LedgerService`
 * directly and never a raw `JournalEntry`/`JournalLine` Eloquent
 * query (FINANCE.md 0G.7 rules 5-7).
 *
 * `{journalEntry}` is always a plain route-parameter string, never
 * Eloquent-bound -- resolution/cross-School privacy is entirely
 * `LedgerReadService`/`LedgerAdministrationService`'s job (uniform
 * `JournalEntryNotFoundException`, translated to a real 404 here).
 *
 * Money arrives as an exact decimal STRING from the form, never a
 * JavaScript float (FINANCE.md 0G.7 rules 30-31) -- `parseAmount()`
 * mirrors `App\Domain\Finance\Http\Controllers\JournalEntryController`'s
 * own validation exactly, before a `Money` value object is ever
 * constructed.
 */
class JournalEntryController extends Controller
{
    use AuthorizesCapability;

    public function index(Request $request, TenantContext $context, LedgerReadService $service, CapabilityResolver $capabilities): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('finance.ledger.view', $school);

        $validated = $request->validate([
            'posted_from' => ['sometimes', 'date'],
            'posted_to' => ['sometimes', 'date'],
            'ledger_account_id' => ['sometimes', 'uuid'],
            'reversed_only' => ['sometimes', 'boolean'],
            'search' => ['sometimes', 'string', 'max:255'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $query = new JournalEntryQuery(
            postedFrom: $validated['posted_from'] ?? null,
            postedTo: $validated['posted_to'] ?? null,
            ledgerAccountId: $validated['ledger_account_id'] ?? null,
            reversedOnly: array_key_exists('reversed_only', $validated) ? (bool) $validated['reversed_only'] : null,
            search: $validated['search'] ?? null,
            page: (int) ($validated['page'] ?? 1),
        );

        $page = $service->listJournalEntries($school, $query, $context->actor());
        $accounts = $service->listAccounts($school, $context->actor());

        return Inertia::render('App/Finance/Ledger/Journals/Index', [
            'journalEntries' => $page
                ->through(fn (JournalEntrySummary $e) => $this->presentSummary($e))
                ->appends($request->only(['posted_from', 'posted_to', 'ledger_account_id', 'reversed_only', 'search'])),
            'filters' => [
                'posted_from' => $validated['posted_from'] ?? '',
                'posted_to' => $validated['posted_to'] ?? '',
                'ledger_account_id' => $validated['ledger_account_id'] ?? '',
                'reversed_only' => (bool) ($validated['reversed_only'] ?? false),
                'search' => $validated['search'] ?? '',
            ],
            'accounts' => $accounts->map(fn (LedgerAccountSummary $a) => ['id' => $a->ledgerAccountId, 'code' => $a->code, 'name' => $a->name])->all(),
            'canPost' => $capabilities->canInSchool($context->actor(), 'finance.ledger.post', $school),
        ]);
    }

    public function create(TenantContext $context, LedgerReadService $service): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('finance.ledger.post', $school);

        $accounts = $service->listAccounts($school, $context->actor());

        return Inertia::render('App/Finance/Ledger/Journals/Create', [
            'accounts' => $accounts
                ->filter(fn (LedgerAccountSummary $a) => $a->status === 'active')
                ->map(fn (LedgerAccountSummary $a) => ['id' => $a->ledgerAccountId, 'code' => $a->code, 'name' => $a->name, 'currency' => $a->currency])
                ->values()
                ->all(),
        ]);
    }

    public function store(Request $request, TenantContext $context, LedgerAdministrationService $service): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('finance.ledger.post', $school);

        $validated = $request->validate([
            'currency' => ['required', 'string', 'regex:/^[A-Z]{3}$/'],
            'description' => ['required', 'string', 'max:255'],
            'lines' => ['required', 'array', 'min:2', 'max:50'],
            'lines.*.ledger_account_id' => ['required', 'uuid'],
            'lines.*.side' => ['required', Rule::in(['debit', 'credit'])],
            'lines.*.amount' => ['required', 'string'],
        ]);

        $lines = array_map(
            fn (array $line) => new JournalLineData(
                $line['ledger_account_id'],
                JournalSide::from($line['side']),
                $this->parseAmount($line['amount'], $validated['currency'], 'lines'),
            ),
            $validated['lines'],
        );

        try {
            $result = $service->post($school, new PostJournalEntryData(
                currency: $validated['currency'],
                description: $validated['description'],
                lines: $lines,
            ), $context->actor());
        } catch (InvalidJournalCurrencyException $e) {
            throw ValidationException::withMessages(['currency' => [$e->getMessage()]]);
        } catch (FinanceException $e) {
            throw ValidationException::withMessages(['lines' => [$e->getMessage()]]);
        }

        return redirect("/app/finance/journal-entries/{$result->journalEntryId}");
    }

    public function show(TenantContext $context, LedgerReadService $service, CapabilityResolver $capabilities, string $journalEntry): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('finance.ledger.view', $school);

        try {
            $detail = $service->getJournalEntryDetail($school, $journalEntry, $context->actor());
        } catch (JournalEntryNotFoundException) {
            throw new NotFoundHttpException;
        }

        return Inertia::render('App/Finance/Ledger/Journals/Show', [
            'journalEntry' => $this->presentDetail($detail),
            'canReverse' => $capabilities->canInSchool($context->actor(), 'finance.ledger.reverse', $school),
        ]);
    }

    public function reverse(Request $request, TenantContext $context, LedgerAdministrationService $service, string $journalEntry): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('finance.ledger.reverse', $school);

        $validated = $request->validate([
            'reason' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        try {
            $result = $service->reverse($school, $journalEntry, $context->actor(), $validated['reason'] ?? null);
        } catch (JournalEntryNotFoundException) {
            throw new NotFoundHttpException;
        } catch (JournalEntryAlreadyReversedException $e) {
            return redirect("/app/finance/journal-entries/{$journalEntry}")->withErrors(['reversal' => $e->getMessage()]);
        }

        return redirect("/app/finance/journal-entries/{$result->journalEntryId}");
    }

    /**
     * Mirrors `App\Domain\Finance\Http\Controllers\JournalEntryController::parseAmount()`
     * exactly -- rejects `1e3`/`NaN`/`Infinity`/locale-formatted/
     * float-derived ambiguity before a `Money` value object is ever
     * constructed.
     */
    private function parseAmount(mixed $amount, string $currency, string $field): Money
    {
        if (! is_string($amount) || ! preg_match('/^\d{1,12}(\.\d{1,2})?$/', $amount)) {
            throw ValidationException::withMessages([
                $field => ['Each amount must be a positive decimal string with at most 2 decimal places (e.g. "1000.00").'],
            ]);
        }

        try {
            return Money::of($amount, $currency);
        } catch (InvalidMoneyException $e) {
            throw ValidationException::withMessages([$field => [$e->getMessage()]]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function presentSummary(JournalEntrySummary $entry): array
    {
        return [
            'id' => $entry->journalEntryId,
            'currency' => $entry->currency,
            'description' => $entry->description,
            'postedAt' => $entry->postedAt->toIso8601String(),
            'reversalOfJournalEntryId' => $entry->reversalOfJournalEntryId,
            'reversedByJournalEntryId' => $entry->reversedByJournalEntryId,
            'lineCount' => $entry->lineCount,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentDetail(JournalEntryDetail $detail): array
    {
        return [
            'id' => $detail->journalEntryId,
            'currency' => $detail->currency,
            'description' => $detail->description,
            'postedAt' => $detail->postedAt->toIso8601String(),
            'reversalOfJournalEntryId' => $detail->reversalOfJournalEntryId,
            'reversedByJournalEntryId' => $detail->reversedByJournalEntryId,
            'lines' => array_map(fn (JournalLineDetail $line) => [
                'id' => $line->journalLineId,
                'ledgerAccountId' => $line->ledgerAccountId,
                'accountCode' => $line->accountCode,
                'accountName' => $line->accountName,
                'side' => $line->side->value,
                'amount' => $line->amount->amount(),
                'currency' => $line->currency,
            ], $detail->lines),
        ];
    }
}

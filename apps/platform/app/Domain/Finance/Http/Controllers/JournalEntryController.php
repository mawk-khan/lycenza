<?php

namespace App\Domain\Finance\Http\Controllers;

use App\Domain\Finance\Application\JournalEntryDetail;
use App\Domain\Finance\Application\JournalEntryQuery;
use App\Domain\Finance\Application\JournalEntryResult;
use App\Domain\Finance\Application\JournalEntrySummary;
use App\Domain\Finance\Application\JournalLineData;
use App\Domain\Finance\Application\JournalLineDetail;
use App\Domain\Finance\Application\LedgerAdministrationService;
use App\Domain\Finance\Application\LedgerReadService;
use App\Domain\Finance\Application\PostJournalEntryData;
use App\Domain\Finance\Domain\JournalSide;
use App\Http\Controllers\Controller;
use App\Models\School;
use App\Support\Auth\Mfa\FreshMfaRequirement;
use App\Support\Money\Exceptions\InvalidMoneyException;
use App\Support\Money\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Phase 0G.6: thin HTTP transport over `LedgerReadService` (reads) and
 * `LedgerAdministrationService` (post/reverse) -- never
 * `LedgerService` (the trusted core) directly, and never a raw
 * `JournalEntry`/`JournalLine` Eloquent query. `{journalEntry}` is
 * always a plain route-parameter string, never Eloquent-bound --
 * resolution/authorization/cross-School privacy is entirely
 * `LedgerReadService`/`LedgerAdministrationService`'s job (uniform
 * `JournalEntryNotFoundException` for nonexistent and cross-School
 * ids alike), mirroring `WebhookDeliveryController`'s established
 * explicit-resolution convention.
 *
 * Money arrives/leaves as an exact decimal STRING, never a JSON float
 * (rule 18/19) -- `parseAmount()` validates decimal syntax and scale
 * BEFORE ever constructing a `Money` value object; `LedgerService`'s
 * own validation (balance, positivity, currency) remains the sole
 * business-rule authority regardless.
 *
 * Neither `post()` nor `reverse()` carries the `idempotent` middleware
 * -- 0G.2 explicitly deferred generic ledger-posting/reversal HTTP
 * idempotency (FINANCE.md, rule 30/31 of this checkpoint's brief); a
 * duplicate HTTP retry of `post()` creates a second, independent
 * journal entry, and a duplicate `reverse()` retry is rejected by
 * `JournalEntryAlreadyReversedException` (409), never silently
 * replayed.
 */
class JournalEntryController extends Controller
{
    public function index(Request $request, School $school, LedgerReadService $service): JsonResponse
    {
        $validated = $request->validate([
            'posted_from' => ['sometimes', 'date'],
            'posted_to' => ['sometimes', 'date'],
            'ledger_account_id' => ['sometimes', 'uuid'],
            'reversed_only' => ['sometimes', 'boolean'],
            'search' => ['sometimes', 'string', 'max:255'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $query = new JournalEntryQuery(
            postedFrom: $validated['posted_from'] ?? null,
            postedTo: $validated['posted_to'] ?? null,
            ledgerAccountId: $validated['ledger_account_id'] ?? null,
            reversedOnly: array_key_exists('reversed_only', $validated) ? (bool) $validated['reversed_only'] : null,
            search: $validated['search'] ?? null,
            page: (int) ($validated['page'] ?? 1),
            perPage: (int) ($validated['per_page'] ?? JournalEntryQuery::DEFAULT_PER_PAGE),
        );

        $page = $service->listJournalEntries($school, $query, $request->user());

        return response()->json([
            'data' => $page->getCollection()->map(fn (JournalEntrySummary $e) => $this->presentSummary($e))->all(),
            'meta' => [
                'page' => $page->currentPage(),
                'perPage' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function show(Request $request, School $school, string $journalEntry, LedgerReadService $service): JsonResponse
    {
        $detail = $service->getJournalEntryDetail($school, $journalEntry, $request->user());

        return response()->json(['data' => $this->presentDetail($detail)]);
    }

    public function store(Request $request, School $school, LedgerAdministrationService $service, FreshMfaRequirement $mfa): JsonResponse
    {
        $validated = $request->validate([
            'currency' => ['required', 'string', 'regex:/^[A-Z]{3}$/'],
            'description' => ['required', 'string', 'max:255'],
            'lines' => ['required', 'array', 'min:2', 'max:50'],
            'lines.*.ledger_account_id' => ['required', 'uuid'],
            'lines.*.side' => ['required', Rule::in(['debit', 'credit'])],
            'lines.*.amount' => ['required', 'string'],
        ]);

        $lines = array_map(function (array $line) use ($validated) {
            return new JournalLineData(
                $line['ledger_account_id'],
                JournalSide::from($line['side']),
                $this->parseAmount($line['amount'], $validated['currency'], 'lines.amount'),
            );
        }, $validated['lines']);

        // SR.4 (ADR 0071 §26.7): a fresh code, before the posting transaction.
        $mfa->requireForAction($request, $request->user());
        $result = $service->post($school, new PostJournalEntryData(
            currency: $validated['currency'],
            description: $validated['description'],
            lines: $lines,
        ), $request->user());

        return response()->json(['data' => $this->presentResult($result)], 201);
    }

    public function reverse(Request $request, School $school, string $journalEntry, LedgerAdministrationService $service, FreshMfaRequirement $mfa): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $mfa->requireForAction($request, $request->user());
        $result = $service->reverse($school, $journalEntry, $request->user(), $validated['reason'] ?? null);

        return response()->json(['data' => $this->presentResult($result)], 201);
    }

    /**
     * Rejects `1e3`/`NaN`/`Infinity`/locale-formatted/float-derived
     * ambiguity before a `Money` value object is ever constructed --
     * `Money::of()` itself would already reject these, but a
     * `ValidationException` (422, field-scoped) is the correct
     * transport-layer response shape, not `InvalidMoneyException`
     * bubbling up as a generic Application error.
     */
    private function parseAmount(mixed $amount, string $currency, string $field): Money
    {
        if (! is_string($amount) || ! preg_match('/^\d{1,12}(\.\d{1,2})?$/', $amount)) {
            throw ValidationException::withMessages([
                $field => ["The {$field} must be a positive decimal string with at most 2 decimal places (e.g. \"1000.00\")."],
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
    private function presentResult(JournalEntryResult $result): array
    {
        return [
            'id' => $result->journalEntryId,
            'currency' => $result->currency,
            'description' => $result->description,
            'postedAt' => $result->postedAt->toIso8601String(),
            'reversalOfJournalEntryId' => $result->reversalOfJournalEntryId,
            'lineCount' => $result->lineCount,
        ];
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

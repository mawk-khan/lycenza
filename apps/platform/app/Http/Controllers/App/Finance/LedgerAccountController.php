<?php

namespace App\Http\Controllers\App\Finance;

use App\Domain\Finance\Application\Exceptions\LedgerAccountNotFoundException;
use App\Domain\Finance\Application\Exceptions\LedgerHistoryExpiredException;
use App\Domain\Finance\Application\LedgerAccountAdministrationService;
use App\Domain\Finance\Application\LedgerAccountStatementLine;
use App\Domain\Finance\Application\LedgerAccountStatementService;
use App\Domain\Finance\Application\LedgerAccountSummary;
use App\Domain\Finance\Application\LedgerReadService;
use App\Domain\Finance\Http\TranslatesLedgerAccountErrors;
use App\Http\Controllers\Controller;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Authorization\CapabilityResolver;
use App\Support\NormalizesCodeInput;
use App\Support\Tenancy\SchoolTimezone;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The Chart of Accounts page. Reads need finance.ledger.view; since FEE.1
 * (ADR 0062 §6, K1) a holder of finance.accounts.manage can also create an
 * account and activate/deactivate one. There is no delete and no type
 * change.
 */
class LedgerAccountController extends Controller
{
    use AuthorizesCapability, NormalizesCodeInput, TranslatesLedgerAccountErrors;

    public function index(TenantContext $context, LedgerReadService $service, CapabilityResolver $capabilities): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('finance.ledger.view', $school);

        $accounts = $service->listAccounts($school, $context->actor());

        return Inertia::render('App/Finance/Ledger/Accounts', [
            'accounts' => $accounts->map(fn (LedgerAccountSummary $a) => [
                'id' => $a->ledgerAccountId,
                'code' => $a->code,
                'name' => $a->name,
                'type' => $a->type,
                'currency' => $a->currency,
                'isSystem' => $a->isSystem,
                'status' => $a->status,
            ])->all(),
            'types' => LedgerAccountAdministrationService::TYPES,
            'canManage' => $capabilities->canInSchool($context->actor(), 'finance.accounts.manage', $school),
        ]);
    }

    public function store(Request $request, TenantContext $context, LedgerAccountAdministrationService $service): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('finance.accounts.manage', $school);
        $this->normalizeCodeInput($request);

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:32', $this->caseInsensitiveUniqueCode('ledger_accounts', $school)],
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', 'string', Rule::in(LedgerAccountAdministrationService::TYPES)],
        ]);

        $this->translatingLedgerAccountErrors(fn () => $service->create($school, $validated, $context->actor()));

        return redirect('/app/finance/ledger-accounts');
    }

    public function updateStatus(Request $request, TenantContext $context, LedgerAccountAdministrationService $service, string $ledgerAccount): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('finance.accounts.manage', $school);

        $validated = $request->validate([
            'status' => ['required', 'string', Rule::in(LedgerAccountAdministrationService::STATUSES)],
        ]);

        try {
            $service->changeStatus($school, $ledgerAccount, $validated['status'], $context->actor());
        } catch (LedgerAccountNotFoundException) {
            throw new NotFoundHttpException;
        }

        return redirect('/app/finance/ledger-accounts');
    }

    /**
     * ORG.9B (ADR 0076 §68), 9B-3: one account's statement -- opening
     * balance, posted lines with a running balance, closing balance -- for a
     * School-local posting-date range (default: this month to today). Same
     * finance.ledger.view authority as the rest of the ledger; read-only.
     */
    public function ledger(Request $request, TenantContext $context, LedgerAccountStatementService $service, string $ledgerAccount): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('finance.ledger.view', $school);
        $today = SchoolTimezone::today($school);

        $validated = $request->validate([
            'from' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'before_or_equal:'.$today],
            'to' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'before_or_equal:'.$today],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);
        $from = $validated['from'] ?? substr($today, 0, 8).'01';
        $to = $validated['to'] ?? $today;
        if ($from > $to) {
            throw ValidationException::withMessages(['from' => 'The start date must be on or before the end date.']);
        }

        try {
            $statement = $service->statement($school, $context->actor(), $ledgerAccount, $from, $to, (int) ($validated['page'] ?? 1));
        } catch (LedgerAccountNotFoundException) {
            throw new NotFoundHttpException;
        } catch (LedgerHistoryExpiredException $e) {
            throw ValidationException::withMessages(['from' => "Ledger detail before {$e->retainedFrom} is no longer retained; start on that date or later."]);
        }
        $timezone = SchoolTimezone::resolve($school);

        return Inertia::render('App/Finance/Ledger/Statement', [
            'account' => [
                'id' => $statement->account->ledgerAccountId,
                'code' => $statement->account->code,
                'name' => $statement->account->name,
                'type' => $statement->account->type,
                'status' => $statement->account->status,
                'currency' => $statement->account->currency,
                // Asset and expense accounts normally carry a debit balance; the others a credit balance.
                'normalSide' => in_array($statement->account->type, ['asset', 'expense'], true) ? 'debit' : 'credit',
            ],
            'filters' => ['from' => $from, 'to' => $to],
            'today' => $today,
            'opening' => $statement->opening,
            'totalDebit' => $statement->totalDebit,
            'totalCredit' => $statement->totalCredit,
            'closing' => $statement->closing,
            'lines' => (new LengthAwarePaginator(
                array_map(fn (LedgerAccountStatementLine $l) => [
                    'journalEntryId' => $l->journalEntryId,
                    'postedOn' => $l->postedAt->setTimezone($timezone)->toDateString(),
                    'postedAt' => $l->postedAt->setTimezone($timezone)->format('Y-m-d H:i'),
                    'description' => $l->description,
                    'isReversal' => $l->isReversal,
                    'debit' => $l->debit,
                    'credit' => $l->credit,
                    'balance' => $l->balance,
                ], $statement->lines),
                $statement->total,
                $statement->perPage,
                $statement->page,
                ['path' => "/app/finance/ledger-accounts/{$statement->account->ledgerAccountId}/ledger"],
            ))->withQueryString(),
        ]);
    }
}

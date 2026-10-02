<?php

namespace App\Http\Controllers\App\Finance;

use App\Domain\Finance\Application\Exceptions\FinancialPeriodCloseRefusedException;
use App\Domain\Finance\Application\Exceptions\FinancialPeriodNotFoundException;
use App\Domain\Finance\Application\Exceptions\FinancialPeriodVerificationFailedException;
use App\Domain\Finance\Application\Periods\FinancialPeriodCloseService;
use App\Domain\Finance\Application\Periods\FinancialPeriodService;
use App\Domain\Finance\Application\Periods\FinancialPeriodSummary;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Auth\Mfa\FreshMfaRequirement;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * E21.3A (ADR 0064 §7): Finance -> Financial periods. Lists the School's
 * periods with their range, status and who closed them, and for each open
 * period what a close would face (blockers, notices). Viewing needs
 * `finance.ledger.view`. Closing needs `finance.periods.manage`, the typed
 * period key and a fresh MFA code, in that order (a refused person never
 * spends a code); the Application service re-checks the capability. There
 * is no reopen and no way to enter a balance by hand: baselines are
 * computed from the ledger.
 */
class FinancialPeriodController extends Controller
{
    use AuthorizesCapability;

    public function index(TenantContext $context, FinancialPeriodService $periods, FinancialPeriodCloseService $close, CapabilityResolver $capabilities, FreshMfaRequirement $mfa): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('finance.ledger.view', $school);
        $actor = $context->actor();

        $list = $periods->periods($school);
        $closers = User::query()->whereIn('id', array_filter(array_map(fn (FinancialPeriodSummary $p) => $p->closedByUserId, $list)))->pluck('name', 'id');

        return Inertia::render('App/Finance/Periods/Index', [
            'localToday' => $periods->localToday($school),
            'periods' => array_map(function (FinancialPeriodSummary $period) use ($school, $close, $actor, $closers) {
                $evaluation = $period->isClosed() ? null : $close->evaluate($school, $period->id, $actor);

                return [
                    'id' => $period->id,
                    'key' => $period->key,
                    'startsOn' => $period->startsOn,
                    'endsOn' => $period->endsOn,
                    'status' => $period->status,
                    'closedAt' => $period->closedAt,
                    'closedBy' => $period->closedByUserId === null ? null : ($closers[$period->closedByUserId] ?? null),
                    'entryCount' => $evaluation?->entryCount,
                    'blockers' => $evaluation === null ? [] : $evaluation->blockers,
                    'notices' => $evaluation === null ? [] : $evaluation->notices,
                ];
            }, $list),
            'unmappedEntries' => $periods->unmappedEntryCount($school),
            'canClose' => $capabilities->canInSchool($actor, 'finance.periods.manage', $school),
            'hasMfaFactor' => $mfa->hasActiveFactor($actor),
        ]);
    }

    public function close(Request $request, TenantContext $context, FinancialPeriodCloseService $close, FreshMfaRequirement $mfa, string $period): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('finance.periods.manage', $school);

        $validated = $request->validate([
            'confirmation' => ['required', 'string', 'max:16'],
            'mfa_code' => ['nullable', 'string', 'max:32'],
        ]);
        $mfa->require($request, $context->actor(), $validated['mfa_code'] ?? null);

        try {
            $close->close($school, $period, $validated['confirmation'], $context->actor());
        } catch (FinancialPeriodNotFoundException) {
            throw new NotFoundHttpException;
        } catch (FinancialPeriodCloseRefusedException $e) {
            throw ValidationException::withMessages(['period' => 'This period cannot be closed: '.implode(', ', $e->reasons).'.']);
        } catch (FinancialPeriodVerificationFailedException) {
            throw ValidationException::withMessages(['period' => 'The close was rolled back because the carried-forward balances did not reproduce the ledger. Nothing changed; contact support.']);
        }

        return redirect('/app/finance/periods');
    }
}

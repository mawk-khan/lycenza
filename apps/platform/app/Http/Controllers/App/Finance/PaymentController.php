<?php

namespace App\Http\Controllers\App\Finance;

use App\Domain\Payments\Application\Exceptions\PaymentNotFoundException;
use App\Domain\Payments\Application\PaymentDetail;
use App\Domain\Payments\Application\PaymentQuery;
use App\Domain\Payments\Application\PaymentReadService;
use App\Domain\Payments\Application\PaymentSummary;
use App\Http\Controllers\Controller;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Phase 0G.7: session-authenticated, READ-ONLY Inertia pages for
 * Payments. Follows the same convention every other App/Finance
 * controller in this namespace follows (NOT the Bearer-token JSON API
 * under /api/v1 that 0G.6 built). Delegates every read to
 * `PaymentReadService` -- the exact same Application-layer service the
 * JSON API controller calls -- never a raw `Payment`/`PaymentAllocation`
 * Eloquent query.
 *
 * There is no `finance.payments.manage` capability (0G.5's own
 * deliberate decision) and no mutation route of any kind exists here --
 * no create/settle/allocate/edit/delete/refund action, matching the
 * JSON API's own `no_human_payment_mutation_route_exists` guarantee.
 * Refund UI remains explicitly deferred (FINANCE.md 0G.7 scope).
 */
class PaymentController extends Controller
{
    use AuthorizesCapability;

    public function index(Request $request, TenantContext $context, PaymentReadService $service): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('finance.payments.view', $school);

        $validated = $request->validate([
            'provider_payment_reference' => ['sometimes', 'string', 'max:255'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $query = new PaymentQuery(
            providerPaymentReference: $validated['provider_payment_reference'] ?? null,
            page: (int) ($validated['page'] ?? 1),
        );

        $page = $service->listPayments($school, $query, $context->actor());

        return Inertia::render('App/Finance/Payments/Index', [
            'payments' => $page
                ->through(fn (PaymentSummary $p) => $this->presentSummary($p))
                ->appends($request->only(['provider_payment_reference'])),
            'filters' => [
                'provider_payment_reference' => $validated['provider_payment_reference'] ?? '',
            ],
        ]);
    }

    public function show(TenantContext $context, PaymentReadService $service, string $payment): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('finance.payments.view', $school);

        try {
            $detail = $service->getPaymentDetail($school, $payment, $context->actor());
        } catch (PaymentNotFoundException) {
            throw new NotFoundHttpException;
        }

        return Inertia::render('App/Finance/Payments/Show', [
            'payment' => $this->presentDetail($detail),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentSummary(PaymentSummary $payment): array
    {
        return [
            'id' => $payment->paymentId,
            'provider' => $payment->provider,
            'amount' => $payment->amount,
            'currency' => $payment->currency,
            'settledAt' => $payment->settledAt->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentDetail(PaymentDetail $payment): array
    {
        return [
            'id' => $payment->paymentId,
            'provider' => $payment->provider,
            'providerPaymentReference' => $payment->providerPaymentReference,
            'amount' => $payment->amount,
            'currency' => $payment->currency,
            'settlementLedgerAccountId' => $payment->settlementLedgerAccountId,
            'journalEntryId' => $payment->journalEntryId,
            'settledAt' => $payment->settledAt->toIso8601String(),
            'allocations' => $payment->allocations,
        ];
    }
}

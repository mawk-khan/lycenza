<?php

namespace App\Domain\Payments\Http\Controllers;

use App\Domain\Payments\Application\PaymentDetail;
use App\Domain\Payments\Application\PaymentQuery;
use App\Domain\Payments\Application\PaymentReadService;
use App\Domain\Payments\Application\PaymentSummary;
use App\Http\Controllers\Controller;
use App\Models\School;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 0G.6: thin HTTP transport over `PaymentReadService` -- READ
 * ONLY. There is no `finance.payments.manage` capability (0G.5) and no
 * human Payment mutation route exists here or anywhere -- settlement
 * recognition belongs exclusively to the trusted
 * `PaymentProviderEventService` boundary, reached only by a future
 * provider/HTTP adapter (deferred, not implemented in this checkpoint;
 * see FINANCE.md "0G.6 as-built"). `{payment}` is always a plain
 * route-parameter string, never Eloquent-bound -- cross-School privacy
 * is entirely `PaymentReadService`'s job (uniform
 * `PaymentNotFoundException`). Never exposes the internal Payment-
 * creation transaction identifier, a provider payload, or a provider
 * secret -- `PaymentDetail`/
 * `PaymentSummary` structurally cannot carry any of those.
 */
class PaymentController extends Controller
{
    public function index(Request $request, School $school, PaymentReadService $service): JsonResponse
    {
        $validated = $request->validate([
            'provider_payment_reference' => ['sometimes', 'string', 'max:255'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $query = new PaymentQuery(
            providerPaymentReference: $validated['provider_payment_reference'] ?? null,
            perPage: (int) ($validated['per_page'] ?? 25),
            page: (int) ($validated['page'] ?? 1),
        );

        $page = $service->listPayments($school, $query, $request->user());

        return response()->json([
            'data' => $page->getCollection()->map(fn (PaymentSummary $p) => $this->presentSummary($p))->all(),
            'meta' => [
                'page' => $page->currentPage(),
                'perPage' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function show(Request $request, School $school, string $payment, PaymentReadService $service): JsonResponse
    {
        $detail = $service->getPaymentDetail($school, $payment, $request->user());

        return response()->json(['data' => $this->presentDetail($detail)]);
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

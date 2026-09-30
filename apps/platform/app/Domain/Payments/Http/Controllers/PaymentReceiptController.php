<?php

namespace App\Domain\Payments\Http\Controllers;

use App\Domain\Payments\Application\PaymentReceiptReadService;
use App\Domain\Payments\Application\ReceiptDocument;
use App\Domain\Payments\Application\StudentFeeStatementReadService;
use App\Http\Controllers\Controller;
use App\Models\School;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * FEE.4 (ADR 0062 §17, §18): READ-ONLY receipt and statement API. There is
 * no create, edit, delete, renumber, void or tax operation -- a receipt
 * exists because a Payment settled, and the I2 backfill is an operator
 * command. The receipt needs `finance.payments.view`; the statement needs
 * `finance.charges.view` AND `finance.payments.view` -- on the route and
 * again in the Application services. The receipt is a payment
 * acknowledgement ("Payment receipt"), never a tax invoice (J: DEVELOPMENT
 * AUTHORISED — PROD LEGAL SIGN-OFF REQUIRED).
 */
class PaymentReceiptController extends Controller
{
    public function receipt(Request $request, School $school, string $payment, PaymentReceiptReadService $receipts): JsonResponse
    {
        $document = $receipts->forPayment($school, $payment, $request->user());

        return response()->json(['data' => [
            'id' => $document->receiptId,
            'title' => ReceiptDocument::TITLE,
            'receiptNumber' => $document->receiptNumber,
            'seriesKey' => $document->seriesKey,
            'sequenceValue' => $document->sequenceValue,
            'issuedAt' => $document->issuedAt->toIso8601String(),
            'paymentId' => $document->paymentId,
            'source' => $document->source,
            'method' => $document->method,
            'manualReference' => $document->manualReference,
            'amount' => $document->amount,
            'currency' => $document->currency,
            'settledAt' => $document->settledAt->toIso8601String(),
            'studentIds' => $document->studentIds,
            'lines' => $document->lines,
        ]]);
    }

    public function statement(Request $request, School $school, string $student, StudentFeeStatementReadService $statements): JsonResponse
    {
        $validated = $request->validate(['academic_year_id' => ['sometimes', 'nullable', 'uuid']]);
        if (! Str::isUuid($student)) {
            throw new NotFoundHttpException;
        }

        $statement = $statements->statementFor($school, strtolower($student), $validated['academic_year_id'] ?? null, $request->user());

        return response()->json(['data' => [
            'studentId' => $statement->studentId,
            'academicYearId' => $statement->academicYearId,
            'currency' => $statement->currency,
            'lines' => $statement->lines,
            'totals' => $statement->totals,
        ]]);
    }
}

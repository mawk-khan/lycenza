<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Phase 0H.4D-P1 -- a tiny infrastructure-only demonstration endpoint
 * proving the `mfa` middleware composes correctly with `capability:`
 * (never substituting for it), following exactly the same pattern
 * App\Http\Controllers\Api\V1\Internal\IdempotencyDemoController
 * already established for the `idempotent` middleware: NOT a real ERP
 * business module, only registered in local/testing
 * (routes/web.php), reusing an existing capability
 * (`platform.operations.view`) rather than inventing a demo-only one.
 */
class MfaDemoController extends Controller
{
    public function ping(): JsonResponse
    {
        return response()->json(['ok' => true]);
    }
}

<?php

namespace App\Support\Auth;

use Illuminate\Http\JsonResponse;
use Throwable;

/**
 * Phase 0H.4D-P1: the global JSON error envelope in bootstrap/app.php
 * (`{"error": {"message", "status", "code", ...}}`) is deliberately
 * scoped to `/api/*` only (`if (! $request->is('api/*')) return null`)
 * -- widening that guard to cover web routes generally would be an
 * unrelated, global exception-handling change, out of scope for this
 * checkpoint. MFA's web-routed JSON endpoints (Account Security,
 * the /internal/mfa-demo/ping proof route, the `mfa` middleware) build
 * the SAME envelope shape directly instead, for every exception that
 * exposes `getStatusCode()`/`errorCode()` -- MfaException and
 * App\Support\Auth\Exceptions\FreshPasswordConfirmationRequiredException
 * both do.
 */
trait RendersAuthJsonErrors
{
    protected function jsonError(Throwable $e): JsonResponse
    {
        $status = method_exists($e, 'getStatusCode') ? $e->getStatusCode() : 500;
        $code = method_exists($e, 'errorCode') ? $e->errorCode() : null;

        return response()->json([
            'error' => [
                'message' => $e->getMessage(),
                'status' => $status,
                'code' => $code,
            ],
        ], $status);
    }
}

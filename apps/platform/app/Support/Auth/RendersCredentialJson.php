<?php

namespace App\Support\Auth;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Phase 0O.3: the credential-management actions are called with
 * `postJson()` (resources/js/csrf.ts) because they return a one-time
 * secret an Inertia redirect cannot carry (the Account Security MFA
 * precedent, RendersAuthJsonErrors). This renders their refusals in the
 * same JSON error envelope `postJson()` reads -- never echoing a
 * submitted value.
 */
trait RendersCredentialJson
{
    /**
     * @param  Closure(): array<string, mixed>  $action
     */
    protected function credentialJson(Closure $action, int $status = 200): JsonResponse
    {
        try {
            return response()->json($action(), $status);
        } catch (ValidationException $e) {
            return response()->json(['error' => [
                'message' => collect($e->errors())->flatten()->first() ?? 'The request is not valid.',
                'status' => 422,
                'code' => null,
                'errors' => $e->errors(),
            ]], 422);
        } catch (AccessDeniedHttpException $e) {
            return response()->json(['error' => [
                'message' => $e->getMessage(),
                'status' => 403,
                'code' => null,
                'errors' => null,
            ]], 403);
        }
    }
}

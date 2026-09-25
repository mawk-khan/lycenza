<?php

namespace App\Http\Controllers\App\Account;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiScope;
use App\Support\Api\HumanApiTokenLifetime;
use App\Support\Api\HumanApiTokenService;
use App\Support\Auth\Mfa\FreshMfaRequirement;
use App\Support\Auth\RendersCredentialJson;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Phase 0O.3 (ADR 0049 section 2): a person's OWN human API tokens, in the
 * Account/Security area (no School context -- a token represents the
 * person, and every API request re-checks their School access then).
 *
 * - list: metadata only (never a hash or an old value);
 * - issue: an enrolled factor and a FRESH code, closed scopes, 30 days by
 *   default and never more than 90; the value is returned once, as JSON;
 * - revoke: the signed-in session suffices -- revoking must never be hard;
 *   the next API request with it fails.
 * A person can only ever see, issue or revoke their own tokens.
 */
class ApiTokenController extends Controller
{
    use RendersCredentialJson;

    public function __construct(
        private readonly HumanApiTokenService $tokens,
        private readonly FreshMfaRequirement $mfa,
    ) {}

    public function index(Request $request): Response
    {
        return Inertia::render('Account/ApiTokens', [
            'tokens' => $this->tokens->listFor($request->user()),
            'scopes' => ApiScope::HUMAN,
            'defaultDays' => HumanApiTokenLifetime::DEFAULT_DAYS,
            'maxDays' => HumanApiTokenLifetime::MAX_DAYS,
            'hasMfaFactor' => $this->mfa->hasActiveFactor($request->user()),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        return $this->credentialJson(function () use ($request): array {
            $validated = $request->validate([
                'name' => ['required', 'string', 'max:100'],
                'scopes' => ['required', 'array', 'min:1'],
                'scopes.*' => ['string'],
                'lifetime_days' => ['nullable', 'integer'],
            ]);

            $this->mfa->require($request, $request->user(), $request->input('mfa_code'));

            [$token, $plaintext] = $this->tokens->issue(
                $request->user(),
                $validated['name'],
                array_values($validated['scopes']),
                (int) ($validated['lifetime_days'] ?? HumanApiTokenLifetime::DEFAULT_DAYS),
            );

            return ['token' => $plaintext, 'id' => (string) $token->getKey(), 'expiresAt' => $token->expires_at?->toIso8601String()];
        }, 201);
    }

    public function destroy(Request $request, string $token): JsonResponse
    {
        return $this->credentialJson(function () use ($request, $token): array {
            $this->tokens->revoke($request->user(), $token);

            return ['revoked' => true];
        });
    }
}

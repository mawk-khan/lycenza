<?php

namespace App\Http\Middleware;

use App\Support\Auth\Mfa\MfaChallengeService;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * E33 / TCH-L1 (ADR 0063 section 43): the ADR 0037 MFA assurance gate for
 * session-authenticated Inertia PAGES and their form posts, registered as
 * `mfa-page`. The same two checks as RequireMfa (`mfa`) -- an active factor,
 * and current sign-in assurance (`mfa_verified_at` inside the configured
 * window) -- but a refusal renders the existing MfaRequired page (the
 * ApiClientController / platform audit-log precedent) instead of a JSON body,
 * because these are full page loads. Opt-in per route, composed after
 * `capability:`, never replacing it; MFA never substitutes for ownership.
 */
class RequireMfaForPage
{
    public function __construct(private readonly MfaChallengeService $challenge) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            throw new AuthenticationException;
        }

        $enrolled = $this->challenge->userHasActiveFactor($user);
        if (! $enrolled || ! $this->challenge->hasValidAssurance($request)) {
            return Inertia::render('App/Platform/MfaRequired', [
                'code' => $enrolled ? 'mfa_step_up_required' : 'mfa_required_not_enrolled',
            ])->toResponse($request)->setStatusCode($enrolled ? 401 : 403);
        }

        return $next($request);
    }
}

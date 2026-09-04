<?php

namespace App\Http\Middleware;

use App\Support\Auth\Mfa\Exceptions\MfaRequiredNotEnrolledException;
use App\Support\Auth\Mfa\Exceptions\MfaStepUpRequiredException;
use App\Support\Auth\Mfa\MfaChallengeService;
use App\Support\Auth\RendersAuthJsonErrors;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase 0H.4D-P1 section 8/9/15/18: generic, reusable, registered as
 * alias `mfa`. Deliberately contains ZERO reference to Examinations/
 * marks/StudentMark -- see
 * Tests\Feature\Auth\Mfa\MfaMiddlewareArchitectureGuardTest. Composed
 * alongside `capability:`, never replacing it (section 9/18):
 *
 *   ->middleware(['auth', 'school-membership', 'capability:X', 'mfa'])
 *
 * Applied per-route, opt-in (section 9's Option B) -- never globally
 * forced onto every authenticated route.
 *
 * Denials are returned as direct JSON (RendersAuthJsonErrors), not
 * thrown up to Laravel's default handler -- bootstrap/app.php's global
 * `{"error": {...}}` envelope is deliberately scoped to `/api/*` only,
 * and every route this middleware protects is expected to be a
 * JS-driven request to a Highly Sensitive action, not a full page
 * load. See RendersAuthJsonErrors's own docblock.
 */
class RequireMfa
{
    use RendersAuthJsonErrors;

    public function __construct(private readonly MfaChallengeService $challenge) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            throw new AuthenticationException;
        }

        if (! $this->challenge->userHasActiveFactor($user)) {
            return $this->jsonError(new MfaRequiredNotEnrolledException);
        }

        if (! $this->challenge->hasValidAssurance($request)) {
            return $this->jsonError(new MfaStepUpRequiredException);
        }

        return $next($request);
    }
}

<?php

namespace App\Support\Auth\Mfa;

use App\Models\User;
use App\Support\Auth\Mfa\Exceptions\MfaRequiredNotEnrolledException;
use App\Support\Auth\Mfa\Exceptions\MfaStepUpRequiredException;
use App\Support\Auth\RendersAuthJsonErrors;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * SR.4 (ADR 0071 §26.7): the read side of sensitive-action MFA. A Highly
 * Sensitive read (payroll amounts and run results, highly sensitive HR
 * records) needs CURRENT MFA assurance -- exactly what the `mfa` /
 * `mfa-page` middleware assert (an enrolled factor plus
 * MfaChallengeService::hasValidAssurance(), session or bearer token) --
 * never a fresh code per read. These are the same checks for a controller
 * that must authorize the capability first, or that shows the sensitive
 * part of a page only when assurance holds; the refusals are the
 * middleware's own (401 `mfa_step_up_required`, 403
 * `mfa_required_not_enrolled`, or the `App/Platform/MfaRequired` page).
 */
class SensitiveReadAssurance
{
    use RendersAuthJsonErrors;

    public function __construct(private readonly MfaChallengeService $challenge) {}

    public function holds(Request $request): bool
    {
        $user = $request->user();

        return $user instanceof User
            && $this->challenge->userHasActiveFactor($user)
            && $this->challenge->hasValidAssurance($request);
    }

    /** For a JSON response: the `mfa` middleware's refusal. @throws HttpResponseException */
    public function requireForJson(Request $request): void
    {
        if (! $this->holds($request)) {
            throw new HttpResponseException($this->jsonError($this->enrolled($request) ? new MfaStepUpRequiredException : new MfaRequiredNotEnrolledException));
        }
    }

    /** For an Inertia page: the `mfa-page` middleware's refusal. @throws HttpResponseException */
    public function requireForPage(Request $request): void
    {
        if (! $this->holds($request)) {
            $enrolled = $this->enrolled($request);

            throw new HttpResponseException(Inertia::render('App/Platform/MfaRequired', [
                'code' => $enrolled ? 'mfa_step_up_required' : 'mfa_required_not_enrolled',
            ])->toResponse($request)->setStatusCode($enrolled ? 401 : 403));
        }
    }

    private function enrolled(Request $request): bool
    {
        $user = $request->user();

        return $user instanceof User && $this->challenge->userHasActiveFactor($user);
    }
}

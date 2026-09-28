<?php

namespace App\Http\Controllers\Auth;

use App\Domain\Identity\Application\Staff\AccountActivationService;
use App\Domain\Identity\Application\Staff\CredentialOutcome;
use App\Http\Controllers\Controller;
use App\Support\Domains\CanonicalOrigin;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Phase 0O.12B (ADR 0059 sections 5, 10): a bootstrap account's one-time
 * activation page -- the canonical PLATFORM host only (not in
 * SchoolHostSurface: a School host answers 404), no School, no
 * TenantContext. The page never sees the secret (it is the link's
 * #fragment) and changes nothing on GET; only the CSRF-protected POST
 * consumes, and every credential failure answers the same "invalid or
 * expired". No return parameter; success goes to the platform login (no
 * auto-login).
 */
class AccountActivationController extends Controller
{
    public const INVALID_LINK = 'This activation link is invalid or has expired. Ask the person who set up your account for a new one.';

    public function edit(string $selector): Response
    {
        return Inertia::render('Auth/AccountActivation/Activate', [
            'selector' => $selector,
            'invalidMessage' => self::INVALID_LINK,
        ]);
    }

    public function update(Request $request, string $selector, AccountActivationService $activation, CanonicalOrigin $origins): RedirectResponse
    {
        $input = $request->validate([
            'secret' => ['required', 'string', 'max:64'],
            'password' => ['required', 'string', 'max:1024'],
            'password_confirmation' => ['required', 'string', 'max:1024'],
        ]);

        $outcome = $activation->activate($selector, $input['secret'], $input['password'], $input['password_confirmation']);

        if ($outcome->outcome === CredentialOutcome::POLICY_REJECTED) {
            throw ValidationException::withMessages($outcome->errors?->toArray() ?? ['password' => 'Choose a stronger password.']);
        }

        if (! $outcome->succeeded()) {
            throw ValidationException::withMessages(['secret' => self::INVALID_LINK]);
        }

        return redirect($origins->platformUrl('login'))
            ->with('status_message', 'Your account is ready. Sign in with your new password.');
    }
}

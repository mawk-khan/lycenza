<?php

namespace App\Http\Controllers\Auth;

use App\Domain\Identity\Application\AccountRecovery\AccountRecoveryIdentityLimiter;
use App\Domain\Identity\Application\AccountRecovery\AccountRecoveryResetService;
use App\Domain\Identity\Application\AccountRecovery\AccountRecoveryTelemetry;
use App\Domain\Identity\Application\AccountRecovery\ResetOutcome;
use App\Http\Controllers\Controller;
use App\Jobs\IssueAccountRecoveryJob;
use App\Support\Domains\CanonicalOrigin;
use App\Support\Observability\QueueName;
use App\Support\Privacy\EmailNormalizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Phase 0O.10A (ADR 0056 sections 5, 8): self-service password recovery,
 * served on the canonical PLATFORM host only (not in SchoolHostSurface: a
 * School host answers 404; no School, no TenantContext).
 *
 * - The request answers ONE generic response for every well-formed address
 *   -- unknown, eligible, root, disabled, pending invitation, suppressed,
 *   email unavailable, over the per-identity limit, recovery disabled. The
 *   request path does the same bounded local work for all of them (canonical
 *   form, keyed limiter, one encrypted job); the decision is made OFF the
 *   request path (IssueAccountRecoveryJob).
 * - The reset page never sees the secret (it is the link's #fragment) and
 *   changes nothing on GET; only the CSRF-protected POST consumes, and every
 *   credential failure answers the same "invalid or expired".
 * - No return/redirect parameter exists: success goes to the platform login.
 */
class AccountRecoveryController extends Controller
{
    public const GENERIC_STATUS = 'If an eligible account exists for that address, we have sent password reset instructions. The link expires in 30 minutes.';

    public const INVALID_LINK = 'This password reset link is invalid or has expired. Request a new one.';

    public function create(): Response
    {
        return Inertia::render('Auth/AccountRecovery/Request', [
            'status' => session('account_recovery_status'),
        ]);
    }

    public function store(Request $request, AccountRecoveryIdentityLimiter $limiter, AccountRecoveryTelemetry $telemetry): RedirectResponse
    {
        $validated = $request->validate(['email' => ['required', 'string', 'email', 'max:254']]);
        $email = EmailNormalizer::canonical($validated['email']);

        if ($limiter->attempt($email)) {
            try {
                IssueAccountRecoveryJob::dispatch($email)->onQueue(QueueName::Notifications->value);
                $telemetry->request('accepted');
            } catch (Throwable) {
                // Same response; a bounded operational signal, never the address.
                $telemetry->request('dispatch_failed');
                Log::warning('auth.account_recovery.dispatch_failed');
            }
        } else {
            $telemetry->request('rate_limited_identity');
        }

        return redirect('/account-recovery')->with('account_recovery_status', self::GENERIC_STATUS);
    }

    public function edit(string $selector): Response
    {
        // The selector is only shape-checked by the route; no lookup, no
        // state change -- a scanner's GET is harmless (ADR 0056 section 8.2).
        return Inertia::render('Auth/AccountRecovery/Reset', [
            'selector' => $selector,
            'invalidMessage' => self::INVALID_LINK,
        ]);
    }

    public function update(Request $request, string $selector, AccountRecoveryResetService $resets, CanonicalOrigin $origins): RedirectResponse
    {
        $input = $request->validate([
            'secret' => ['required', 'string', 'max:64'],
            'password' => ['required', 'string', 'max:1024'],
            'password_confirmation' => ['required', 'string', 'max:1024'],
        ]);

        $outcome = $resets->reset($selector, $input['secret'], $input['password'], $input['password_confirmation']);

        if ($outcome->outcome === ResetOutcome::INVALID) {
            throw ValidationException::withMessages(['secret' => self::INVALID_LINK]);
        }

        if ($outcome->outcome === ResetOutcome::POLICY_REJECTED) {
            throw ValidationException::withMessages($outcome->errors?->toArray() ?? ['password' => 'Choose a stronger password.']);
        }

        return redirect($origins->platformUrl('login'))
            ->with('status_message', 'Your password was changed. Sign in with your new password.');
    }
}

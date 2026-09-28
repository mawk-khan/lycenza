<?php

namespace App\Http\Controllers\Identity;

use App\Domain\Identity\Application\Staff\CredentialOutcome;
use App\Domain\Identity\Application\Staff\StaffInvitationAcceptanceService;
use App\Http\Controllers\Controller;
use App\Models\School;
use App\Support\Domains\CanonicalOrigin;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Phase 0O.12B (ADR 0059 sections 6.3, 15): accepting a staff account
 * invitation, on the School's canonical origin under the existing
 * `invitations/` School-host surface (ClassifyRequestHost serves
 * `invitations/{school}/...` on a School host only for that host's School).
 *
 * `{school}` is a plain, non-secret segment that only lets the RLS-protected
 * lookup run inside that School's context -- never authorization (rule 19).
 * The secret is the link's #fragment: the GET renders a shell and changes
 * nothing; only the CSRF-protected POST consumes. Neither `guest` nor
 * `auth`: a new person has no account, an existing one confirms while
 * signed in. Every credential failure is the same "invalid or no longer
 * valid"; whether an account exists is disclosed only after a POST with a
 * valid secret -- to the holder of the invited mailbox, never to the School.
 * No auto-login.
 */
class StaffInvitationAcceptanceController extends Controller
{
    public const INVALID_LINK = 'This invitation link is invalid or no longer valid. Ask the School to send a new one.';

    public const SIGN_IN_REQUIRED = 'An account already exists for the invited address. Sign in with that account, then open the invitation link from your email again.';

    public function show(string $school, string $selector): Response
    {
        $model = School::query()->find($school);
        $user = Auth::user();

        return Inertia::render('Invitations/StaffAccept', [
            'school' => $school,
            'selector' => $selector,
            'schoolName' => $model?->isActive() ? $model->name : null,
            'signedInName' => $user?->name,
            'invalidMessage' => self::INVALID_LINK,
        ]);
    }

    public function store(Request $request, StaffInvitationAcceptanceService $service, CanonicalOrigin $origins, string $school, string $selector): RedirectResponse
    {
        $input = $request->validate([
            'secret' => ['required', 'string', 'max:64'],
            'mode' => ['required', 'in:new,existing'],
            'name' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:1024'],
            'password_confirmation' => ['nullable', 'string', 'max:1024'],
        ]);

        $model = School::query()->find($school);

        if ($model === null) {
            throw ValidationException::withMessages(['secret' => self::INVALID_LINK]);
        }

        $signedIn = $input['mode'] === 'existing' ? Auth::user() : null;
        $outcome = $service->accept($model, $selector, $input['secret'], $signedIn, $input['name'] ?? null, $input['password'] ?? null, $input['password_confirmation'] ?? null);

        return match ($outcome->outcome) {
            CredentialOutcome::POLICY_REJECTED => throw ValidationException::withMessages($outcome->errors?->toArray() ?? ['password' => 'Choose a stronger password.']),
            CredentialOutcome::SIGN_IN_REQUIRED => throw ValidationException::withMessages(['secret' => self::SIGN_IN_REQUIRED]),
            CredentialOutcome::ACCEPTED_NEW => redirect($origins->schoolUrl($model, 'login'))
                ->with('status_message', "Your staff account at {$model->name} is ready. Sign in with your new password."),
            CredentialOutcome::ACCEPTED_EXISTING => redirect('/app')
                ->with('status_message', "You now have staff access at {$model->name}. Select it to continue."),
            default => throw ValidationException::withMessages(['secret' => self::INVALID_LINK]),
        };
    }
}

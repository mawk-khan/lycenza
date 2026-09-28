<?php

namespace App\Http\Controllers\Identity;

use App\Domain\Identity\Application\Exceptions\AccountInvitationException;
use App\Domain\Identity\Application\Exceptions\ExistingAccountConfirmationRequiredException;
use App\Domain\Identity\Application\GuardianAccountActivationService;
use App\Http\Controllers\Controller;
use App\Models\School;
use App\Support\Auth\CredentialSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Phase 5D.3 -- Identity domain. The ONLY unauthenticated route pair
 * in this checkpoint. `{school}` is a plain, non-secret route-model-bound
 * segment (Schools carry no RLS -- see the `schools`/`users` migration
 * docblocks); it exists solely so `TenantContext::withSchool()` can be
 * legitimately established BEFORE the RLS-protected
 * `identity_account_invitations` lookup runs -- it is never itself
 * treated as authorization (root CLAUDE.md rule 19). The actual secret
 * is the 64-byte random `{token}` path segment, compared only via its
 * SHA-256 hash (never logged, never persisted in plaintext -- see
 * GuardianAccountInvitation's migration docblock).
 *
 * Every failure path returns the SAME generic "invalid or expired"
 * response regardless of the underlying reason (wrong token, expired,
 * revoked, already accepted, or destination-email drift) -- brief §42,
 * no enumeration oracle.
 */
class InvitationAcceptanceController extends Controller
{
    public function show(GuardianAccountActivationService $service, string $school, string $token): Response
    {
        $schoolModel = School::query()->find($school);
        $invitation = $schoolModel === null ? null : $service->resolveUsableInvitation($schoolModel, $token);

        if ($invitation === null) {
            return Inertia::render('Invitations/Accept', ['valid' => false]);
        }

        $description = $service->describeForAcceptance($schoolModel, $invitation);
        $user = Auth::user();

        return Inertia::render('Invitations/Accept', [
            'valid' => true,
            'school' => $school,
            'token' => $token,
            'schoolName' => $schoolModel->name,
            'email' => $description['email'],
            'accountAlreadyExists' => $description['accountAlreadyExists'],
            'authenticatedAsMatchingUser' => $description['accountAlreadyExists']
                && $user !== null
                && $user->email === $description['email'],
            'authenticatedEmail' => $user?->email,
        ]);
    }

    public function store(Request $request, GuardianAccountActivationService $service, string $school, string $token): RedirectResponse
    {
        $schoolModel = School::query()->find($school);
        $invitation = $schoolModel === null ? null : $service->resolveUsableInvitation($schoolModel, $token);

        if ($invitation === null) {
            throw ValidationException::withMessages(['invitation' => ['This invitation link is no longer valid.']]);
        }

        $description = $service->describeForAcceptance($schoolModel, $invitation);
        $newPassword = null;

        if (! $description['accountAlreadyExists']) {
            $validated = $request->validate([
                'password' => ['required', 'confirmed', Password::defaults()],
            ]);
            $newPassword = $validated['password'];
        }

        try {
            $link = $service->accept($schoolModel, $invitation, Auth::user(), $newPassword);
        } catch (ExistingAccountConfirmationRequiredException $e) {
            throw ValidationException::withMessages(['invitation' => [$e->getMessage()]]);
        } catch (AccountInvitationException $e) {
            throw ValidationException::withMessages(['invitation' => [$e->getMessage()]]);
        }

        if (! $description['accountAlreadyExists']) {
            Auth::login($link->membership->user);
            $request->session()->regenerate();
            CredentialSession::stamp($request->session(), $link->membership->user);
        }

        return redirect('/app');
    }
}

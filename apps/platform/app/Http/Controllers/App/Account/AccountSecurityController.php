<?php

namespace App\Http\Controllers\App\Account;

use App\Http\Controllers\Controller;
use App\Support\Audit\AuditRecorder;
use App\Support\Auth\Exceptions\FreshPasswordConfirmationRequiredException;
use App\Support\Auth\Mfa\Exceptions\MfaException;
use App\Support\Auth\Mfa\Exceptions\MfaNotEnrolledException;
use App\Support\Auth\Mfa\MfaAuditActions;
use App\Support\Auth\Mfa\MfaChallengeService;
use App\Support\Auth\Mfa\MfaEnrollmentService;
use App\Support\Auth\Mfa\MfaFactorService;
use App\Support\Auth\Mfa\MfaQrCodeGenerator;
use App\Support\Auth\Mfa\MfaRecoveryCodeService;
use App\Support\Auth\PasswordConfirmationService;
use App\Support\Auth\RendersAuthJsonErrors;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Phase 0H.4D-P1 section 17: the User-level (NOT School-scoped)
 * "Account Security" surface -- deliberately outside `App/SchoolSetup`
 * (MFA belongs to User identity, section 10/18, not any School). Every
 * mutating action here is `auth`-only (no `school-membership`, no
 * `capability:` -- a User acts on their OWN account regardless of
 * which School is active). API representations never include
 * secret_encrypted/code_hash/consumed values (section 30) -- only the
 * plaintext secret/otpauth URI/recovery codes returned exactly once by
 * begin()/confirm()/regenerateRecoveryCodes(), matching
 * UserMfaFactor/UserMfaRecoveryCode's own #[Hidden(...)] attributes.
 *
 * Every mutating action here returns JSON (not an Inertia
 * redirect) -- called via `postJson()` (resources/js/csrf.ts) from
 * Account/Security.vue, not through Inertia's router, because several
 * of them return computed payloads (a QR code, plaintext recovery
 * codes) an Inertia redirect can't carry. MfaException/
 * FreshPasswordConfirmationRequiredException are caught explicitly and
 * converted via RendersAuthJsonErrors rather than left to propagate to
 * bootstrap/app.php's global handler, which is scoped to `/api/*` only
 * -- see RendersAuthJsonErrors's docblock.
 */
class AccountSecurityController extends Controller
{
    use RendersAuthJsonErrors;

    public function show(Request $request, MfaChallengeService $challenge, MfaRecoveryCodeService $recoveryCodes): Response
    {
        $user = $request->user();
        $factor = $challenge->activeFactorFor($user);

        return Inertia::render('Account/Security', [
            'mfa' => [
                'enabled' => $factor !== null,
                'factorType' => $factor?->type,
                'confirmedAt' => $factor?->confirmed_at?->toIso8601String(),
                'recoveryCodesRemaining' => $factor !== null ? $recoveryCodes->remainingCount($user) : 0,
            ],
        ]);
    }

    public function confirmPassword(Request $request, PasswordConfirmationService $passwordConfirmation): JsonResponse
    {
        $validated = $request->validate(['password' => ['required', 'string']]);

        // Throttling is the dedicated `mfa-password-confirmation` named
        // limiter (route middleware), not duplicated here.
        if (! $passwordConfirmation->confirm($request, $request->user(), $validated['password'])) {
            throw ValidationException::withMessages([
                'password' => 'This password does not match our records.',
            ]);
        }

        return response()->json(['confirmed' => true]);
    }

    public function beginMfaEnrollment(
        Request $request,
        PasswordConfirmationService $passwordConfirmation,
        MfaEnrollmentService $enrollment,
        MfaQrCodeGenerator $qrCode,
    ): JsonResponse {
        try {
            $passwordConfirmation->require($request);

            $result = $enrollment->begin($request->user());
        } catch (FreshPasswordConfirmationRequiredException|MfaException $e) {
            return $this->jsonError($e);
        }

        return response()->json([
            'secret' => $result['secret'],
            'otpAuthUri' => $result['otpAuthUri'],
            'qrCodeSvg' => $qrCode->svgFor($result['otpAuthUri']),
        ]);
    }

    public function confirmMfaEnrollment(Request $request, MfaEnrollmentService $enrollment): JsonResponse
    {
        $validated = $request->validate(['code' => ['required', 'string']]);

        // Throttling is the dedicated `mfa-enrollment-confirm` named
        // limiter (route middleware), not duplicated here.
        try {
            $codes = $enrollment->confirm($request->user(), $validated['code']);
        } catch (MfaException $e) {
            return $this->jsonError($e);
        }

        return response()->json(['recoveryCodes' => $codes]);
    }

    public function regenerateRecoveryCodes(
        Request $request,
        PasswordConfirmationService $passwordConfirmation,
        MfaRecoveryCodeService $recoveryCodes,
        AuditRecorder $audit,
    ): JsonResponse {
        try {
            $passwordConfirmation->require($request);

            $user = $request->user();

            if ($user->mfaFactors()->where('status', 'active')->doesntExist()) {
                throw new MfaNotEnrolledException;
            }

            $codes = $recoveryCodes->issue($user);
        } catch (FreshPasswordConfirmationRequiredException|MfaException $e) {
            return $this->jsonError($e);
        }

        $audit->platform(MfaAuditActions::RECOVERY_CODES_REGENERATED, actor: $user);

        return response()->json(['recoveryCodes' => $codes]);
    }

    public function disableMfa(
        Request $request,
        PasswordConfirmationService $passwordConfirmation,
        MfaFactorService $factors,
    ): JsonResponse {
        $validated = $request->validate(['code' => ['required', 'string']]);

        try {
            $passwordConfirmation->require($request);

            $factors->disable($request->user(), $validated['code']);
        } catch (FreshPasswordConfirmationRequiredException|MfaException $e) {
            return $this->jsonError($e);
        }

        return response()->json(['disabled' => true]);
    }
}

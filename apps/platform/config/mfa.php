<?php

// Phase 0H.4D-P1 (Staff MFA Foundation), ADR 0037. Single source of
// truth for the values the MFA architecture gate closed on -- never
// hardcode these in a controller/middleware/service.
return [
    // How long a completed MFA challenge remains "assured" for
    // MFA-gated routes before a fresh step-up challenge is required.
    // Deliberately shorter than config('session.lifetime') (120
    // minutes) -- a stale-but-technically-live session must not coast
    // indefinitely on one earlier MFA check for Highly Sensitive
    // operations.
    'assurance_window_minutes' => (int) env('MFA_ASSURANCE_WINDOW_MINUTES', 60),

    // TOTP (RFC 6238) parameters -- pragmarx/google2fa defaults
    // (30-second period, 6 digits, SHA1) are used; `window` is the
    // number of adjacent time-steps accepted either side of "now" to
    // tolerate ordinary clock skew. window=1 accepts the previous and
    // next 30-second step in addition to the current one -- NOT an
    // unbounded/silently-widened tolerance.
    'totp_window' => (int) env('MFA_TOTP_WINDOW', 1),

    // Number of single-use recovery codes issued per enrollment
    // confirmation or regeneration.
    'recovery_codes_count' => (int) env('MFA_RECOVERY_CODES_COUNT', 10),

    // How long a fresh-password-confirmation grant (see
    // App\Support\Auth\PasswordConfirmationService) remains valid
    // before an MFA-sensitive account action (begin enrollment,
    // disable, regenerate recovery codes, self-service reset) requires
    // the password to be re-entered again.
    'password_confirmation_window_minutes' => (int) env('MFA_PASSWORD_CONFIRMATION_WINDOW_MINUTES', 10),
];

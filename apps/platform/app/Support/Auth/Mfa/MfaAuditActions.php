<?php

namespace App\Support\Auth\Mfa;

/**
 * Phase 0H.4D-P1 section 21: the closed, stable vocabulary of MFA
 * audit actions, all written via AuditRecorder::platform() (the same
 * `auth.*` family LoginController already uses -- MFA is User/identity
 * level, not School-scoped). NEVER pass a secret, submitted code,
 * recovery-code plaintext/hash, or otpauth URI as metadata for any of
 * these -- see Tests\Feature\Auth\Mfa\MfaAuditPayloadSecurityTest.
 */
final class MfaAuditActions
{
    public const string ENROLLMENT_STARTED = 'auth.mfa_enrollment_started';

    public const string ENROLLED = 'auth.mfa_enrolled';

    public const string DISABLED = 'auth.mfa_disabled';

    public const string RECOVERY_CODES_REGENERATED = 'auth.mfa_recovery_codes_regenerated';

    public const string RESET_BY_ADMIN = 'auth.mfa_reset_by_admin';

    public const string CHALLENGE_FAILED = 'auth.mfa_challenge_failed';

    public const string CHALLENGE_SUCCEEDED = 'auth.mfa_challenge_succeeded';

    private function __construct() {}
}

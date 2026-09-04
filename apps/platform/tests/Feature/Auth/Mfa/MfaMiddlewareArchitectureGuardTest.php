<?php

namespace Tests\Feature\Auth\Mfa;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 0H.4D-P1 section 8/34: the `mfa` middleware is generic
 * platform infrastructure -- it must never reference Examinations,
 * marks, StudentMark, or any other future consumer module. Mirrors
 * GradeScaleArchitectureGuardTest's source-scan pattern.
 */
class MfaMiddlewareArchitectureGuardTest extends TestCase
{
    /** @return list<string> */
    private function mfaFoundationSources(): array
    {
        return [
            app_path('Http/Middleware/RequireMfa.php'),
            app_path('Support/Auth/Mfa/MfaChallengeService.php'),
            app_path('Support/Auth/Mfa/MfaEnrollmentService.php'),
            app_path('Support/Auth/Mfa/MfaFactorService.php'),
            app_path('Support/Auth/Mfa/MfaRecoveryCodeService.php'),
            app_path('Support/Auth/Mfa/MfaAdminResetService.php'),
            app_path('Support/Auth/Mfa/MfaAuditActions.php'),
        ];
    }

    private function code(string $file): string
    {
        return preg_replace('#/\*.*?\*/|//[^\n]*#s', '', file_get_contents($file));
    }

    #[Test]
    public function mfa_foundation_sources_never_reference_examinations_or_marks(): void
    {
        foreach ($this->mfaFoundationSources() as $file) {
            $code = $this->code($file);

            foreach (['Examination', 'GradeScale', 'StudentMark', 'examinations.', 'marks.'] as $forbidden) {
                $this->assertStringNotContainsString(
                    $forbidden,
                    $code,
                    "{$file} must never reference '{$forbidden}' -- MFA is generic platform infrastructure, not Examinations-specific (Phase 0H.4D-P1 section 8/34).",
                );
            }
        }
    }

    #[Test]
    public function mfa_foundation_sources_never_reference_a_third_party_sms_or_email_provider(): void
    {
        foreach ($this->mfaFoundationSources() as $file) {
            $code = $this->code($file);

            foreach (['Twilio', 'sms', 'Sms', 'SMS'] as $forbidden) {
                $this->assertStringNotContainsString(
                    $forbidden,
                    $code,
                    "{$file} must never reference '{$forbidden}' -- v1 is TOTP-only, no SMS/email factor (ADR 0037).",
                );
            }
        }
    }
}

<?php

namespace Tests\Concerns;

use App\Models\User;
use App\Models\UserMfaFactor;
use App\Models\UserMfaRecoveryCode;
use PragmaRX\Google2FA\Google2FA;

/**
 * SR.4 (ADR 0071 §26.7) test helper: sensitive actions need a fresh MFA
 * code (`mfa_code`) and sensitive reads need current MFA assurance. This
 * enrolls an ACTIVE factor once per User (directly, as CreatesMfaFixtures
 * does) and hands out its single-use recovery codes, so a suite exercising
 * those actions sends a real code through the real FreshMfaRequirement.
 *
 * - freshMfaCode($user): the next unused recovery code (enrolls first).
 * - withMfaAssurance($user): an enrolled factor plus a session verified now
 *   (web reads behind `mfa` / `mfa-page` / SensitiveReadAssurance).
 * - A bearer token minted AFTER mfaEnrolled($user) carries assurance for
 *   API reads behind `mfa`; one minted before the factor does not.
 */
trait ProvidesSensitiveActionMfa
{
    /** @var array<string, list<string>> */
    private array $sensitiveActionCodes = [];

    /** @var array<string, User> plaintext token => owner */
    private array $mfaTokenOwners = [];

    private ?User $lastMfaTokenOwner = null;

    protected function mfaEnrolled(User $user): User
    {
        if (! array_key_exists($user->id, $this->sensitiveActionCodes)) {
            if (! UserMfaFactor::query()->where('user_id', $user->id)->where('status', 'active')->exists()) {
                UserMfaFactor::create([
                    'user_id' => $user->id,
                    'type' => 'totp',
                    'secret_encrypted' => app(Google2FA::class)->generateSecretKey(),
                    'status' => 'active',
                    'confirmed_at' => now(),
                ]);
            }

            $this->sensitiveActionCodes[$user->id] = [];
        }

        return $user;
    }

    protected function freshMfaCode(User $user): string
    {
        $this->mfaEnrolled($user);

        if ($this->sensitiveActionCodes[$user->id] === []) {
            for ($i = 0; $i < 5; $i++) {
                $code = strtoupper(bin2hex(random_bytes(5)));
                UserMfaRecoveryCode::create(['user_id' => $user->id, 'code_hash' => $code]);
                $this->sensitiveActionCodes[$user->id][] = $code;
            }
        }

        return array_shift($this->sensitiveActionCodes[$user->id]);
    }

    /** A bearer token minted AFTER the owner's factor: it carries MFA assurance for API reads. */
    protected function mfaToken(User $user, string $name = 'test-device'): string
    {
        $this->mfaEnrolled($user);
        $token = $user->createToken($name)->plainTextToken;
        $this->mfaTokenOwners[$token] = $user;
        $this->lastMfaTokenOwner = $user;

        return $token;
    }

    /** `['mfa_code' => …]` for the owner of $token (or of the last token minted). */
    protected function mfaBody(?string $token = null): array
    {
        $owner = $token !== null ? $this->mfaTokenOwners[$token] : $this->lastMfaTokenOwner;
        assert($owner instanceof User);

        return ['mfa_code' => $this->freshMfaCode($owner)];
    }

    /**
     * For a suite whose existing requests predate SR.4: adds a fresh code
     * for the bearer token currently in the Authorization header when $uri
     * matches $pattern and the request carries none. Tokens must come from
     * mfaToken(); an unknown token gets nothing (and is refused).
     *
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    protected function withStepUpCode(string $uri, array $data, string $pattern): array
    {
        $header = $this->defaultHeaders['Authorization'] ?? '';
        $token = str_starts_with($header, 'Bearer ') ? substr($header, 7) : null;

        if (preg_match($pattern, $uri) === 1 && ! array_key_exists('mfa_code', $data) && $token !== null && isset($this->mfaTokenOwners[$token])) {
            $data += $this->mfaBody($token);
        }

        return $data;
    }

    /**
     * For a session-authenticated suite whose existing requests predate
     * SR.4, called from an overridden call(): a write to a $writePattern
     * path gets a fresh code for the signed-in user (form or JSON body), and
     * a GET of a $readPattern path gets current MFA assurance.
     *
     * @param  array<mixed>  $parameters
     */
    protected function applySensitiveActionMfa(string $method, string $uri, array &$parameters, ?string &$content, string $writePattern, ?string $readPattern = null): void
    {
        $user = $this->app['auth']->guard()->user();
        if (! $user instanceof User) {
            return;
        }

        $path = (string) (parse_url($uri, PHP_URL_PATH) ?? $uri);

        if ($method !== 'GET' && preg_match($writePattern, $path) === 1) {
            $decoded = $content !== null && $content !== '' ? json_decode($content, true) : null;
            if (is_array($decoded)) {
                if (! array_key_exists('mfa_code', $decoded)) {
                    $decoded['mfa_code'] = $this->freshMfaCode($user);
                    $content = json_encode($decoded, JSON_THROW_ON_ERROR);
                }
            } elseif (! array_key_exists('mfa_code', $parameters)) {
                $parameters['mfa_code'] = $this->freshMfaCode($user);
            }
        }

        if ($readPattern !== null && $method === 'GET' && preg_match($readPattern, $path) === 1) {
            $this->withMfaAssurance($user);
        }
    }

    /** An enrolled factor and a session verified now -- current MFA assurance for web reads. */
    protected function withMfaAssurance(User $user): static
    {
        $this->mfaEnrolled($user);

        return $this->withSession(['mfa_verified_at' => now()->toIso8601String()]);
    }
}

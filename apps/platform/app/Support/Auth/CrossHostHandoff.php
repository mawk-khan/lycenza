<?php

namespace App\Support\Auth;

use App\Models\School;
use App\Models\User;
use Illuminate\Contracts\Cache\Factory;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Config\Repository as Config;
use Throwable;

/**
 * Phase 0O.8A (ADR 0054 amendment, section 8.7): sign-in CONTINUITY across
 * Lycenza origins whose host-only cookies cannot be shared (the platform
 * host and each School's custom domain). Cookies stay host-only; a
 * deliberate School switch that changes origin carries the login with a
 * ticket instead:
 *
 * - opaque: 256 random bits (43 base64url characters); the browser learns
 *   nothing from it. Everything it stands for lives server-side, in the
 *   handoff store (Redis in production), under sha256(ticket);
 * - bound to the user, the source session, the exact target School (or the
 *   platform, with none), the exact target hostname and the one purpose
 *   `school-switch`; 60 s lifetime;
 * - single use: consumption first claims `used:` atomically (Cache::add --
 *   an atomic SET NX on Redis), so of two concurrent redemptions exactly one
 *   proceeds; then reads and deletes the ticket;
 * - fail closed: if the store is unavailable no ticket is issued or
 *   redeemed (ordinary same-host requests never touch the store).
 *
 * A ticket is NOT authority: redemption (SessionHandoffController) re-checks
 * the user, the source session, the School's state, the membership and the
 * Host before it signs anyone in, and carries the source session's MFA
 * assurance time unchanged (never refreshes it).
 */
final class CrossHostHandoff
{
    public const LIFETIME_SECONDS = 60;

    public const PURPOSE = 'school-switch';

    public const TICKET_PATTERN = '/^[A-Za-z0-9_-]{43}$/';

    private const PREFIX = 'session-handoff:';

    public function __construct(private readonly Factory $caches, private readonly Config $config) {}

    /**
     * @throws HandoffUnavailable
     */
    public function issue(User $user, string $sourceSessionId, ?School $school, string $targetHost, ?string $mfaVerifiedAt): string
    {
        $ticket = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $now = time();

        try {
            $stored = $this->store()->add(self::PREFIX.hash('sha256', $ticket), [
                'user_id' => $user->id,
                'source_session' => $sourceSessionId,
                'school_id' => $school?->id,
                'target_host' => $targetHost,
                'purpose' => self::PURPOSE,
                'mfa_verified_at' => $mfaVerifiedAt,
                // ADR 0056 section 11.2: redemption refuses a changed credential.
                'credential_version' => CredentialSession::current($user),
                'created_at' => $now,
                'expires_at' => $now + self::LIFETIME_SECONDS,
            ], self::LIFETIME_SECONDS);
        } catch (Throwable) {
            throw new HandoffUnavailable;
        }

        if (! $stored) {
            throw new HandoffUnavailable;
        }

        return $ticket;
    }

    /**
     * Redeems a ticket exactly once. Null when it is malformed, unknown,
     * expired or already redeemed (indistinguishable on purpose).
     *
     * @return array{user_id: string, source_session: string, school_id: string|null, target_host: string, purpose: string, mfa_verified_at: string|null, credential_version?: int, created_at: int, expires_at: int}|null
     *
     * @throws HandoffUnavailable
     */
    public function consume(string $ticket): ?array
    {
        if (preg_match(self::TICKET_PATTERN, $ticket) !== 1) {
            return null;
        }

        $key = self::PREFIX.hash('sha256', $ticket);

        try {
            $store = $this->store();

            if (! $store->add($key.':used', 1, self::LIFETIME_SECONDS)) {
                return null;
            }

            $data = $store->get($key);
            $store->forget($key);
        } catch (Throwable) {
            throw new HandoffUnavailable;
        }

        if (! is_array($data) || ($data['purpose'] ?? null) !== self::PURPOSE || (int) ($data['expires_at'] ?? 0) < time()) {
            return null;
        }

        /** @var array{user_id: string, source_session: string, school_id: string|null, target_host: string, purpose: string, mfa_verified_at: string|null, credential_version?: int, created_at: int, expires_at: int} $data */
        return $data;
    }

    private function store(): Repository
    {
        return $this->caches->store($this->config->get('domains.handoff_store'));
    }
}

<?php

namespace App\Support\Api;

use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Phase 0O.3 (ADR 0049 section 2): a person's own API tokens, from the
 * Account/Security area. Issuance is gated by the caller
 * (App\Http\Controllers\App\Account\ApiTokenController) on an enrolled MFA
 * factor and a fresh code; this service fixes the rest: only for oneself,
 * closed scopes, a finite expiry within the owner-approved range, the
 * secret returned once, and `auth.api_token.issued` /
 * `auth.api_token.revoked` in the platform audit ledger (identifiers and
 * codes only -- never the token, its hash or its free-text name).
 * Revocation deletes the row, so the very next request fails (the Sanctum
 * guard reads the token from the database on every request).
 */
class HumanApiTokenService
{
    public const ISSUED = 'auth.api_token.issued';

    public const REVOKED = 'auth.api_token.revoked';

    public function __construct(private readonly AuditRecorder $audit) {}

    /**
     * @param  list<string>  $scopes
     * @return array{0: PersonalAccessToken, 1: string} the token row and its value, shown once
     */
    public function issue(User $owner, string $name, array $scopes, int $lifetimeDays): array
    {
        $name = trim($name);

        if ($name === '' || mb_strlen($name) > 100) {
            throw ValidationException::withMessages(['name' => 'Give the token a name of at most 100 characters.']);
        }

        $scopes = array_values(array_unique($scopes));
        if (! ApiScope::isValidHumanSet($scopes)) {
            throw ValidationException::withMessages(['scopes' => 'Choose at least one of the available scopes.']);
        }

        if ($lifetimeDays < 1 || $lifetimeDays > HumanApiTokenLifetime::MAX_DAYS) {
            throw ValidationException::withMessages(['lifetime_days' => 'A token lasts between 1 and '.HumanApiTokenLifetime::MAX_DAYS.' days.']);
        }

        return DB::transaction(function () use ($owner, $name, $scopes, $lifetimeDays): array {
            $new = $owner->createToken($name, $scopes, now()->addDays($lifetimeDays));
            /** @var PersonalAccessToken $token */
            $token = $new->accessToken;

            // No subject: Sanctum token ids are integers; the id is in metadata.
            $this->audit->platform(self::ISSUED, actor: $owner, metadata: [
                'token_id' => (string) $token->getKey(),
                'scopes' => $scopes,
                'expires_at' => $token->expires_at?->toIso8601String(),
            ]);

            return [$token, $new->plainTextToken];
        });
    }

    public function revoke(User $owner, string $tokenId): void
    {
        DB::transaction(function () use ($owner, $tokenId): void {
            $token = $owner->tokens()->whereKey($tokenId)->lockForUpdate()->first();

            if ($token === null) {
                throw ValidationException::withMessages(['token' => 'That token does not exist or is already revoked.']);
            }

            $token->delete();

            $this->audit->platform(self::REVOKED, actor: $owner, metadata: [
                'token_id' => (string) $tokenId,
            ]);
        });
    }

    /**
     * The owner's live tokens -- metadata only, never the hash.
     *
     * @return list<array<string, mixed>>
     */
    public function listFor(User $owner): array
    {
        return $owner->tokens()->where('expires_at', '>', now())->orderByDesc('created_at')->get()
            ->map(fn (PersonalAccessToken $t): array => [
                'id' => (string) $t->getKey(),
                'name' => $t->name,
                'scopes' => $t->abilities,
                'createdAt' => $t->created_at?->toIso8601String(),
                'expiresAt' => $t->expires_at?->toIso8601String(),
                'lastUsedAt' => $t->last_used_at?->toIso8601String(),
            ])->values()->all();
    }
}

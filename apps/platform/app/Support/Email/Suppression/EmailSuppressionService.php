<?php

namespace App\Support\Email\Suppression;

use App\Models\EmailSuppression;
use App\Models\User;
use App\Support\Email\EmailKind;
use App\Support\Privacy\EmailNormalizer;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * ADR 0055 section 12: the GLOBAL platform suppression list. It protects
 * the shared sending reputation for every School, so it is platform data
 * (no RLS, never readable by a School) holding keyed fingerprints only.
 *
 * Scope: `all` blocks every class; `standard` blocks standard mail only.
 * Critical mail respects `all` (never bypasses suppression); emergency
 * Communications timing never touches this list. Suppression is NOT a
 * Communications preference or consent, and never an unsubscribe.
 *
 * Addresses are normalized exactly as the application stores them
 * (App\Support\Privacy\EmailNormalizer: trim + lowercase, no provider
 * aliasing) before fingerprinting.
 */
final class EmailSuppressionService
{
    public const SCOPES = ['all', 'standard'];

    public const REASONS = ['hard_bounce', 'complaint', 'provider_suppressed', 'operator'];

    public function __construct(
        private readonly SuppressionKeyRing $ring,
        private readonly EmailNormalizer $normalizer,
    ) {}

    /**
     * @return array<string, string> key id => HMAC-SHA256 fingerprint, current key first
     *
     * @throws InvalidArgumentException for a value that is not an address
     */
    public function fingerprints(string $address): array
    {
        $normalized = $this->normalizer->normalize($address);

        return array_map(fn (string $key) => hash_hmac('sha256', $normalized, $key), $this->ring->all());
    }

    /**
     * The active suppression that blocks this address for this kind of mail,
     * or null. A match found only under the previous key is re-recorded under
     * the current key (rehash on observation).
     */
    public function blocking(string $address, EmailKind $kind): ?EmailSuppression
    {
        try {
            $fingerprints = $this->fingerprints($address);
        } catch (InvalidArgumentException) {
            return null;
        }

        $match = EmailSuppression::query()->active()
            ->whereIn('scope', $kind === EmailKind::Critical ? ['all'] : self::SCOPES)
            ->where(function ($query) use ($fingerprints): void {
                foreach ($fingerprints as $keyId => $fingerprint) {
                    $query->orWhere(fn ($q) => $q->where('key_id', $keyId)->where('address_fingerprint', $fingerprint));
                }
            })
            ->orderByRaw("CASE scope WHEN 'all' THEN 0 ELSE 1 END")
            ->first();

        $currentId = array_key_first($fingerprints);
        if ($match !== null && $match->key_id !== $currentId) {
            $this->record($fingerprints[$currentId], $currentId, $match->scope, $match->reason, $match->source_event_id, $match->source_email_message_id);
        }

        return $match;
    }

    /**
     * Idempotent: an existing active suppression of the same (or wider) scope
     * is returned instead of a duplicate.
     */
    public function suppress(string $address, string $scope, string $reason, ?string $sourceEventId = null, ?string $sourceMessageId = null): ?EmailSuppression
    {
        if (! in_array($scope, self::SCOPES, true) || ! in_array($reason, self::REASONS, true)) {
            throw new InvalidArgumentException('Unknown suppression scope or reason.');
        }

        try {
            $fingerprints = $this->fingerprints($address);
        } catch (InvalidArgumentException) {
            return null;
        }

        $currentId = (string) array_key_first($fingerprints);

        $existing = EmailSuppression::query()->active()
            ->where('key_id', $currentId)
            ->where('address_fingerprint', $fingerprints[$currentId])
            ->whereIn('scope', $scope === 'all' ? ['all'] : self::SCOPES)
            ->first();

        return $existing ?? $this->record($fingerprints[$currentId], $currentId, $scope, $reason, $sourceEventId, $sourceMessageId);
    }

    /**
     * Operator release (platform:mail-suppression-release): every active row
     * for this address, under every ring key. Returns how many were released.
     */
    public function release(string $address, ?User $operator, string $reason): int
    {
        $fingerprints = $this->fingerprints($address);

        return EmailSuppression::query()->active()
            ->where(function ($query) use ($fingerprints): void {
                foreach ($fingerprints as $keyId => $fingerprint) {
                    $query->orWhere(fn ($q) => $q->where('key_id', $keyId)->where('address_fingerprint', $fingerprint));
                }
            })
            ->update(['released_at' => now(), 'released_by_user_id' => $operator?->id, 'release_reason' => $reason]);
    }

    /** Active suppressions whose key id is no longer in the ring (unmatchable). */
    public function orphanedCount(): int
    {
        return EmailSuppression::query()->active()->whereNotIn('key_id', array_keys($this->ring->all()))->count();
    }

    /** Active suppressions still under the previous ring key. */
    public function previousKeyCount(): int
    {
        $ids = array_keys($this->ring->all());

        return count($ids) < 2 ? 0 : EmailSuppression::query()->active()->where('key_id', $ids[1])->count();
    }

    private function record(string $fingerprint, string $keyId, string $scope, string $reason, ?string $sourceEventId, ?string $sourceMessageId): ?EmailSuppression
    {
        try {
            return DB::transaction(fn () => EmailSuppression::query()->create([
                'key_id' => $keyId,
                'address_fingerprint' => $fingerprint,
                'scope' => $scope,
                'reason' => $reason,
                'source_event_id' => $sourceEventId,
                'source_email_message_id' => $sourceMessageId,
            ]));
        } catch (UniqueConstraintViolationException) {
            // A concurrent writer recorded the same active suppression.
            return EmailSuppression::query()->active()->where('key_id', $keyId)->where('address_fingerprint', $fingerprint)->where('scope', $scope)->first();
        }
    }
}

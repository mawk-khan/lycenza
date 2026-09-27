<?php

namespace App\Support\Email\Suppression;

use App\Support\Email\EmailConfigurationException;
use Illuminate\Contracts\Config\Repository;

/**
 * ADR 0055 section 12.3, amended by Phase 0O.9A: the suppression HMAC keys
 * form a bounded ring -- the CURRENT key plus at most one PREVIOUS key,
 * each with a stable id. A replace-in-place key would make every existing
 * fingerprint unmatchable, i.e. silently drop every suppression.
 *
 * - New suppressions are fingerprinted with the current key.
 * - Lookups compute the fingerprint under EVERY ring key and match a row
 *   only under the key id it records.
 * - A match found only under the previous key is re-recorded under the
 *   current key the next time the address is seen (rehash on observation;
 *   the plaintext address exists at that moment).
 * - `platform:mail-suppression-rekey` re-keys what the repository can
 *   honestly reconstruct (suppressions whose source message still holds
 *   its encrypted recipient). The rest can NOT be re-keyed -- an HMAC is
 *   one-way -- so the previous key must stay in the ring until those rows
 *   are released or retired; `platform:mail-status` reports how many remain.
 */
final class SuppressionKeyRing
{
    public const MIN_KEY_LENGTH = 32;

    public function __construct(private readonly Repository $config) {}

    /** @return array{id: string, key: string} */
    public function current(): array
    {
        $key = (string) $this->config->get('email.suppression.key');
        $id = (string) $this->config->get('email.suppression.key_id');

        if (! self::validKey($key) || ! self::validId($id)) {
            throw new EmailConfigurationException('mail_suppression_keys_invalid');
        }

        return ['id' => $id, 'key' => $key];
    }

    /** @return array<string, string> key id => key, current first */
    public function all(): array
    {
        $current = $this->current();
        $ring = [$current['id'] => $current['key']];

        $previous = (string) $this->config->get('email.suppression.previous_key');
        $previousId = (string) $this->config->get('email.suppression.previous_key_id');

        if ($previous !== '' || $previousId !== '') {
            if (! self::validKey($previous) || ! self::validId($previousId) || $previousId === $current['id'] || hash_equals($current['key'], $previous)) {
                throw new EmailConfigurationException('mail_suppression_keys_invalid');
            }
            $ring[$previousId] = $previous;
        }

        return $ring;
    }

    /** Null when the configured ring is valid; otherwise the violation code. */
    public function violation(): ?string
    {
        try {
            $this->all();

            return null;
        } catch (EmailConfigurationException $e) {
            return $e->reason;
        }
    }

    public static function validKey(string $key): bool
    {
        return strlen($key) >= self::MIN_KEY_LENGTH;
    }

    public static function validId(string $id): bool
    {
        return preg_match('/^[a-z0-9][a-z0-9._-]{0,31}$/', $id) === 1;
    }
}

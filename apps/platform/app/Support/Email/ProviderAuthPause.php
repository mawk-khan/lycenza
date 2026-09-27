<?php

namespace App\Support\Email;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Carbon;

/**
 * ADR 0055 section 10 (brief step 36): a provider authentication or TLS
 * configuration failure is an OPERATOR problem. For a bounded pause no
 * message contacts the provider at all -- every message waits, durably --
 * instead of each one burning its retries against a provider that refuses
 * the credential. Platform-level state (no tenant data), in the shared
 * cache so every worker observes it.
 */
final class ProviderAuthPause
{
    public const KEY = 'email:provider-auth-pause-until';

    public function __construct(private readonly Cache $cache) {}

    public function open(int $seconds): Carbon
    {
        $until = now()->addSeconds($seconds);
        $this->cache->put(self::KEY, $until->getTimestamp(), $seconds);

        return $until;
    }

    public function until(): ?Carbon
    {
        $at = $this->cache->get(self::KEY);

        return is_numeric($at) && (int) $at > now()->getTimestamp() ? Carbon::createFromTimestamp((int) $at) : null;
    }

    public function clear(): void
    {
        $this->cache->forget(self::KEY);
    }
}

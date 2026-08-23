<?php

namespace App\Support\ServiceIdentities;

use App\Models\ServiceIdentity;

/**
 * Section 25-27: a service token authenticating successfully must NOT
 * mean "access everything." This resolves a presented raw credential
 * to its ServiceIdentity (verification-only -- the raw credential is
 * never stored, only its SHA-256 hash, section 27) and exposes the
 * capability check every internal endpoint must perform before acting.
 */
class ServiceIdentityAuthenticator
{
    public function resolve(?string $presentedCredential): ?ServiceIdentity
    {
        if ($presentedCredential === null || $presentedCredential === '') {
            return null;
        }

        $hash = hash('sha256', $presentedCredential);

        $identity = ServiceIdentity::query()
            ->where('credential_hash', $hash)
            ->where('enabled', true)
            ->first();

        if ($identity !== null) {
            $identity->forceFill(['last_used_at' => now()])->saveQuietly();
        }

        return $identity;
    }

    /**
     * Section 26: Service identity + service capability + (when
     * required) trusted School context + requested action -- never
     * "the token was valid, therefore allowed."
     */
    public function authorize(?ServiceIdentity $identity, string $capability): bool
    {
        return $identity !== null && $identity->hasCapability($capability);
    }
}

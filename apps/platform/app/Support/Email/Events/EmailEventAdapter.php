<?php

namespace App\Support\Email\Events;

use Illuminate\Http\Request;

/**
 * ADR 0055 section 11.2: a provider's event feed -- how its webhook is
 * AUTHENTICATED and how its payload is NORMALIZED. Both are
 * provider-specific; no generic "canonical signature" is invented here. A
 * vendor's adapter arrives with the vendor selection. Only the test/local
 * FakeEmailEventAdapter exists, and production refuses it.
 *
 * An adapter must:
 * - authenticate with the provider's signature or shared secret against the
 *   CONFIGURED 1-2 entry secret ring (rotation overlap), with a timestamp
 *   tolerance of at most 5 minutes where its scheme carries one;
 * - never trust a source IP alone, and never fetch a key from a URL the
 *   caller supplied;
 * - keep vendor event names inside itself, mapping them to EmailEventType.
 */
interface EmailEventAdapter
{
    public function provider(): string;

    /** Constant-time; false for a missing, wrong, stale or unconfigured credential. */
    public function authenticate(Request $request): bool;

    /**
     * @param  array<mixed>  $payload  the decoded (depth-bounded) JSON body
     * @return list<NormalizedEmailEvent>
     *
     * @throws InvalidEmailEventPayload
     */
    public function normalize(array $payload): array;
}

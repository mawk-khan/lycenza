<?php

namespace App\Support\Webhooks;

/**
 * HMAC-SHA256 webhook signing (section 22/25). Production headers
 * (App\Jobs\DeliverWebhookJob) carry the timestamp, signature version,
 * and raw hex signature in their OWN dedicated headers --
 * `X-SchoolOS-Timestamp` / `X-SchoolOS-Signature-Version` /
 * `X-SchoolOS-Signature` -- built from sign() alone, NOT header()'s
 * combined "t=...,v1=..." single-header string (header() is kept as a
 * documented alternative/reference format only, e.g. for a future
 * integration modeled more closely on a single-header convention; it
 * is not what this codebase's own delivery job sends). Signed material
 * is `{timestamp}.{deliveryId}.{body}`; the delivery id binds the
 * signature to one specific delivery attempt (replay of a captured
 * signature against a DIFFERENT delivery id fails), and the timestamp
 * + a verifier-side tolerance window is the replay-protection
 * mechanism (see `verify()`'s docblock).
 */
class WebhookSigner
{
    public function sign(string $secret, string $deliveryId, int $timestamp, string $body): string
    {
        return hash_hmac('sha256', "{$timestamp}.{$deliveryId}.{$body}", $secret);
    }

    /**
     * An alternative, Stripe-style combined single-header format
     * ("t=<timestamp>,v1=<signature>") -- NOT what
     * App\Jobs\DeliverWebhookJob actually sends (see class docblock);
     * kept as a documented, tested reference format only.
     */
    public function header(string $secret, string $deliveryId, int $timestamp, string $body): string
    {
        return "t={$timestamp},v1={$this->sign($secret, $deliveryId, $timestamp, $body)}";
    }

    /**
     * Verification pseudocode for third-party developers (documented
     * again, in full, in docs/security/INTEGRATION-SECURITY.md), for
     * THIS codebase's actual separate-headers contract:
     *
     *   1. Read X-SchoolOS-Timestamp, X-SchoolOS-Delivery-Id, and
     *      X-SchoolOS-Signature.
     *   2. Reject if abs(now() - timestamp) > tolerance (default 300s)
     *      -- this is the replay-protection check.
     *   3. Recompute HMAC-SHA256 of "{timestamp}.{deliveryId}.{rawBody}"
     *      using your stored webhook secret.
     *   4. Compare using a constant-time comparison (hash_equals or
     *      equivalent) against X-SchoolOS-Signature. Reject on mismatch.
     *   5. During a secret rotation window, try BOTH your old and new
     *      secret if you were issued both (section 9).
     */
    public function verify(
        string $secret,
        string $deliveryId,
        int $timestamp,
        string $body,
        string $signature,
        int $toleranceSeconds = 300,
    ): bool {
        if (abs(time() - $timestamp) > $toleranceSeconds) {
            return false;
        }

        return hash_equals($this->sign($secret, $deliveryId, $timestamp, $body), $signature);
    }
}

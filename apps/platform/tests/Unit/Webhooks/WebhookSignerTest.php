<?php

namespace Tests\Unit\Webhooks;

use App\Support\Webhooks\WebhookSigner;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 0C.3 section 91: the exact HMAC-SHA256/timestamp-tolerance
 * contract documented in docs/security/INTEGRATION-SECURITY.md.
 */
class WebhookSignerTest extends TestCase
{
    private WebhookSigner $signer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->signer = new WebhookSigner;
    }

    #[Test]
    public function a_correctly_signed_body_verifies(): void
    {
        $timestamp = time();
        $signature = $this->signer->sign('secret', 'delivery-1', $timestamp, '{"a":1}');

        $this->assertTrue($this->signer->verify('secret', 'delivery-1', $timestamp, '{"a":1}', $signature));
    }

    #[Test]
    public function a_modified_body_fails_verification(): void
    {
        $timestamp = time();
        $signature = $this->signer->sign('secret', 'delivery-1', $timestamp, '{"a":1}');

        $this->assertFalse($this->signer->verify('secret', 'delivery-1', $timestamp, '{"a":2}', $signature));
    }

    #[Test]
    public function an_incorrect_secret_fails_verification(): void
    {
        $timestamp = time();
        $signature = $this->signer->sign('secret', 'delivery-1', $timestamp, '{"a":1}');

        $this->assertFalse($this->signer->verify('wrong-secret', 'delivery-1', $timestamp, '{"a":1}', $signature));
    }

    #[Test]
    public function an_expired_timestamp_fails_verification(): void
    {
        $timestamp = time() - 330; // well past the window: a 1 s margin is flaky across a second boundary
        $signature = $this->signer->sign('secret', 'delivery-1', $timestamp, '{"a":1}');

        $this->assertFalse($this->signer->verify('secret', 'delivery-1', $timestamp, '{"a":1}', $signature, toleranceSeconds: 300));
    }

    #[Test]
    public function a_future_timestamp_beyond_tolerance_fails_verification(): void
    {
        $timestamp = time() + 330; // well past the window: a 1 s margin is flaky across a second boundary
        $signature = $this->signer->sign('secret', 'delivery-1', $timestamp, '{"a":1}');

        $this->assertFalse($this->signer->verify('secret', 'delivery-1', $timestamp, '{"a":1}', $signature, toleranceSeconds: 300));
    }

    #[Test]
    public function a_timestamp_just_within_tolerance_still_verifies(): void
    {
        $timestamp = time() - 299;
        $signature = $this->signer->sign('secret', 'delivery-1', $timestamp, '{"a":1}');

        $this->assertTrue($this->signer->verify('secret', 'delivery-1', $timestamp, '{"a":1}', $signature, toleranceSeconds: 300));
    }

    #[Test]
    public function a_signature_computed_for_a_different_delivery_id_fails_verification(): void
    {
        $timestamp = time();
        $signature = $this->signer->sign('secret', 'delivery-1', $timestamp, '{"a":1}');

        $this->assertFalse($this->signer->verify('secret', 'delivery-2', $timestamp, '{"a":1}', $signature));
    }

    #[Test]
    public function the_header_format_is_stripe_style_t_and_v1(): void
    {
        $header = $this->signer->header('secret', 'delivery-1', 1700000000, '{}');

        $this->assertMatchesRegularExpression('/^t=\d+,v1=[0-9a-f]{64}$/', $header);
    }

    #[Test]
    public function the_previous_secret_still_verifies_during_a_rotation_overlap_window(): void
    {
        // Section 9/91: a receiver documented to try both secrets
        // during rotation succeeds with either one independently --
        // WebhookSigner itself is secret-agnostic; DeliverWebhookJob
        // always signs with the CURRENT secret, but a receiver holding
        // the OLD secret must still be able to verify a signature
        // computed with that old secret's own value.
        $timestamp = time();
        $oldSecret = 'old-secret';
        $signature = $this->signer->sign($oldSecret, 'delivery-1', $timestamp, '{}');

        $this->assertTrue($this->signer->verify($oldSecret, 'delivery-1', $timestamp, '{}', $signature));
        $this->assertFalse($this->signer->verify('new-secret', 'delivery-1', $timestamp, '{}', $signature));
    }
}

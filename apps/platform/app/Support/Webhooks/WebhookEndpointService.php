<?php

namespace App\Support\Webhooks;

use App\Models\School;
use App\Models\User;
use App\Models\WebhookEndpoint;
use App\Support\Audit\AuditRecorder;

/**
 * The only sanctioned write path for webhook endpoints. Every
 * significant change is audited (section 49). Event subscriptions are
 * managed separately -- see WebhookSubscriptionService.
 */
class WebhookEndpointService
{
    public function __construct(
        private readonly SsrfSafeUrlValidator $ssrf,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * @return array{0: WebhookEndpoint, 1: string} the endpoint and its plaintext secret (shown once, section 7)
     */
    public function create(School $school, string $name, string $url, ?User $actor = null): array
    {
        $this->ssrf->assertSafeAndResolve($url);

        $secret = $this->generateSecret();

        $endpoint = WebhookEndpoint::query()->create([
            'school_id' => $school->id,
            'name' => $name,
            'url' => $url,
            'secret_encrypted' => $secret,
            'status' => 'active',
            'created_by_user_id' => $actor?->id,
        ]);

        $this->audit->school($school, 'integrations.webhook_endpoint.created', actor: $actor, subject: $endpoint, metadata: [
            'url' => $url,
            'name' => $name,
        ]);

        return [$endpoint, $secret];
    }

    /**
     * Rotates the signing secret with an overlap window (section 9) --
     * the previous secret keeps verifying until it expires, so the
     * subscriber has time to update without missed/rejected deliveries.
     *
     * @return string the new plaintext secret (shown once, section 7)
     */
    public function rotateSecret(WebhookEndpoint $endpoint, ?User $actor = null): string
    {
        $newSecret = $this->generateSecret();

        $endpoint->update([
            'previous_secret_encrypted' => $endpoint->secret_encrypted,
            'previous_secret_expires_at' => now()->addHours((int) config('webhooks.secret_rotation_overlap_hours')),
            'secret_encrypted' => $newSecret,
        ]);

        $this->audit->school($endpoint->school, 'integrations.webhook_endpoint.secret_rotated', actor: $actor, subject: $endpoint);

        return $newSecret;
    }

    public function disable(WebhookEndpoint $endpoint, ?User $actor = null): void
    {
        $endpoint->update(['status' => 'disabled', 'disabled_at' => now()]);
        $this->audit->school($endpoint->school, 'integrations.webhook_endpoint.disabled', actor: $actor, subject: $endpoint);
    }

    public function enable(WebhookEndpoint $endpoint, ?User $actor = null): void
    {
        $endpoint->update(['status' => 'active', 'disabled_at' => null]);
        $this->audit->school($endpoint->school, 'integrations.webhook_endpoint.enabled', actor: $actor, subject: $endpoint);
    }

    /**
     * Section 8: a cryptographically secure random secret, generated
     * with PHP's CSPRNG (`random_bytes`) and safely hex-encoded --
     * never `uniqid()`, `mt_rand()`, or a UUID (a UUID is designed to
     * be unique, not unguessable).
     */
    private function generateSecret(): string
    {
        return bin2hex(random_bytes(32));
    }
}

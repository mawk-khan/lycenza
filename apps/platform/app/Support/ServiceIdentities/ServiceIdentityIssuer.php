<?php

namespace App\Support\ServiceIdentities;

use App\Models\ServiceIdentity;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Support\Str;

/**
 * The only sanctioned way to create/manage service identities --
 * mirrors WebhookEndpointService's shape. Every change is audited
 * (section 45).
 */
class ServiceIdentityIssuer
{
    /**
     * Phase 0O.1: the capabilities a service identity may be issued with
     * from the operator console -- exactly the ones an internal-service
     * route checks (`ai-service:` middleware). Nothing else.
     */
    public const ISSUABLE_CAPABILITIES = ['ai.tools.invoke', 'ai.audit.write'];

    public function __construct(private readonly AuditRecorder $audit) {}

    /**
     * @param  array<int, string>  $capabilities
     * @return array{0: ServiceIdentity, 1: string} the identity and its plaintext credential (shown once)
     */
    public function issue(string $slug, string $name, array $capabilities, ?User $actor = null): array
    {
        $credential = Str::random(64);

        $identity = ServiceIdentity::query()->create([
            'slug' => $slug,
            'name' => $name,
            'credential_hash' => hash('sha256', $credential),
            'enabled' => true,
        ]);

        $identity->capabilities()->sync($capabilities);

        $this->audit->platform('platform.service_identity.issued', actor: $actor, subject: $identity, metadata: [
            'slug' => $slug,
            'capabilities' => $capabilities,
        ]);

        return [$identity, $credential];
    }

    public function disable(ServiceIdentity $identity, ?User $actor = null): void
    {
        $identity->update(['enabled' => false]);
        $this->audit->platform('platform.service_identity.disabled', actor: $actor, subject: $identity);
    }

    public function enable(ServiceIdentity $identity, ?User $actor = null): void
    {
        $identity->update(['enabled' => true]);
        $this->audit->platform('platform.service_identity.enabled', actor: $actor, subject: $identity);
    }
}

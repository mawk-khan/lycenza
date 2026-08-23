<?php

namespace Database\Seeders;

use App\Models\ServiceIdentity;
use Illuminate\Database\Seeder;

/**
 * Local/dev/test bootstrap only: creates the "ai-gateway" service
 * identity from AI_GATEWAY_SERVICE_TOKEN so the existing env-configured
 * token keeps working after the Phase 0C migration from a flat shared-
 * secret check to real ServiceIdentity + capability enforcement
 * (section 25-27). A real deployment issues service credentials via
 * App\Support\ServiceIdentities\ServiceIdentityIssuer (which shows the
 * plaintext credential exactly once) rather than reading it back out of
 * an env var like this seeder does for local convenience.
 */
class ServiceIdentitySeeder extends Seeder
{
    public function run(): void
    {
        $token = (string) config('services.ai_gateway.service_token');

        if ($token === '') {
            return;
        }

        $identity = ServiceIdentity::query()->updateOrCreate(
            ['slug' => 'ai-gateway'],
            ['name' => 'AI Gateway', 'credential_hash' => hash('sha256', $token), 'enabled' => true],
        );

        $identity->capabilities()->sync(['ai.tools.invoke', 'ai.audit.write']);
    }
}

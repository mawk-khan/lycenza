<?php

namespace App\Support\ApiClients;

use App\Models\ApiClient;
use App\Models\School;
use App\Models\WebhookEndpoint;
use App\Support\Setup\SetupReadiness;
use App\Support\Setup\SetupReadinessProvider;
use App\Support\Setup\SetupStatus;

/**
 * ORG.12A (ADR 0080 §10): Integrations readiness -- whether an active
 * partner API client or webhook endpoint exists. EXISTS answers only: no
 * name, URL, scope, secret or credential is ever read into the result.
 */
class IntegrationSetupReadiness implements SetupReadinessProvider
{
    public function keys(): array
    {
        return ['integrations.integrations'];
    }

    public function evaluate(string $key, School $school): SetupReadiness
    {
        $configured = ApiClient::query()->where('school_id', $school->id)->where('status', ApiClient::STATUS_ACTIVE)->exists()
            || WebhookEndpoint::query()->where('school_id', $school->id)->where('status', 'active')->exists();

        return $configured
            ? new SetupReadiness(SetupStatus::CONFIGURED, 'An active partner API client or webhook endpoint is set up.', 1, 1)
            : new SetupReadiness(SetupStatus::NOT_CONFIGURED, 'No partner API client or webhook endpoint is set up.', 0, 1);
    }
}

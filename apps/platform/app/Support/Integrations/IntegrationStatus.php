<?php

namespace App\Support\Integrations;

/**
 * ORG.12G (ADR 0080 §12): the closed status vocabulary of the Integration &
 * Device Readiness Hub. It answers four separate questions, in order:
 *
 * - exists: NOT_AVAILABLE -- Lycenza has no such integration here (deferred,
 *   out of scope or not enabled in this environment); nothing to configure;
 * - entitled: NOT_APPLICABLE -- the owning optional module is not enabled for
 *   the School (decided from module entitlement, never by a provider);
 * - configured: NOT_CONFIGURED / CONFIGURED;
 * - healthy: HEALTHY (recent success observed), DEGRADED (delayed or paused),
 *   ATTENTION_REQUIRED (something the School should look at), ERROR (the
 *   readiness itself could not be read; decided by the Hub, never a provider).
 *
 * Never "connected", "live" or "production ready": nothing here calls a
 * provider, a device or an endpoint.
 */
enum IntegrationStatus: string
{
    case NOT_AVAILABLE = 'not_available';
    case NOT_APPLICABLE = 'not_applicable';
    case NOT_CONFIGURED = 'not_configured';
    case CONFIGURED = 'configured';
    case HEALTHY = 'healthy';
    case DEGRADED = 'degraded';
    case ATTENTION_REQUIRED = 'attention_required';
    case ERROR = 'error';
}

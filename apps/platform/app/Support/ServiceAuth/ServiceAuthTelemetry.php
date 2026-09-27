<?php

namespace App\Support\ServiceAuth;

use App\Support\Observability\MetricsRecorder;
use Illuminate\Support\Facades\Log;

/**
 * ADR 0053 section 9: bounded logs and metrics. Logs carry the calling
 * service, direction, `kid` (only once found in a ring), a closed outcome
 * code and the route name -- never the assertion, signature, key material,
 * body digest, jti or context token. Metric labels are closed catalogs only
 * (never kid, School, User or request id).
 */
final class ServiceAuthTelemetry
{
    public const INBOUND = 'gateway_to_platform';

    public const OUTBOUND = 'platform_to_gateway';

    public const OUTCOMES = ['success', 'rejected_by_receiver', ...ServiceAuthContract::FAILURE_CODES];

    public function __construct(private readonly MetricsRecorder $metrics) {}

    public function record(string $direction, ?string $service, string $outcome, string $route, ?string $kid = null): void
    {
        $service = in_array($service, ServiceAuthContract::SERVICES, true) ? $service : 'unknown';
        $outcome = in_array($outcome, self::OUTCOMES, true) ? $outcome : 'malformed';

        $this->metrics->counter('lycenza_service_auth_total', 1, ['direction' => $direction, 'service' => $service, 'outcome' => $outcome]);

        $context = array_filter([
            'peer_service' => $service,
            'direction' => $direction,
            'outcome' => $outcome,
            'route' => $route,
            'kid' => $kid,
        ], fn ($value) => $value !== null);

        if ($outcome === 'success') {
            Log::debug('service_auth.succeeded', $context);
        } else {
            Log::warning('service_auth.failed', $context);
        }
    }
}

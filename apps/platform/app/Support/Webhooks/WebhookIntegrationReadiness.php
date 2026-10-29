<?php

namespace App\Support\Webhooks;

use App\Models\School;
use App\Support\Integrations\IntegrationReadiness;
use App\Support\Integrations\IntegrationReadinessProvider;
use App\Support\Integrations\IntegrationStatus;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\DB;

/**
 * ORG.12G (ADR 0080 §12): `integrations.webhooks` readiness for ONE School,
 * from its own `webhook_endpoints`/`webhook_deliveries` (RLS) as indexed
 * EXISTS probes over a 7-day window, for ACTIVE endpoints only.
 *
 * Never selects an endpoint's URL, name or either secret column; never
 * calls an endpoint (no SSRF surface: delivery stays DeliverWebhookJob's,
 * with its send-time validation, rule 41); never a count, event id or
 * response detail.
 */
final class WebhookIntegrationReadiness implements IntegrationReadinessProvider
{
    public const string KEY = 'integrations.webhooks';

    public const int WINDOW_DAYS = 7;

    public function __construct(private readonly Repository $config) {}

    public function keys(): array
    {
        return [self::KEY];
    }

    public function evaluate(string $key, School $school): IntegrationReadiness
    {
        $active = DB::table('webhook_endpoints')->where('school_id', $school->id)->where('status', 'active')->select('id');
        if (! (clone $active)->exists()) {
            return new IntegrationReadiness(IntegrationStatus::NOT_CONFIGURED, 'No active webhook endpoint is registered.');
        }

        // Bounded EXISTS probes for active endpoints only. The windowed probes
        // use (school_id, updated_at); a delivery's delivered_at is never after
        // its last update. The backlog probe uses (status, next_attempt_at).
        $high = (int) $this->config->get('observability.thresholds.backlog_high_seconds');
        $since = now()->subDays(self::WINDOW_DAYS);
        $deliveries = fn () => DB::table('webhook_deliveries')->where('school_id', $school->id)->whereIn('webhook_endpoint_id', clone $active);
        $failed = $deliveries()->where('updated_at', '>=', $since)->whereIn('status', ['failed', 'abandoned'])->exists();
        $backlog = $deliveries()->whereIn('status', ['pending', 'retrying'])->where('created_at', '<', now()->subSeconds($high))->exists();
        $delivered = $deliveries()->where('updated_at', '>=', $since)->where('delivered_at', '>=', $since)->exists();

        return match (true) {
            $failed => new IntegrationReadiness(IntegrationStatus::ATTENTION_REQUIRED, 'Some deliveries in the last 7 days failed permanently. Check the endpoint\'s delivery history through the School API.'),
            $backlog => new IntegrationReadiness(IntegrationStatus::DEGRADED, 'Some deliveries are still being retried.'),
            $delivered => new IntegrationReadiness(IntegrationStatus::HEALTHY, 'Events from the last 7 days were delivered.'),
            default => new IntegrationReadiness(IntegrationStatus::CONFIGURED, 'An active endpoint is registered. Nothing was delivered in the last 7 days.'),
        };
    }
}

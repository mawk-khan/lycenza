<?php

namespace App\Support\ApiClients;

use App\Models\School;
use App\Support\Api\PartnerScopeRegistry;
use App\Support\Integrations\IntegrationReadiness;
use App\Support\Integrations\IntegrationReadinessProvider;
use App\Support\Integrations\IntegrationStatus;
use Illuminate\Support\Facades\DB;

/**
 * ORG.12G (ADR 0080 §12): `integrations.api_clients` readiness for ONE
 * School, from its own `api_clients`/`api_client_credentials` (RLS).
 *
 * Reads only lifecycle timestamps (expiry, revocation, last use) -- never
 * `secret_hash`, the key id, a scope list or a client name. Partner access
 * exists only where a partner scope is approved: none is in production
 * (ADR 0057 §5.1, `PartnerScopeRegistry::PRODUCTION`), so there the family is
 * NOT_AVAILABLE unless a client somehow exists.
 */
final class ApiClientIntegrationReadiness implements IntegrationReadinessProvider
{
    public const string KEY = 'integrations.api_clients';

    public const int EXPIRY_WARNING_DAYS = 30;

    public const int RECENT_USE_DAYS = 30;

    public function keys(): array
    {
        return [self::KEY];
    }

    public function evaluate(string $key, School $school): IntegrationReadiness
    {
        $now = now();
        $clients = DB::table('api_clients as c')
            ->leftJoin('api_client_credentials as cr', fn ($j) => $j->on('cr.api_client_id', '=', 'c.id')->on('cr.school_id', '=', 'c.school_id'))
            ->where('c.school_id', $school->id)
            ->where('c.status', 'active')
            ->groupBy('c.id')
            ->selectRaw('max(cr.expires_at) filter (where cr.revoked_at is null and cr.expires_at > ?) as usable_until', [$now])
            ->selectRaw('max(cr.last_used_at) as last_used_at')
            ->get();

        if ($clients->isEmpty()) {
            return PartnerScopeRegistry::available() === []
                ? new IntegrationReadiness(IntegrationStatus::NOT_AVAILABLE, 'No partner API access is approved for this Lycenza environment (ADR 0057 §5.1).')
                : new IntegrationReadiness(IntegrationStatus::NOT_CONFIGURED, 'No active partner API client.');
        }

        $warnBefore = $now->copy()->addDays(self::EXPIRY_WARNING_DAYS);
        $recentSince = $now->copy()->subDays(self::RECENT_USE_DAYS);

        return match (true) {
            $clients->contains(fn ($c): bool => $c->usable_until === null) => new IntegrationReadiness(IntegrationStatus::ATTENTION_REQUIRED, 'An active API client has no current credential. Rotate it or revoke the client.'),
            $clients->contains(fn ($c): bool => $warnBefore->greaterThan($c->usable_until)) => new IntegrationReadiness(IntegrationStatus::ATTENTION_REQUIRED, 'An API client credential expires within 30 days. Rotate it before it expires.'),
            $clients->contains(fn ($c): bool => $c->last_used_at !== null && $recentSince->lessThanOrEqualTo($c->last_used_at)) => new IntegrationReadiness(IntegrationStatus::HEALTHY, 'A partner used its credential in the last 30 days.'),
            default => new IntegrationReadiness(IntegrationStatus::CONFIGURED, 'An active API client has a current credential. It has not been used in the last 30 days.'),
        };
    }
}

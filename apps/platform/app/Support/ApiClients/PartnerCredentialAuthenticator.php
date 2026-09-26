<?php

namespace App\Support\ApiClients;

use App\Models\ApiClient;
use App\Models\ApiClientCredential;
use App\Models\School;
use App\Support\Api\PartnerCredentialFormat;
use App\Support\Audit\AuditRecorder;
use App\Support\Observability\MetricsRecorder;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Phase 0O.3 (ADR 0049 sections 3, 5, 15): the `auth:partner` guard's
 * resolver -- credential -> client -> exactly one School, BEFORE any
 * TenantContext exists (these are platform-resolvable bootstrap records).
 *
 * Every request re-checks: the credential format, a known key id, the
 * secret (constant-time against its SHA-256), the credential neither
 * revoked nor expired, and the client active. Any failure is the same
 * generic 401 -- the caller is never told which. The School's own status
 * is checked afterwards by App\Http\Middleware\Api\EstablishPartnerContext
 * (a non-disclosing 404), so a suspended School never revokes anything.
 *
 * `integrations.api_client.authentication_denied` is recorded only for a
 * KNOWN key id (wrong secret, revoked or expired credential, revoked
 * client) -- never for random probes -- and its volume is bounded by the
 * `api-auth-failure` limiter, checked before authentication.
 */
class PartnerCredentialAuthenticator
{
    public const DENIED = 'integrations.api_client.authentication_denied';

    private const RESOLVED = 'partner.authenticated_client';

    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
    ) {}

    public function authenticate(Request $request): ?ApiClient
    {
        // Resolved once per request: the request guard does not cache a
        // failed (null) result, and a denial must be audited only once.
        if ($request->attributes->has(self::RESOLVED)) {
            $cached = $request->attributes->get(self::RESOLVED);

            return $cached instanceof ApiClient ? $cached : null;
        }

        $client = $this->resolve($request);
        $request->attributes->set(self::RESOLVED, $client ?? false);

        return $client;
    }

    private function resolve(Request $request): ?ApiClient
    {
        $parsed = PartnerCredentialFormat::parse($request->bearerToken());

        if ($parsed === null) {
            // Only a token that claims to be a partner credential is a
            // partner authentication failure (a human token is not).
            if (str_starts_with((string) $request->bearerToken(), PartnerCredentialFormat::PREFIX)) {
                $this->countFailure('malformed');
            }

            return null;
        }

        [$keyId, $secret] = $parsed;

        $credential = ApiClientCredential::query()->where('key_id', $keyId)->first();

        if ($credential === null) {
            $this->countFailure('unknown_key');

            return null;
        }

        $client = ApiClient::query()->find($credential->api_client_id);

        $outcome = match (true) {
            $client === null => 'client_missing',
            ! hash_equals($credential->secret_hash, PartnerCredentialFormat::hash($secret)) => 'secret_mismatch',
            $credential->revoked_at !== null => 'credential_revoked',
            ! $credential->expires_at->isFuture() => 'credential_expired',
            ! $client->isActive() => 'client_revoked',
            default => null,
        };

        if ($outcome !== null) {
            $this->countFailure($outcome);

            if ($client !== null) {
                $this->recordDenial($client, $credential, $outcome);
            }

            return null;
        }

        // Coarse usage marker: at most one write per credential per minute,
        // and never waiting on a row lock (SKIP LOCKED) -- authentication
        // must not block behind a concurrent rotation or revocation.
        DB::update(
            'update api_client_credentials set last_used_at = ? where id = (select id from api_client_credentials where id = ? and (last_used_at is null or last_used_at < ?) for update skip locked)',
            [now(), $credential->id, now()->subMinute()],
        );

        $client->currentCredential = $credential;

        return $client;
    }

    private function recordDenial(ApiClient $client, ApiClientCredential $credential, string $outcome): void
    {
        $school = School::query()->find($client->school_id);

        if ($school === null) {
            return;
        }

        $this->context->withSchool($school, fn () => $this->audit->school($school, self::DENIED, subject: $client, metadata: [
            'api_client_id' => $client->id,
            'credential_id' => $credential->id,
            'outcome_code' => $outcome,
        ]));
    }

    /**
     * Phase 0O.5A (ADR 0051 §11): the closed outcome code only -- never the
     * key id, client, School or source address (those stay in the audit
     * trail and logs).
     */
    private function countFailure(string $outcome): void
    {
        app(MetricsRecorder::class)->counter('lycenza_partner_auth_failures_total', 1, ['outcome' => $outcome]);
    }
}

<?php

namespace App\Support\ApiClients;

use App\Models\ApiClient;
use App\Models\ApiClientCredential;
use App\Models\School;
use App\Models\User;
use App\Support\Api\PartnerCredentialFormat;
use App\Support\Api\PartnerScopeRegistry;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Phase 0O.3 (ADR 0049 sections 3-5, 15): the only writer of partner API
 * clients and their credentials. School-owned: every operation is for the
 * trusted, already-resolved School (never a client-supplied id) and needs
 * `integrations.api_clients.manage` there; the caller (the School
 * Integrations page) has already required a fresh MFA re-verification.
 *
 * - issue: a new client bound to that School for good, with approved
 *   partner scopes only, and its first credential;
 * - rotate: a new credential; the previous one expires at most 24 hours
 *   later (owner-contracted overlap), and any credential still inside an
 *   earlier overlap is revoked -- never more than two usable;
 * - revoke: the client and every credential, immediately and finally.
 *
 * Secrets are generated here, returned once, never stored, logged or
 * audited. Each mutation locks the client row (rotation vs revoke vs
 * rotation are serialized) and runs in one transaction with its audit.
 */
class ApiClientService
{
    public const CAPABILITY_VIEW = 'integrations.api_clients.view';

    public const CAPABILITY_MANAGE = 'integrations.api_clients.manage';

    /** Owner values V3/V4 (ADR 0049 implementation amendment). */
    public const DEFAULT_LIFETIME_DAYS = 90;

    public const MAX_LIFETIME_DAYS = 365;

    public const ROTATION_OVERLAP_HOURS = 24;

    public const ISSUED = 'integrations.api_client.issued';

    public const ROTATED = 'integrations.api_client.credential_rotated';

    public const REVOKED = 'integrations.api_client.revoked';

    public function __construct(
        private readonly CapabilityResolver $capabilities,
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
    ) {}

    /**
     * @param  list<string>  $scopes
     * @return array{0: ApiClient, 1: string} the client and its credential, shown once
     */
    public function issue(School $school, User $actor, string $name, array $scopes, ?int $lifetimeDays = null): array
    {
        $this->requireManage($school, $actor);
        $name = trim($name);
        $days = $this->lifetime($lifetimeDays);

        if ($name === '' || mb_strlen($name) > 100) {
            throw ValidationException::withMessages(['name' => 'Give the client a name of at most 100 characters.']);
        }

        $scopes = array_values(array_unique($scopes));
        if (! PartnerScopeRegistry::isValidSet($scopes)) {
            throw ValidationException::withMessages(['scopes' => PartnerScopeRegistry::available() === []
                ? 'No partner API scope is approved yet, so no partner client can be issued.'
                : 'Choose at least one approved partner scope.']);
        }

        // The School audit ledger is RLS-protected: write under that School.
        return $this->context->withSchool($school, fn (): array => DB::transaction(function () use ($school, $actor, $name, $scopes, $days): array {
            $client = ApiClient::query()->create([
                'school_id' => $school->id,
                'name' => $name,
                'scopes' => $scopes,
                'created_by_user_id' => $actor->id,
            ]);

            [$credential, $plaintext] = $this->newCredential($client, $days);

            $this->audit->school($school, self::ISSUED, actor: $actor, subject: $client, metadata: [
                'api_client_id' => $client->id,
                'credential_id' => $credential->id,
                'scopes' => $scopes,
                'expires_at' => $credential->expires_at->toIso8601String(),
            ]);

            return [$client->fresh() ?? $client, $plaintext];
        }));
    }

    /**
     * @return string the new credential, shown once
     */
    public function rotate(School $school, User $actor, string $clientId, ?int $lifetimeDays = null): string
    {
        $this->requireManage($school, $actor);
        $days = $this->lifetime($lifetimeDays);

        return $this->context->withSchool($school, fn (): string => DB::transaction(function () use ($school, $actor, $clientId, $days): string {
            $client = $this->lockActiveClient($school, $clientId);
            $now = now();

            // Anything still inside an earlier overlap ends now: at most two usable.
            ApiClientCredential::query()->where('api_client_id', $client->id)
                ->whereNotNull('superseded_at')->whereNull('revoked_at')->where('expires_at', '>', $now)
                ->update(['revoked_at' => $now, 'updated_at' => $now]);

            $previous = ApiClientCredential::query()->where('api_client_id', $client->id)
                ->whereNull('superseded_at')->whereNull('revoked_at')->first();

            $overlapEnds = null;
            if ($previous !== null) {
                $overlapEnds = $previous->expires_at->lt($now->copy()->addHours(self::ROTATION_OVERLAP_HOURS))
                    ? $previous->expires_at
                    : $now->copy()->addHours(self::ROTATION_OVERLAP_HOURS);
                $previous->forceFill(['superseded_at' => $now, 'expires_at' => $overlapEnds])->save();
            }

            [$credential, $plaintext] = $this->newCredential($client, $days);

            $this->audit->school($school, self::ROTATED, actor: $actor, subject: $client, metadata: [
                'api_client_id' => $client->id,
                'credential_id' => $credential->id,
                'previous_credential_id' => $previous?->id,
                'overlap_ends_at' => $overlapEnds?->toIso8601String(),
            ]);

            return $plaintext;
        }));
    }

    public function revoke(School $school, User $actor, string $clientId): void
    {
        $this->requireManage($school, $actor);

        $this->context->withSchool($school, fn () => DB::transaction(function () use ($school, $actor, $clientId): void {
            $client = $this->lockActiveClient($school, $clientId);
            $now = now();

            ApiClientCredential::query()->where('api_client_id', $client->id)->whereNull('revoked_at')
                ->update(['revoked_at' => $now, 'updated_at' => $now]);

            $client->forceFill(['status' => ApiClient::STATUS_REVOKED, 'revoked_at' => $now, 'revoked_by_user_id' => $actor->id])->save();

            $this->audit->school($school, self::REVOKED, actor: $actor, subject: $client, metadata: [
                'api_client_id' => $client->id,
            ]);
        }));
    }

    /**
     * The School's clients with credential metadata -- never a hash.
     *
     * @return list<array<string, mixed>>
     */
    public function listFor(School $school, User $actor): array
    {
        if (! $this->capabilities->can($actor, self::CAPABILITY_VIEW, $school)) {
            throw new AccessDeniedHttpException('You cannot view API clients in this School.');
        }

        return ApiClient::query()->where('school_id', $school->id)
            ->with(['credentials' => fn ($q) => $q->orderByDesc('issued_at')])
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (ApiClient $client): array => [
                'id' => $client->id,
                'name' => $client->name,
                'scopes' => $client->scopes,
                'status' => $client->status,
                'createdAt' => $client->created_at?->toIso8601String(),
                'revokedAt' => $client->revoked_at?->toIso8601String(),
                'credentials' => $client->credentials->map(fn (ApiClientCredential $c): array => [
                    'id' => $c->id,
                    'keyId' => $c->key_id,
                    'issuedAt' => $c->issued_at->toIso8601String(),
                    'expiresAt' => $c->expires_at->toIso8601String(),
                    'supersededAt' => $c->superseded_at?->toIso8601String(),
                    'revokedAt' => $c->revoked_at?->toIso8601String(),
                    'lastUsedAt' => $c->last_used_at?->toIso8601String(),
                    'usable' => $client->isActive() && $c->isUsable(),
                ])->values()->all(),
            ])->values()->all();
    }

    private function requireManage(School $school, User $actor): void
    {
        if (! $this->capabilities->can($actor, self::CAPABILITY_MANAGE, $school)) {
            throw new AccessDeniedHttpException('You cannot manage API clients in this School.');
        }
    }

    private function lifetime(?int $days): int
    {
        $days ??= self::DEFAULT_LIFETIME_DAYS;

        if ($days < 1 || $days > self::MAX_LIFETIME_DAYS) {
            throw ValidationException::withMessages(['lifetime_days' => 'A credential lasts between 1 and '.self::MAX_LIFETIME_DAYS.' days.']);
        }

        return $days;
    }

    /** The School's own client, active, locked for this transaction. */
    private function lockActiveClient(School $school, string $clientId): ApiClient
    {
        $client = ApiClient::query()->where('school_id', $school->id)->whereKey($clientId)->lockForUpdate()->first();

        if ($client === null || ! $client->isActive()) {
            throw ValidationException::withMessages(['client' => 'That API client does not exist or is already revoked.']);
        }

        return $client;
    }

    /**
     * @return array{0: ApiClientCredential, 1: string}
     */
    private function newCredential(ApiClient $client, int $days): array
    {
        $keyId = PartnerCredentialFormat::newKeyId();
        $secret = PartnerCredentialFormat::newSecret();
        $now = now();

        $credential = ApiClientCredential::query()->create([
            'api_client_id' => $client->id,
            'school_id' => $client->school_id,
            'key_id' => $keyId,
            'secret_hash' => PartnerCredentialFormat::hash($secret),
            'issued_at' => $now,
            'expires_at' => $now->copy()->addDays($days),
        ]);

        return [$credential, PartnerCredentialFormat::compose($keyId, $secret)];
    }
}

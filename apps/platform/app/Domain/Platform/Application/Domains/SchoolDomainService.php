<?php

namespace App\Domain\Platform\Application\Domains;

use App\Models\School;
use App\Models\SchoolDomain;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Domains\DomainDirectory;
use App\Support\Domains\DomainState;
use App\Support\Domains\DomainTelemetry;
use App\Support\Domains\HostnamePolicy;
use App\Support\Domains\HostnameRejected;
use App\Support\Domains\OwnershipVerifier;
use App\Support\Tenancy\SchoolOperationalGuard;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Phase 0O.8A (ADR 0054 sections 3-4, 7.3, 10): School-side custom domain
 * management -- claim, challenge regeneration, primary choice, revocation
 * -- plus the operator's console revocation. Every School method re-checks
 * `school.domains.manage` (callers also require a fresh MFA code), writes
 * inside one transaction with its `school.domain.*` audit event, and
 * forgets the Host cache after commit.
 *
 * The database is the concurrency authority, never a pre-check (CLAUDE.md
 * rule 30):
 * - claim: an expired pending claim for the hostname is moved to `expired`
 *   IN the claiming transaction, then the INSERT relies on the partial
 *   unique index (one claiming row per hostname: the loser of a race gets a
 *   non-disclosing "not available") and the INSERT guard's per-School limit
 *   of 3 (serialized by a per-School advisory lock);
 * - primary and revocation: the School's advisory lock plus FOR UPDATE of
 *   its claiming rows, and the deferred primary-invariant trigger at COMMIT
 *   (a School with an active domain has exactly one primary).
 * Revoking the current primary while another domain is active requires an
 * explicit replacement (School) -- the operator path promotes the oldest
 * active alias -- so no transaction can ever commit an active domain set
 * without a primary.
 */
final class SchoolDomainService
{
    public const CAPABILITY_VIEW = 'school.domains.view';

    public const CAPABILITY_MANAGE = 'school.domains.manage';

    public const MAX_PER_SCHOOL = 3;

    public const CHALLENGE_LIFETIME_HOURS = 24;

    public function __construct(
        private readonly HostnamePolicy $hostnames,
        private readonly CapabilityResolver $capabilities,
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
        private readonly SchoolOperationalGuard $operational,
        private readonly DomainDirectory $directory,
        private readonly DomainTelemetry $telemetry,
        private readonly Repository $config,
    ) {}

    public function enabled(): bool
    {
        return (bool) $this->config->get('domains.enabled');
    }

    /**
     * The School's own domains, School-safe fields only (ADR 0054 section
     * 10.4): never outcomes' raw reasons, resolver output or another School.
     *
     * @return list<array<string, mixed>>
     */
    public function listFor(School $school, User $actor): array
    {
        $this->authorize($actor, $school, self::CAPABILITY_VIEW);
        $manage = $this->capabilities->can($actor, self::CAPABILITY_MANAGE, $school);

        return SchoolDomain::query()
            ->where('school_id', $school->id)
            ->orderByRaw("CASE WHEN state IN ('revoked', 'expired') THEN 1 ELSE 0 END")
            ->orderBy('created_at')
            ->limit(20)
            ->get()
            ->map(fn (SchoolDomain $d) => [
                'id' => $d->id,
                'hostname' => $d->hostname,
                'state' => $d->state->value,
                'label' => $d->state->label(),
                'isPrimary' => $d->is_primary,
                'attention' => $d->state === DomainState::Suspended ? $d->suspension_reason : null,
                // The TXT record stays published for the domain's whole life
                // (section 4.4); shown to managers of non-terminal rows only.
                'dnsRecord' => $manage && ! $d->state->isTerminal() ? [
                    'type' => 'TXT',
                    'name' => OwnershipVerifier::recordName($d->hostname),
                    'value' => OwnershipVerifier::recordValue((string) $d->challenge_token),
                ] : null,
                'challengeExpiresAt' => $d->state === DomainState::PendingVerification ? $d->challenge_expires_at?->toIso8601String() : null,
                'certificateNotAfter' => $d->certificate_not_after?->toIso8601String(),
                'lastCheckedAt' => $d->last_checked_at?->toIso8601String(),
                'createdAt' => $d->created_at->toIso8601String(),
            ])
            ->all();
    }

    /** @return array{cnameTarget: string|null, addresses: list<string>} */
    public function routingTarget(): array
    {
        $target = trim((string) $this->config->get('domains.edge.cname_target'));

        return [
            'cnameTarget' => $target !== '' ? $target : null,
            'addresses' => array_values(array_map('strval', (array) $this->config->get('domains.edge.addresses', []))),
        ];
    }

    public function claim(School $school, User $actor, string $input): SchoolDomain
    {
        $this->authorize($actor, $school, self::CAPABILITY_MANAGE);

        if (! $this->enabled()) {
            throw new SchoolDomainException('disabled');
        }

        try {
            $hostname = $this->hostnames->claimable(trim($input));
        } catch (HostnameRejected $e) {
            throw new SchoolDomainException('invalid_hostname', $e->reason);
        }

        try {
            $domain = $this->context->withSchool($school, fn (): SchoolDomain => DB::transaction(function () use ($school, $actor, $hostname): SchoolDomain {
                // Lock order (see the migration): every School whose rows this
                // transaction may write -- ours, and the holder of a stale
                // pending claim of this hostname -- in one sorted order.
                $staleHolder = SchoolDomain::query()->where('hostname', $hostname)->where('state', DomainState::PendingVerification->value)->value('school_id');
                $this->lockSchool(...array_unique(array_filter([$school->id, is_string($staleHolder) ? $staleHolder : null])));

                if (! $this->operational->holdOperational($school->id)) {
                    throw new SchoolDomainException('school_not_active');
                }

                $this->expireStalePending($hostname);

                $domain = new SchoolDomain;
                $domain->forceFill([
                    'school_id' => $school->id,
                    'hostname' => $hostname,
                    'type' => 'custom',
                    'state' => DomainState::PendingVerification,
                    'is_primary' => false,
                    'challenge_token' => self::newToken(),
                    'challenge_generation' => 1,
                    'challenge_expires_at' => now()->addHours(self::CHALLENGE_LIFETIME_HOURS),
                    'next_check_at' => now()->addMinutes(5),
                ])->save();

                $this->audit->school($school, SchoolDomainAudit::CLAIMED, actor: $actor, subject: $domain, metadata: SchoolDomainAudit::metadata($hostname, null, DomainState::PendingVerification->value, 'claimed'));

                return $domain;
            }));
        } catch (UniqueConstraintViolationException) {
            throw new SchoolDomainException('unavailable');
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'school_domains_limit_exceeded')) {
                throw new SchoolDomainException('limit');
            }

            throw $e;
        }

        $this->telemetry->transition(DomainState::PendingVerification->value, $domain->id, 'claimed');

        return $domain;
    }

    public function regenerateChallenge(School $school, User $actor, string $domainId): SchoolDomain
    {
        $this->authorize($actor, $school, self::CAPABILITY_MANAGE);

        $domain = $this->context->withSchool($school, fn (): SchoolDomain => DB::transaction(function () use ($school, $actor, $domainId): SchoolDomain {
            $this->lockSchool($school->id);
            $domain = $this->lockOwn($school, $domainId);

            if ($domain->state !== DomainState::PendingVerification) {
                throw new SchoolDomainException('invalid_state');
            }

            $domain->forceFill([
                'challenge_token' => self::newToken(),
                'challenge_generation' => $domain->challenge_generation + 1,
                'challenge_expires_at' => now()->addHours(self::CHALLENGE_LIFETIME_HOURS),
                'ownership_outcome' => null,
                'ownership_checked_at' => null,
                'next_check_at' => now()->addMinutes(5),
            ])->save();

            $this->audit->school($school, SchoolDomainAudit::CHALLENGE_REGENERATED, actor: $actor, subject: $domain, metadata: SchoolDomainAudit::metadata($domain->hostname, $domain->state->value, $domain->state->value, 'regenerated', ['generation' => $domain->challenge_generation]));

            return $domain;
        }));

        $this->directory->forget($domain->hostname);

        return $domain;
    }

    public function setPrimary(School $school, User $actor, string $domainId): void
    {
        $this->authorize($actor, $school, self::CAPABILITY_MANAGE);

        $this->context->withSchool($school, fn () => DB::transaction(function () use ($school, $actor, $domainId): void {
            $rows = $this->lockSchoolRows($school->id);
            $target = $rows[$domainId] ?? throw new SchoolDomainException('not_found');

            if ($target->state !== DomainState::Active) {
                throw new SchoolDomainException('invalid_state');
            }

            if ($target->is_primary) {
                return;
            }

            $previous = collect($rows)->first(fn (SchoolDomain $d) => $d->is_primary);
            $previous?->forceFill(['is_primary' => false])->save();
            $target->forceFill(['is_primary' => true])->save();

            $this->audit->school($school, SchoolDomainAudit::PRIMARY_CHANGED, actor: $actor, subject: $target, metadata: SchoolDomainAudit::metadata($target->hostname, 'active', 'active', 'primary_changed', ['previous_primary' => $previous?->hostname]));
        }));

        $this->directory->forgetSchool($school->id);
    }

    public function revoke(School $school, User $actor, string $domainId, ?string $replacementId = null): void
    {
        $this->authorize($actor, $school, self::CAPABILITY_MANAGE);

        $hostname = $this->context->withSchool($school, fn (): string => DB::transaction(function () use ($school, $actor, $domainId, $replacementId): string {
            $rows = $this->lockSchoolRows($school->id);
            $target = $rows[$domainId] ?? throw new SchoolDomainException('not_found');

            $replacement = null;
            if ($target->is_primary && collect($rows)->contains(fn (SchoolDomain $d) => $d->id !== $target->id && $d->state === DomainState::Active)) {
                $replacement = $replacementId !== null ? ($rows[$replacementId] ?? null) : null;

                if ($replacement === null || $replacement->id === $target->id || $replacement->state !== DomainState::Active) {
                    throw new SchoolDomainException('replacement_required');
                }
            }

            $this->revokeLocked($school, $target, 'school', $actor, $replacement);

            return $target->hostname;
        }));

        $this->telemetry->transition(DomainState::Revoked->value, $domainId, 'school');
        $this->directory->forget($hostname);
        $this->directory->forgetSchool($school->id);
    }

    /**
     * ADR 0054 section 7.4: an operator unblocks a stuck hostname from the
     * console (`platform:domain-revoke`). Audited on the platform ledger and
     * the School's. A revoked primary with another active domain hands the
     * primary to the oldest active alias (no silent primary-less state).
     */
    public function revokeByOperator(string $hostname): ?SchoolDomain
    {
        $domain = SchoolDomain::query()->claiming()->where('hostname', $hostname)->with('school')->first();

        if ($domain === null) {
            return null;
        }

        $school = $domain->school;

        $this->context->withSchool($school, fn () => DB::transaction(function () use ($school, $domain): void {
            $rows = $this->lockSchoolRows($school->id);
            $target = $rows[$domain->id] ?? throw new SchoolDomainException('not_found');
            $replacement = $target->is_primary
                ? collect($rows)->filter(fn (SchoolDomain $d) => $d->id !== $target->id && $d->state === DomainState::Active)->sortBy('created_at')->first()
                : null;

            $this->revokeLocked($school, $target, 'operator', null, $replacement);
            $this->audit->platform(SchoolDomainAudit::OPERATOR_REVOKED, subject: $target, metadata: SchoolDomainAudit::metadata($target->hostname, null, DomainState::Revoked->value, 'operator_revoked', ['school_id' => $school->id]));
        }));

        $this->telemetry->transition(DomainState::Revoked->value, $domain->id, 'operator');
        $this->directory->forget($hostname);
        $this->directory->forgetSchool($school->id);

        return $domain->refresh();
    }

    /**
     * Pending claims whose 24 h challenge lifetime has passed become
     * `expired` (scheduler; the claim path also does this for one hostname).
     */
    public function expireOverdue(int $limit = 200): int
    {
        $ids = SchoolDomain::query()
            ->where('state', DomainState::PendingVerification->value)
            ->where('challenge_expires_at', '<=', now())
            ->orderBy('challenge_expires_at')
            ->limit($limit)
            ->pluck('id');

        $expired = 0;
        foreach ($ids as $id) {
            $expired += $this->expireOne((string) $id) ? 1 : 0;
        }

        return $expired;
    }

    public function expireOne(string $domainId): bool
    {
        $domain = SchoolDomain::query()->with('school')->find($domainId);

        if ($domain === null) {
            return false;
        }

        $done = $this->context->withSchool($domain->school, fn (): bool => DB::transaction(function () use ($domain): bool {
            $this->lockSchool($domain->school_id);
            $row = SchoolDomain::query()->whereKey($domain->id)->lockForUpdate()->first();

            if ($row === null || $row->state !== DomainState::PendingVerification || $row->challenge_expires_at === null || $row->challenge_expires_at->isFuture()) {
                return false;
            }

            $row->forceFill(['state' => DomainState::Expired, 'expired_at' => now(), 'next_check_at' => null])->save();
            $this->audit->school($domain->school, SchoolDomainAudit::EXPIRED, subject: $row, metadata: SchoolDomainAudit::metadata($row->hostname, DomainState::PendingVerification->value, DomainState::Expired->value, 'challenge_expired'));

            return true;
        }));

        if ($done) {
            $this->telemetry->transition(DomainState::Expired->value, $domain->id, 'challenge_expired');
        }

        return $done;
    }

    /** 32 CSPRNG bytes, base64url without padding: 43 characters (ADR 0054 section 4.2). */
    public static function newToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    /**
     * The School's advisory lock plus FOR UPDATE of its claiming rows, keyed by id.
     *
     * @return array<string, SchoolDomain>
     */
    public function lockSchoolRows(string $schoolId): array
    {
        $this->lockSchool($schoolId);

        return SchoolDomain::query()
            ->where('school_id', $schoolId)
            ->claiming()
            ->orderBy('created_at')
            ->lockForUpdate()
            ->get()
            ->keyBy('id')
            ->all();
    }

    /**
     * The per-School transaction advisory lock (school_domains_lock_school),
     * taken BEFORE any row lock and, for several Schools, in sorted order --
     * the one lock order every writer of `school_domains` follows, so the
     * deferred primary check's re-acquisition at COMMIT is always re-entrant.
     */
    public function lockSchool(string ...$schoolIds): void
    {
        sort($schoolIds);

        foreach ($schoolIds as $schoolId) {
            DB::select('SELECT school_domains_lock_school(?)', [$schoolId]);
        }
    }

    private function revokeLocked(School $school, SchoolDomain $target, string $source, ?User $actor, ?SchoolDomain $replacement): void
    {
        $from = $target->state->value;

        $target->forceFill([
            'state' => DomainState::Revoked,
            'is_primary' => false,
            'revoked_at' => now(),
            'revocation_source' => $source,
            'next_check_at' => null,
        ])->save();

        if ($replacement !== null) {
            $replacement->forceFill(['is_primary' => true])->save();
            $this->audit->school($school, SchoolDomainAudit::PRIMARY_CHANGED, actor: $actor, subject: $replacement, metadata: SchoolDomainAudit::metadata($replacement->hostname, 'active', 'active', 'primary_changed', ['previous_primary' => $target->hostname]));
        }

        $this->audit->school($school, SchoolDomainAudit::REVOKED, actor: $actor, subject: $target, metadata: SchoolDomainAudit::metadata($target->hostname, $from, DomainState::Revoked->value, $source === 'operator' ? 'operator_revoked' : 'revoked'));
    }

    /** One pending claim of this hostname whose lifetime passed: `expired`, in the caller's transaction. */
    private function expireStalePending(string $hostname): void
    {
        $stale = SchoolDomain::query()
            ->where('hostname', $hostname)
            ->where('state', DomainState::PendingVerification->value)
            ->where('challenge_expires_at', '<=', now())
            ->with('school')
            ->lockForUpdate()
            ->first();

        if ($stale === null) {
            return;
        }

        $stale->forceFill(['state' => DomainState::Expired, 'expired_at' => now(), 'next_check_at' => null])->save();
        $this->context->withSchool($stale->school, fn () => $this->audit->school($stale->school, SchoolDomainAudit::EXPIRED, subject: $stale, metadata: SchoolDomainAudit::metadata($stale->hostname, DomainState::PendingVerification->value, DomainState::Expired->value, 'challenge_expired')));
        $this->telemetry->transition(DomainState::Expired->value, $stale->id, 'challenge_expired');
    }

    private function lockOwn(School $school, string $domainId): SchoolDomain
    {
        $domain = SchoolDomain::query()
            ->where('school_id', $school->id)
            ->whereKey($domainId)
            ->lockForUpdate()
            ->first();

        return $domain ?? throw new SchoolDomainException('not_found');
    }

    private function authorize(User $actor, School $school, string $capability): void
    {
        if (! $this->capabilities->can($actor, $capability, $school)) {
            throw new AccessDeniedHttpException('You cannot manage this School\'s domains.');
        }
    }
}

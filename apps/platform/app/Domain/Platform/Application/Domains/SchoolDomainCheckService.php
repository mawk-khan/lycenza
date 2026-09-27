<?php

namespace App\Domain\Platform\Application\Domains;

use App\Jobs\CheckSchoolDomainJob;
use App\Models\SchoolDomain;
use App\Support\Audit\AuditRecorder;
use App\Support\Domains\DomainDirectory;
use App\Support\Domains\DomainState;
use App\Support\Domains\DomainTelemetry;
use App\Support\Domains\OwnershipVerifier;
use App\Support\Domains\Probe\DomainProber;
use App\Support\Domains\Probe\ProbeResult;
use App\Support\Domains\RoutingVerifier;
use App\Support\Tenancy\SchoolOperationalGuard;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Phase 0O.8A (ADR 0054 sections 4-7): moves a domain through its lifecycle
 * on evidence, never on anybody's say-so. There is no "force activate":
 * `active` is reachable only from a check that, in ONE decision, finds the
 * ownership TXT matching, routing on the configured edge, the TLS probe
 * passing (publicly trusted, valid, exact-name certificate on TLS >= 1.2
 * and this deployment's HMAC proof) and the School active (held FOR SHARE,
 * ADR 0047). The database refuses an activation write without that
 * evidence as well.
 *
 * DNS and TLS work happens OUTSIDE any transaction (CLAUDE.md rule 38); the
 * result is then applied under a row lock only if the row is still in the
 * state and challenge generation the checks ran against -- so a check
 * racing a regeneration, a revocation or an expiry changes nothing.
 *
 * Drift (active/suspended, section 7.2, frozen):
 * - ownership `mismatch`: suspend on the 2nd consecutive, >= 1 h after the first;
 * - ownership `absent`: suspend on the 3rd consecutive, spanning >= 48 h;
 * - routing elsewhere, or TLS/proof failure: suspend on the 2nd consecutive, >= 1 h apart;
 * - `indeterminate`: never suspends; it neither advances nor resets a streak,
 *   and `indeterminate_since` drives OBS-30 after 3 days;
 * - a suspended row returns to `active` (same row, same token) only when all
 *   three pass again and the School is active.
 * Suspending the primary hands the primary to the oldest active alias.
 */
final class SchoolDomainCheckService
{
    public const RECHECK_AFTER_FAILURE_MINUTES = 60;

    public const INDETERMINATE_ALERT_DAYS = 3;

    /**
     * Audit events queued by the mutation running inside apply()'s transaction.
     *
     * @var list<array{0: string, 1: SchoolDomain, 2: array<string, mixed>}>
     */
    private array $pending = [];

    public function __construct(
        private readonly OwnershipVerifier $ownership,
        private readonly RoutingVerifier $routing,
        private readonly DomainProber $prober,
        private readonly SchoolOperationalGuard $operational,
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
        private readonly DomainDirectory $directory,
        private readonly DomainTelemetry $telemetry,
        private readonly SchoolDomainService $domains,
    ) {}

    /**
     * Runs every check the row's state calls for, advancing as far as the
     * evidence allows. Returns the resulting state.
     */
    public function check(SchoolDomain $domain): DomainState
    {
        $domain->refresh();

        if ($domain->state->isTerminal()) {
            return $domain->state;
        }

        if (! $this->operational->isOperational($domain->school_id)) {
            // ADR 0054 section 7.1: nothing to check for a non-active School
            // (its domains serve nothing); the row's state is left alone.
            $this->reschedule($domain, now()->addDay());

            return $domain->state;
        }

        // Advance through at most three states in one call, but never start
        // another step after 15 s: one job stays well inside its 60 s timeout
        // (each step is itself bounded: DNS <= 10 s, probe <= 2 x 10 s).
        $started = microtime(true);

        for ($step = 0; $step < 3 && microtime(true) - $started < 15; $step++) {
            $before = $domain->state;

            match ($before) {
                DomainState::PendingVerification => $this->checkPending($domain),
                DomainState::Verified => $this->checkVerified($domain),
                DomainState::TlsPending => $this->checkTlsPending($domain),
                DomainState::Active, DomainState::Suspended => $this->checkLive($domain),
                default => null,
            };

            $domain->refresh();

            if ($domain->state === $before || in_array($domain->state, [DomainState::Active, DomainState::Suspended], true) || $domain->state->isTerminal()) {
                break;
            }
        }

        return $domain->state;
    }

    /**
     * The scheduler's bounded run: expire overdue claims, then hand at most
     * $limit due rows to the queue (CheckSchoolDomainJob) -- never network
     * work inside the scheduler process. Enumeration reads platform data
     * only; each job carries its School id explicitly (CLAUDE.md rule 7) and
     * re-checks the School when it runs. `next_check_at` is pushed forward
     * first, so an overlapping run never queues the same row twice.
     */
    public function dispatchDue(int $limit): int
    {
        $this->domains->expireOverdue();

        $due = SchoolDomain::query()
            ->claiming()
            ->where(fn ($q) => $q->whereNull('next_check_at')->orWhere('next_check_at', '<=', now()))
            ->orderByRaw('next_check_at NULLS FIRST')
            ->limit(max(1, $limit))
            ->with('school')
            ->get();

        $queued = 0;
        foreach ($due as $domain) {
            $claimed = SchoolDomain::query()->whereKey($domain->id)->claiming()
                ->where(fn ($q) => $q->whereNull('next_check_at')->orWhere('next_check_at', '<=', now()))
                ->update(['next_check_at' => now()->addMinutes(15)]);

            if ($claimed === 1) {
                $this->context->withSchool($domain->school, fn () => CheckSchoolDomainJob::dispatch($domain->id));
                $queued++;
            }
        }

        return $queued;
    }

    private function checkPending(SchoolDomain $domain): void
    {
        if ($domain->challenge_expires_at !== null && ! $domain->challenge_expires_at->isFuture()) {
            $this->domains->expireOne($domain->id);

            return;
        }

        $generation = $domain->challenge_generation;
        $result = $this->ownership->check($domain->hostname, (string) $domain->challenge_token);
        $this->telemetry->check('ownership', $result['outcome'], $domain->id, $result['reason']);

        $this->apply($domain, DomainState::PendingVerification, $generation, function (SchoolDomain $row) use ($result): void {
            $row->forceFill([
                'ownership_checked_at' => now(),
                'ownership_outcome' => $result['outcome'],
                'last_checked_at' => now(),
                'next_check_at' => now()->addMinutes(15),
            ]);

            if ($result['outcome'] === OwnershipVerifier::MATCH && $row->challenge_expires_at?->isFuture()) {
                $row->forceFill(['state' => DomainState::Verified, 'verified_at' => now(), 'next_check_at' => now()]);
                $this->record($row, SchoolDomainAudit::VERIFIED, DomainState::PendingVerification, 'ownership_match');
            }
        });
    }

    private function checkVerified(SchoolDomain $domain): void
    {
        $result = $this->routing->check($domain->hostname);
        $this->telemetry->check('routing', $result['outcome'], $domain->id, $result['reason']);

        $this->apply($domain, DomainState::Verified, $domain->challenge_generation, function (SchoolDomain $row) use ($result): void {
            $row->forceFill([
                'routing_checked_at' => now(),
                'routing_outcome' => $result['outcome'],
                'last_checked_at' => now(),
                'next_check_at' => now()->addMinutes(15),
            ]);

            if ($result['outcome'] === RoutingVerifier::PASS) {
                // Entering tls_pending is the edge's provisioning signal
                // (platform:domains-edge-desired).
                $row->forceFill(['state' => DomainState::TlsPending, 'next_check_at' => now()->addMinutes(5)]);
                $this->record($row, SchoolDomainAudit::TLS_PENDING, DomainState::Verified, 'routing_pass');
            }
        });
    }

    private function checkTlsPending(SchoolDomain $domain): void
    {
        [$ownership, $routing, $probe] = $this->evidence($domain);

        $this->apply($domain, DomainState::TlsPending, $domain->challenge_generation, function (SchoolDomain $row) use ($ownership, $routing, $probe): void {
            $this->storeEvidence($row, $ownership, $routing, $probe);
            $row->forceFill(['next_check_at' => now()->addMinutes(15)]);

            if ($ownership['outcome'] === OwnershipVerifier::MATCH && $routing['outcome'] === RoutingVerifier::PASS && $probe->passed()
                && $this->operational->holdOperational($row->school_id)) {
                $this->activate($row, DomainState::TlsPending, SchoolDomainAudit::ACTIVATED, 'activated');
            }
        });
    }

    private function checkLive(SchoolDomain $domain): void
    {
        [$ownership, $routing, $probe] = $this->evidence($domain);
        $from = $domain->state;

        $this->apply($domain, $from, $domain->challenge_generation, function (SchoolDomain $row) use ($ownership, $routing, $probe, $from): void {
            $now = CarbonImmutable::now();
            $this->storeEvidence($row, $ownership, $routing, $probe);
            $this->advanceStreaks($row, $ownership['outcome'], $routing['outcome'], $probe, $now);

            $allPass = $ownership['outcome'] === OwnershipVerifier::MATCH && $routing['outcome'] === RoutingVerifier::PASS && $probe->passed();
            $failing = $row->ownership_mismatch_count > 0 || $row->ownership_absent_count > 0 || $row->routing_fail_count > 0 || $row->tls_fail_count > 0 || $row->indeterminate_since !== null;
            $row->forceFill(['next_check_at' => $failing ? $now->addMinutes(self::RECHECK_AFTER_FAILURE_MINUTES) : $now->addDay()]);

            if ($from === DomainState::Suspended) {
                if ($allPass && $this->operational->holdOperational($row->school_id)) {
                    $this->activate($row, DomainState::Suspended, SchoolDomainAudit::REACTIVATED, 'reactivated');
                }

                return;
            }

            $reason = $this->suspensionReason($row, $now);

            if ($reason !== null) {
                $this->suspend($row, $reason);
            }
        });
    }

    /**
     * @return array{0: array{outcome: string, reason: string|null}, 1: array{outcome: string, reason: string|null, addresses: list<string>}, 2: ProbeResult}
     */
    private function evidence(SchoolDomain $domain): array
    {
        $ownership = $this->ownership->check($domain->hostname, (string) $domain->challenge_token);
        $this->telemetry->check('ownership', $ownership['outcome'], $domain->id, $ownership['reason']);

        $routing = $this->routing->check($domain->hostname);
        $this->telemetry->check('routing', $routing['outcome'], $domain->id, $routing['reason']);

        // IP-pinned to the routing-validated edge addresses only; with no
        // passing route there is nothing safe to connect to.
        $probe = $routing['outcome'] === RoutingVerifier::PASS
            ? $this->prober->probe($domain->hostname, $routing['addresses'])
            : new ProbeResult(ProbeResult::INDETERMINATE, 'no_address');
        $this->telemetry->check('tls', $probe->outcome, $domain->id, $probe->reason);

        return [$ownership, $routing, $probe];
    }

    /**
     * @param  array{outcome: string, reason: string|null}  $ownership
     * @param  array{outcome: string, reason: string|null, addresses: list<string>}  $routing
     */
    private function storeEvidence(SchoolDomain $row, array $ownership, array $routing, ProbeResult $probe): void
    {
        $row->forceFill([
            'ownership_checked_at' => now(),
            'ownership_outcome' => $ownership['outcome'],
            'routing_checked_at' => now(),
            'routing_outcome' => $routing['outcome'],
            'tls_checked_at' => now(),
            'tls_outcome' => $probe->outcome,
            'last_checked_at' => now(),
        ]);

        if ($probe->notAfter !== null) {
            $row->forceFill([
                'certificate_not_after' => $probe->notAfter,
                'certificate_fingerprint' => $probe->fingerprint !== null ? strtolower(str_replace(':', '', $probe->fingerprint)) : null,
                'certificate_issuer' => $probe->issuer,
            ]);
        }
    }

    private function advanceStreaks(SchoolDomain $row, string $ownership, string $routing, ProbeResult $probe, CarbonImmutable $now): void
    {
        match ($ownership) {
            OwnershipVerifier::MATCH => $row->forceFill(['ownership_mismatch_count' => 0, 'ownership_mismatch_since' => null, 'ownership_absent_count' => 0, 'ownership_absent_since' => null]),
            OwnershipVerifier::MISMATCH => $row->forceFill([
                'ownership_absent_count' => 0, 'ownership_absent_since' => null,
                'ownership_mismatch_count' => $row->ownership_mismatch_count + 1,
                'ownership_mismatch_since' => $row->ownership_mismatch_since ?? $now,
            ]),
            OwnershipVerifier::ABSENT => $row->forceFill([
                'ownership_mismatch_count' => 0, 'ownership_mismatch_since' => null,
                'ownership_absent_count' => $row->ownership_absent_count + 1,
                'ownership_absent_since' => $row->ownership_absent_since ?? $now,
            ]),
            default => null,
        };

        match ($routing) {
            RoutingVerifier::PASS => $row->forceFill(['routing_fail_count' => 0, 'routing_fail_since' => null]),
            RoutingVerifier::FAIL => $row->forceFill(['routing_fail_count' => $row->routing_fail_count + 1, 'routing_fail_since' => $row->routing_fail_since ?? $now]),
            default => null,
        };

        if ($probe->passed()) {
            $row->forceFill(['tls_fail_count' => 0, 'tls_fail_since' => null]);
        } elseif ($probe->failed()) {
            $row->forceFill(['tls_fail_count' => $row->tls_fail_count + 1, 'tls_fail_since' => $row->tls_fail_since ?? $now]);
        }

        $indeterminate = $ownership === OwnershipVerifier::INDETERMINATE || $routing === RoutingVerifier::INDETERMINATE || $probe->outcome === ProbeResult::INDETERMINATE;
        $row->forceFill(['indeterminate_since' => $indeterminate ? ($row->indeterminate_since ?? $now) : null]);
    }

    private function suspensionReason(SchoolDomain $row, CarbonImmutable $now): ?string
    {
        $apart = fn ($since, int $minutes) => $since !== null && $now->diffInMinutes($since, true) >= $minutes;

        return match (true) {
            $row->ownership_mismatch_count >= 2 && $apart($row->ownership_mismatch_since, 60),
            $row->ownership_absent_count >= 3 && $apart($row->ownership_absent_since, 48 * 60) => 'ownership',
            $row->routing_fail_count >= 2 && $apart($row->routing_fail_since, 60) => 'routing',
            $row->tls_fail_count >= 2 && $apart($row->tls_fail_since, 60) => 'tls',
            default => null,
        };
    }

    private function suspend(SchoolDomain $row, string $reason): void
    {
        $wasPrimary = $row->is_primary;
        $row->forceFill([
            'state' => DomainState::Suspended,
            'is_primary' => false,
            'suspended_at' => now(),
            'suspension_reason' => $reason,
        ]);
        $this->record($row, SchoolDomainAudit::SUSPENDED, DomainState::Active, 'suspended_'.$reason);

        if ($wasPrimary) {
            $row->save();
            $alias = SchoolDomain::query()
                ->where('school_id', $row->school_id)
                ->where('state', DomainState::Active->value)
                ->whereKeyNot($row->id)
                ->orderBy('created_at')
                ->lockForUpdate()
                ->first();

            if ($alias !== null) {
                $alias->forceFill(['is_primary' => true])->save();
                $this->audit->school($row->school, SchoolDomainAudit::PRIMARY_CHANGED, subject: $alias, metadata: SchoolDomainAudit::metadata($alias->hostname, 'active', 'active', 'primary_promoted', ['previous_primary' => $row->hostname]));
            }
        }
    }

    private function activate(SchoolDomain $row, DomainState $from, string $event, string $outcome): void
    {
        $hasPrimary = SchoolDomain::query()
            ->where('school_id', $row->school_id)
            ->where('is_primary', true)
            ->whereKeyNot($row->id)
            ->exists();

        $row->forceFill([
            'state' => DomainState::Active,
            'is_primary' => ! $hasPrimary,
            'activated_at' => now(),
            'suspended_at' => null,
            'suspension_reason' => null,
            'ownership_mismatch_count' => 0, 'ownership_mismatch_since' => null,
            'ownership_absent_count' => 0, 'ownership_absent_since' => null,
            'routing_fail_count' => 0, 'routing_fail_since' => null,
            'tls_fail_count' => 0, 'tls_fail_since' => null,
            'indeterminate_since' => null,
            'next_check_at' => now()->addDay(),
        ]);
        $this->record($row, $event, $from, $outcome);
    }

    /**
     * Applies $mutate to the row under the School's advisory lock and a row
     * lock, only if it is still in $state at $generation. Audit events the
     * mutation queues are written in the same transaction; the Host cache
     * is forgotten after commit.
     */
    private function apply(SchoolDomain $domain, DomainState $state, int $generation, \Closure $mutate): void
    {
        $school = $domain->school()->first();

        if ($school === null) {
            return;
        }

        $transitioned = $this->context->withSchool($school, fn (): ?string => DB::transaction(function () use ($domain, $state, $generation, $mutate): ?string {
            $rows = $this->domains->lockSchoolRows($domain->school_id);
            $row = $rows[$domain->id] ?? null;

            if ($row === null || $row->state !== $state || $row->challenge_generation !== $generation) {
                return null;
            }

            $this->resetPending();
            $mutate($row);
            $row->save();

            foreach ($this->takePending() as [$event, $subject, $metadata]) {
                $this->audit->school($subject->school, $event, subject: $subject, metadata: $metadata);
            }

            return $row->state !== $state ? $row->state->value : null;
        }));

        if ($transitioned !== null) {
            $this->telemetry->transition($transitioned, $domain->id, 'check');
            $this->directory->forgetSchool($domain->school_id);
        }
    }

    private function resetPending(): void
    {
        $this->pending = [];
    }

    /** @return list<array{0: string, 1: SchoolDomain, 2: array<string, mixed>}> */
    private function takePending(): array
    {
        [$pending, $this->pending] = [$this->pending, []];

        return $pending;
    }

    private function record(SchoolDomain $row, string $event, DomainState $from, string $outcome): void
    {
        $this->pending[] = [$event, $row, SchoolDomainAudit::metadata($row->hostname, $from->value, $row->state->value, $outcome)];
    }

    private function reschedule(SchoolDomain $domain, \DateTimeInterface $at): void
    {
        SchoolDomain::query()->whereKey($domain->id)->claiming()->update(['next_check_at' => $at]);
    }
}

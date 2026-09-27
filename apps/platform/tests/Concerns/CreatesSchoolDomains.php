<?php

namespace Tests\Concerns;

use App\Domain\Platform\Application\Domains\SchoolDomainService;
use App\Models\School;
use App\Models\SchoolDomain;
use App\Support\Domains\DomainState;

/**
 * Phase 0O.8A (ADR 0054): custom-domain fixtures that go through the SAME
 * database rules production does -- a row is inserted as a pending claim
 * (the INSERT guard) and walked through the permitted transitions with the
 * evidence the transition trigger demands. No test-only bypass: a state the
 * lifecycle cannot reach cannot be fabricated here either.
 */
trait CreatesSchoolDomains
{
    protected function createSchoolDomain(School $school, string $hostname, DomainState $state = DomainState::Active, ?bool $primary = null): SchoolDomain
    {
        $domain = new SchoolDomain;
        $domain->forceFill([
            'school_id' => $school->id,
            'hostname' => $hostname,
            'type' => 'custom',
            'state' => DomainState::PendingVerification,
            'is_primary' => false,
            'challenge_token' => SchoolDomainService::newToken(),
            'challenge_generation' => 1,
            'challenge_expires_at' => now()->addDay(),
        ])->save();

        $path = match ($state) {
            DomainState::PendingVerification => [],
            DomainState::Verified => [DomainState::Verified],
            DomainState::TlsPending => [DomainState::Verified, DomainState::TlsPending],
            DomainState::Active => [DomainState::Verified, DomainState::TlsPending, DomainState::Active],
            DomainState::Suspended => [DomainState::Verified, DomainState::TlsPending, DomainState::Active, DomainState::Suspended],
            DomainState::Revoked => [DomainState::Revoked],
            DomainState::Expired => [DomainState::Expired],
        };

        foreach ($path as $step) {
            $attributes = match ($step) {
                DomainState::Verified => ['verified_at' => now(), 'ownership_outcome' => 'match', 'ownership_checked_at' => now()],
                DomainState::TlsPending => ['routing_outcome' => 'pass', 'routing_checked_at' => now()],
                DomainState::Active => [
                    'tls_outcome' => 'pass', 'tls_checked_at' => now(), 'activated_at' => now(),
                    'certificate_not_after' => now()->addDays(60), 'next_check_at' => now()->addDay(),
                    'is_primary' => $primary ?? ! SchoolDomain::query()->where('school_id', $school->id)->where('is_primary', true)->exists(),
                ],
                DomainState::Suspended => ['is_primary' => false, 'suspended_at' => now(), 'suspension_reason' => 'tls'],
                DomainState::Revoked => ['revoked_at' => now(), 'revocation_source' => 'school'],
                DomainState::Expired => ['expired_at' => now()],
                default => [],
            };

            $domain->forceFill(['state' => $step, ...$attributes])->save();
        }

        return $domain->refresh();
    }
}

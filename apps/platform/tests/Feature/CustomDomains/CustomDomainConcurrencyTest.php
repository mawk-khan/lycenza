<?php

namespace Tests\Feature\CustomDomains;

use App\Models\School;
use App\Models\SchoolDomain;
use App\Models\User;
use App\Support\Domains\Dns\FakeDomainDnsResolver;
use App\Support\Domains\DomainState;
use App\Support\Domains\OwnershipVerifier;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesSchoolDomains;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Concerns\ForcesConcurrentOverlap;
use Tests\TestCase;

/**
 * Phase 0O.8A (ADR 0054 sections 3.5-3.6, 4, 7.3): custom-domain races between
 * GENUINELY separate OS processes on real PostgreSQL (and the real Redis the
 * fake DNS answers live in). Overlap is forced and verified, never assumed
 * (ForcesConcurrentOverlap): the holder's write stays uncommitted until the
 * contender is observed blocked on a lock. The database constraints and
 * locks decide every race -- never a pre-check, never a sleep.
 */
class CustomDomainConcurrencyTest extends TestCase
{
    use CreatesSchoolDomains, CreatesTenancyFixtures, ForcesConcurrentOverlap;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    /** @var list<string> */
    private array $schoolIds = [];

    /** @var list<string> */
    private array $userIds = [];

    /** @var list<string> */
    private array $fakeNames = [];

    protected function tearDown(): void
    {
        $admin = DB::connection('pgsql_admin');
        $admin->table('platform_audit_events')->whereIn('actor_user_id', $this->userIds)->delete();
        foreach ($this->schoolIds as $id) {
            $this->deleteSchoolAsAdmin($id);
        }
        $admin->table('users')->whereIn('id', $this->userIds)->delete();
        foreach ($this->fakeNames as $name) {
            $this->dns()->forget($name);
        }

        parent::tearDown();
    }

    /** @return array{0: User, 1: School} */
    private function admin(): array
    {
        [$user, $school] = $this->createSchoolAdmin();
        $this->userIds[] = $user->id;
        $this->schoolIds[] = $school->id;

        return [$user, $school];
    }

    /** The fake DNS the child processes read: the REAL Redis store. */
    private function dns(): FakeDomainDnsResolver
    {
        return new FakeDomainDnsResolver(Cache::store('redis'));
    }

    private function publish(SchoolDomain $domain, ?string $token = null): void
    {
        $name = OwnershipVerifier::recordName($domain->hostname);
        $this->fakeNames[] = $name;
        $this->fakeNames[] = $domain->hostname;
        $this->dns()->publishTxt($name, [OwnershipVerifier::recordValue($token ?? (string) $domain->challenge_token)]);
        $this->dns()->publishAddresses($domain->hostname, ['1.2.3.4']);
    }

    /** @param list<string> $args */
    private function op(array $args): array
    {
        return ['php', base_path('tests/Support/school-domain-op.php'), ...$args];
    }

    private function race(array $holder, array $contender): array
    {
        return $this->raceWithHeldHolder($this->op($holder), $this->op($contender));
    }

    #[Test]
    public function two_schools_claiming_one_hostname_exactly_one_wins_and_the_loser_learns_nothing(): void
    {
        [$userA, $schoolA] = $this->admin();
        [$userB, $schoolB] = $this->admin();

        [$holder, $contender] = $this->race(
            ['claim', $schoolA->id, $userA->id, 'erp.northfield.org'],
            ['claim', $schoolB->id, $userB->id, 'erp.northfield.org'],
        );

        $this->assertSame('ok:pending_verification', $holder);
        $this->assertSame('refused:unavailable', $contender);
        $this->assertSame([$schoolA->id], SchoolDomain::query()->where('hostname', 'erp.northfield.org')->claiming()->pluck('school_id')->all());
    }

    #[Test]
    public function two_simultaneous_additions_never_push_a_school_past_three_domains(): void
    {
        [$user, $school] = $this->admin();
        $this->createSchoolDomain($school, 'a.northfield.org', DomainState::PendingVerification);
        $this->createSchoolDomain($school, 'b.northfield.org', DomainState::PendingVerification);

        [$holder, $contender] = $this->race(
            ['claim', $school->id, $user->id, 'c.northfield.org'],
            ['claim', $school->id, $user->id, 'd.northfield.org'],
        );

        $this->assertSame('ok:pending_verification', $holder);
        $this->assertSame('refused:limit', $contender);
        $this->assertSame(3, SchoolDomain::query()->where('school_id', $school->id)->claiming()->count());
    }

    #[Test]
    public function a_verification_racing_a_regeneration_never_verifies_the_superseded_token(): void
    {
        [$user, $school] = $this->admin();
        $domain = $this->createSchoolDomain($school, 'erp.northfield.org', DomainState::PendingVerification);
        $this->publish($domain); // the generation-1 value

        [$holder, $contender] = $this->race(
            ['regenerate', $school->id, $user->id, $domain->id],
            ['check', $school->id, $user->id, $domain->id],
        );

        $this->assertSame('ok:pending_verification', $holder);
        $this->assertSame('ok:pending_verification', $contender);
        $domain->refresh();
        $this->assertSame(2, $domain->challenge_generation);
        $this->assertSame(DomainState::PendingVerification, $domain->state);
        $this->assertNull($domain->verified_at);
    }

    #[Test]
    public function a_verification_racing_a_removal_or_an_expiry_changes_nothing(): void
    {
        [$user, $school] = $this->admin();
        $removed = $this->createSchoolDomain($school, 'erp.northfield.org', DomainState::PendingVerification);
        $this->publish($removed);

        [$holder, $contender] = $this->race(
            ['revoke', $school->id, $user->id, $removed->id],
            ['check', $school->id, $user->id, $removed->id],
        );
        $this->assertSame('ok:done', $holder);
        $this->assertSame('ok:revoked', $contender);
        $this->assertNull($removed->refresh()->verified_at);

        $expiring = $this->createSchoolDomain($school, 'portal.northfield.org', DomainState::PendingVerification);
        $this->publish($expiring);
        DB::connection('pgsql_admin')->table('school_domains')->where('id', $expiring->id)->update(['challenge_expires_at' => now()->subMinute()]);

        [$holder, $contender] = $this->race(
            ['expire', $school->id, $user->id, $expiring->id],
            ['check', $school->id, $user->id, $expiring->id],
        );
        $this->assertSame('ok:expired', $holder);
        $this->assertSame('ok:expired', $contender);
        $this->assertSame(DomainState::Expired, $expiring->refresh()->state);
        $this->assertSame(1, app(TenantContext::class)->withSchool($school, fn () => DB::table('school_audit_events')
            ->where('school_id', $school->id)->where('event_type', 'school.domain.expired')->count()), 'expired exactly once');
    }

    #[Test]
    public function an_activation_racing_a_revocation_never_activates_a_revoked_domain(): void
    {
        [$user, $school] = $this->admin();
        $domain = $this->createSchoolDomain($school, 'erp.northfield.org', DomainState::TlsPending);
        $this->publish($domain);

        [$holder, $contender] = $this->race(
            ['revoke', $school->id, $user->id, $domain->id],
            ['check', $school->id, $user->id, $domain->id],
        );

        $this->assertSame('ok:done', $holder);
        $this->assertSame('ok:revoked', $contender);
        $domain->refresh();
        $this->assertSame(DomainState::Revoked, $domain->state);
        $this->assertNull($domain->activated_at);
        $this->assertFalse($domain->is_primary);
    }

    #[Test]
    public function simultaneous_primary_changes_leave_exactly_one_primary(): void
    {
        [$user, $school] = $this->admin();
        $this->createSchoolDomain($school, 'a.northfield.org');
        $b = $this->createSchoolDomain($school, 'b.northfield.org');
        $c = $this->createSchoolDomain($school, 'c.northfield.org');

        [$holder, $contender] = $this->race(
            ['set-primary', $school->id, $user->id, $b->id],
            ['set-primary', $school->id, $user->id, $c->id],
        );

        $this->assertSame('ok:done', $holder);
        $this->assertSame('ok:done', $contender);
        $this->assertSame([$c->id], SchoolDomain::query()->where('school_id', $school->id)->where('is_primary', true)->pluck('id')->all());
    }

    #[Test]
    public function revoking_the_current_primary_while_it_changes_hands_never_leaves_active_domains_without_one(): void
    {
        [$user, $school] = $this->admin();
        $a = $this->createSchoolDomain($school, 'a.northfield.org');
        $b = $this->createSchoolDomain($school, 'b.northfield.org');
        $this->createSchoolDomain($school, 'c.northfield.org');

        [$holder, $contender] = $this->race(
            ['revoke', $school->id, $user->id, $a->id, $b->id], // A out, B becomes primary
            ['revoke', $school->id, $user->id, $b->id],         // B out -- but B is primary by then
        );

        $this->assertSame('ok:done', $holder);
        $this->assertSame('refused:replacement_required', $contender, 'the contender re-reads the committed primary under the lock');
        $active = SchoolDomain::query()->where('school_id', $school->id)->where('state', 'active')->get();
        $this->assertSame(1, $active->where('is_primary', true)->count());
        $this->assertSame($b->id, $active->firstWhere('is_primary', true)->id);
    }

    #[Test]
    public function the_primary_invariant_holds_at_a_real_commit(): void
    {
        [, $school] = $this->admin();
        $a = $this->createSchoolDomain($school, 'a.northfield.org');
        $this->createSchoolDomain($school, 'b.northfield.org');

        try {
            DB::transaction(fn () => DB::table('school_domains')->where('id', $a->id)->update(['is_primary' => false]));
            $this->fail('a School with active domains and no primary must never commit');
        } catch (QueryException|\PDOException $e) {
            // Raised by the deferred trigger AT COMMIT (PDO's commit, not a statement).
            $this->assertStringContainsString('school_domains_primary_invariant', $e->getMessage());
        }

        $this->assertTrue($a->refresh()->is_primary, 'nothing committed');
    }
}

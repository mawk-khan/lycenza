<?php

namespace Tests\Feature\CustomDomains;

use App\Domain\Platform\Application\Domains\SchoolDomainService;
use App\Http\Middleware\RequireSchoolContext;
use App\Jobs\CheckSchoolDomainJob;
use App\Models\School;
use App\Models\SchoolDomain;
use App\Models\User;
use App\Support\Domains\DomainState;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesSchoolDomains;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Feature\Auth\Mfa\Concerns\CreatesMfaFixtures;
use Tests\Feature\Platform\Groups\GroupTestHelpers;
use Tests\TestCase;

/**
 * Phase 0O.8A (ADR 0054 sections 10.1, 10.4-10.5): the School's custom-domain
 * page over HTTP. View needs `school.domains.view`; add, regenerate, primary
 * and remove need `school.domains.manage` AND a fresh MFA code; check now is
 * `.manage`, limited per School and per domain, and queued. No platform or
 * Group bypass, and a School never sees or touches another School's rows.
 */
class SchoolDomainManagementHttpTest extends TestCase
{
    use CreatesMfaFixtures, CreatesSchoolDomains, CreatesTenancyFixtures, GroupTestHelpers;

    /** @var array<string, list<string>> recovery codes per user */
    private array $codes = [];

    private function manager(School $school): User
    {
        $user = $this->createUserWithCapabilities($school, [SchoolDomainService::CAPABILITY_VIEW, SchoolDomainService::CAPABILITY_MANAGE]);
        $this->enrollActiveMfaFactor($user);
        $this->codes[$user->id] = $this->issueRecoveryCodes($user, 8);

        return $user;
    }

    /** A fresh, single-use MFA code (recovery codes: TOTP steps cannot repeat within 30 s). */
    private function code(User $user): string
    {
        return array_shift($this->codes[$user->id]);
    }

    /** The School selected in the platform-host session (a School with an active domain would switch cross-host). */
    private function enter(User $user, School $school): void
    {
        $this->actingAs($user)->withSession([RequireSchoolContext::SESSION_KEY => $school->id]);
    }

    #[Test]
    public function viewing_needs_the_view_capability_and_shows_only_this_schools_domains(): void
    {
        $school = $this->createSchool();
        $other = $this->createSchool();
        $this->createSchoolDomain($other, 'erp.southfield.org');
        $viewer = $this->createUserWithCapabilities($school, [SchoolDomainService::CAPABILITY_VIEW]);
        $noCapability = $this->createUserWithCapabilities($school, ['school.settings.view']);

        $this->enter($viewer, $school);
        $this->get('http://localhost/app/settings/domains')->assertOk()->assertInertia(fn ($page) => $page
            ->component('App/Domains/Index')->where('canManage', false)->where('enabled', true)->has('domains', 0)
            ->where('routing.cnameTarget', 'edge.lycenza-local.invalid'));

        $this->enter($noCapability, $school);
        $this->get('http://localhost/app/settings/domains')->assertForbidden();
    }

    #[Test]
    public function adding_needs_manage_and_a_fresh_mfa_code(): void
    {
        $school = $this->createSchool();
        $manager = $this->manager($school);
        $viewer = $this->createUserWithCapabilities($school, [SchoolDomainService::CAPABILITY_VIEW]);

        $this->enter($viewer, $school);
        $this->postJson('http://localhost/app/settings/domains', ['hostname' => 'erp.northfield.org', 'mfa_code' => '000000'])->assertForbidden();

        $this->enter($manager, $school);
        $this->postJson('http://localhost/app/settings/domains', ['hostname' => 'erp.northfield.org'])
            ->assertStatus(422)->assertJsonPath('error.errors.mfa_code.0', 'Enter a current authentication code.');
        $this->postJson('http://localhost/app/settings/domains', ['hostname' => 'erp.northfield.org', 'mfa_code' => '000000'])
            ->assertStatus(422)->assertJsonPath('error.errors.mfa_code.0', 'That code is not valid.');
        $this->assertSame(0, SchoolDomain::query()->count());

        $id = $this->postJson('http://localhost/app/settings/domains', ['hostname' => 'ERP.Northfield.org', 'mfa_code' => $this->code($manager)])
            ->assertCreated()->json('id');
        $this->assertSame('erp.northfield.org', SchoolDomain::query()->findOrFail($id)->hostname);

        $this->postJson('http://localhost/app/settings/domains', ['hostname' => 'co.uk', 'mfa_code' => $this->code($manager)])
            ->assertStatus(422)->assertJsonPath('error.message', 'Enter a domain name you own, such as erp.yourschool.org.');
    }

    #[Test]
    public function regenerate_primary_and_remove_each_need_a_fresh_code_and_only_touch_this_schools_rows(): void
    {
        $school = $this->createSchool();
        $manager = $this->manager($school);
        $pending = $this->createSchoolDomain($school, 'erp.northfield.org', DomainState::PendingVerification);
        $a = $this->createSchoolDomain($school, 'a.northfield.org');
        $b = $this->createSchoolDomain($school, 'b.northfield.org');
        $foreign = $this->createSchoolDomain($this->createSchool(), 'erp.southfield.org');
        $this->enter($manager, $school);

        foreach (["{$pending->id}/challenge", "{$b->id}/primary", "{$a->id}/revoke"] as $path) {
            $this->postJson("http://localhost/app/settings/domains/{$path}")->assertStatus(422)->assertJsonPath('error.errors.mfa_code.0', 'Enter a current authentication code.');
        }

        $this->postJson("http://localhost/app/settings/domains/{$pending->id}/challenge", ['mfa_code' => $this->code($manager)])->assertOk();
        $this->assertSame(2, $pending->refresh()->challenge_generation);

        $this->postJson("http://localhost/app/settings/domains/{$b->id}/primary", ['mfa_code' => $this->code($manager)])->assertOk();
        $this->assertTrue($b->refresh()->is_primary);

        $this->postJson("http://localhost/app/settings/domains/{$b->id}/revoke", ['mfa_code' => $this->code($manager)])
            ->assertStatus(422)->assertJsonPath('error.message', 'Choose another active domain to become primary before removing this one.');
        $this->postJson("http://localhost/app/settings/domains/{$b->id}/revoke", ['mfa_code' => $this->code($manager), 'replacement_id' => $a->id])->assertOk();
        $this->assertSame(DomainState::Revoked, $b->refresh()->state);
        $this->assertTrue($a->refresh()->is_primary);

        // Domain changes are also throttled per person: seven so far, the
        // eighth is still served, the ninth is not.
        $this->postJson("http://localhost/app/settings/domains/{$pending->id}/challenge", ['mfa_code' => 'x'])->assertStatus(422);
        $this->postJson("http://localhost/app/settings/domains/{$pending->id}/challenge", ['mfa_code' => 'x'])->assertStatus(429);
        RateLimiter::clear(md5('domain-management'.'domain-management:'.$manager->id));

        // Another School's row: indistinguishable from a missing one.
        foreach (["{$foreign->id}/challenge", "{$foreign->id}/primary", "{$foreign->id}/revoke", "{$foreign->id}/check"] as $path) {
            $this->postJson("http://localhost/app/settings/domains/{$path}", ['mfa_code' => $this->code($manager)])
                ->assertStatus(422)->assertJsonPath('error.message', 'That domain was not found.');
        }
        $this->assertSame(DomainState::Active, $foreign->refresh()->state);
        $this->postJson('http://localhost/app/settings/domains/not-a-uuid/revoke', ['mfa_code' => '1'])->assertNotFound();
    }

    #[Test]
    public function check_now_is_queued_and_limited_per_domain_and_per_school(): void
    {
        Queue::fake();
        $school = $this->createSchool();
        $manager = $this->manager($school);
        $a = $this->createSchoolDomain($school, 'a.northfield.org', DomainState::PendingVerification);
        $b = $this->createSchoolDomain($school, 'b.northfield.org', DomainState::PendingVerification);
        $c = $this->createSchoolDomain($school, 'c.northfield.org', DomainState::PendingVerification);
        $this->enter($manager, $school);

        foreach (range(1, 3) as $i) {
            $this->postJson("http://localhost/app/settings/domains/{$a->id}/check")->assertStatus(202);
        }
        $this->postJson("http://localhost/app/settings/domains/{$a->id}/check")->assertStatus(422)->assertJsonPath('error.message', 'Too many checks. Wait a minute and try again.');

        foreach (range(1, 3) as $i) {
            $this->postJson("http://localhost/app/settings/domains/{$b->id}/check")->assertStatus(202);
        }
        // Six per minute per School, whichever domain.
        $this->postJson("http://localhost/app/settings/domains/{$c->id}/check")->assertStatus(422);

        Queue::assertPushed(CheckSchoolDomainJob::class, 6);
        Queue::assertPushed(CheckSchoolDomainJob::class, fn (CheckSchoolDomainJob $job) => $job->contextSchoolId === $school->id);

        $viewer = $this->createUserWithCapabilities($school, [SchoolDomainService::CAPABILITY_VIEW]);
        $this->enter($viewer, $school);
        $this->postJson("http://localhost/app/settings/domains/{$c->id}/check")->assertForbidden();
    }

    #[Test]
    public function platform_and_group_authority_grant_no_domain_management(): void
    {
        $school = $this->createSchool();
        $root = $this->createPlatformRoot();
        $group = $this->createGroup([$school]);
        $groupAdmin = $this->groupAdmin($group, withMfa: false);

        foreach ([$root, $groupAdmin] as $user) {
            $this->actingAs($user)->post("http://localhost/app/schools/{$school->id}/activate")->assertSessionHasErrors('school');
            $this->actingAs($user)->get('http://localhost/app/settings/domains')->assertRedirect(route('app.dashboard'));
            $this->actingAs($user)->postJson('http://localhost/app/settings/domains', ['hostname' => 'erp.northfield.org'])->assertStatus(409);
        }
        $this->assertSame(0, SchoolDomain::query()->count());
    }
}

<?php

namespace Tests\Feature\Automation;

use App\Domain\Automation\Application\AutomationRuleService;
use App\Domain\Automation\Application\Catalog\AcademicYearSetupReviewRule;
use App\Domain\Automation\Infrastructure\AutomationRuleInstance;
use App\Models\School;
use App\Models\SchoolAuditEvent;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\Demo\DemoBuildResult;
use Database\Seeders\Demo\DemoDataBuilder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 0L.6 -- who may see and change Automation (owner decisions,
 * 2026-09-24): School Admin views and manages, Principal only views,
 * nobody else, and no implicit Platform Super Admin access. Configuration
 * is audited once per change with ids/codes only.
 */
class AutomationManagementTest extends TestCase
{
    use CreatesTenancyFixtures;

    private const URL = '/app/automation';

    private const RULE = AcademicYearSetupReviewRule::KEY;

    private function build(): DemoBuildResult
    {
        return app(DemoDataBuilder::class)->build();
    }

    private function user(string $email): User
    {
        return User::query()->where('email', $email)->firstOrFail();
    }

    private function as(User $user, ?School $school): static
    {
        $this->app['auth']->forgetGuards();
        $this->actingAs($user);

        return $school === null ? $this : $this->withHeader('X-School-Id', $school->id);
    }

    private function ruleUrl(string $action): string
    {
        return '/app/automation/rules/'.self::RULE.'/'.$action;
    }

    /** @return list<SchoolAuditEvent> */
    private function audit(School $school, string $prefix = 'automation.rule.'): array
    {
        return app(TenantContext::class)->withSchool($school, fn () => SchoolAuditEvent::query()
            ->where('school_id', $school->id)->where('event_type', 'like', $prefix.'%')
            ->orderBy('occurred_at')->orderBy('id')->get()->all());
    }

    private function ruleInstance(School $school): AutomationRuleInstance
    {
        return app(TenantContext::class)->withSchool($school, fn () => AutomationRuleInstance::query()->where('school_id', $school->id)->where('rule_type', self::RULE)->firstOrFail());
    }

    #[Test]
    public function only_school_admin_and_principal_roles_hold_automation_capabilities(): void
    {
        $this->build();

        $holders = fn (string $capability) => DB::table('role_capabilities')
            ->join('roles', 'roles.id', '=', 'role_capabilities.role_id')
            ->where('role_capabilities.capability_key', $capability)
            ->orderBy('roles.key')->pluck('roles.key')->all();

        $this->assertSame(['principal', 'school_admin'], $holders('automation.view'));
        $this->assertSame(['school_admin'], $holders('automation.manage'));
        $this->assertSame(0, DB::table('capabilities')->whereIn('key', ['automation.platform.view', 'automation.execute'])->count());
    }

    #[Test]
    public function school_admin_views_and_manages_and_principal_only_views(): void
    {
        $w = $this->build();

        $this->as($this->user('school.admin@example.test'), $w->school)->get(self::URL)
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('App/Automation/Index')
                ->where('automation.schoolEnabled', true)
                ->where('automation.canManage', true)
                ->where('automation.rules.0.key', self::RULE)
                ->where('automation.rules.0.tier', 0)
                ->where('automation.rules.0.instance.status', 'enabled')
                ->where('automation.rules.0.instance.ownerName', 'Asha Rao (School Admin)'));

        $principal = $this->user('principal@example.test');
        $this->as($principal, $w->school)->get(self::URL)->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('automation.canManage', false));

        foreach (['disable', 'enable', 'take-ownership'] as $action) {
            $this->as($principal, $w->school)->post($this->ruleUrl($action))->assertForbidden();
        }
        $this->assertSame(AutomationRuleInstance::STATUS_ENABLED, $this->ruleInstance($w->school)->status);
    }

    #[Test]
    public function every_other_persona_is_refused_including_platform_admin(): void
    {
        $w = $this->build();

        foreach ([
            'teacher@example.test', 'student@example.test', 'guardian01@example.test', 'hr.payroll@example.test',
            'finance.officer@example.test', 'library.operator@example.test', 'transport.operator@example.test',
            'reception@example.test', 'hostel.warden@example.test', 'canteen.operator@example.test', 'communications@example.test',
        ] as $email) {
            $this->as($this->user($email), $w->school)->get(self::URL)->assertForbidden();
            $this->as($this->user($email), $w->school)->post($this->ruleUrl('disable'))->assertForbidden();
            $this->as($this->user($email), $w->school)->get('/app')
                ->assertInertia(fn (AssertableInertia $page) => $page->where('nav.canViewAutomation', false));
        }

        $platform = $this->user('platform.admin@example.test');
        $this->as($platform, null)->get(self::URL)->assertForbidden();
        $this->as($platform, $w->school)->get(self::URL)->assertForbidden();
        $this->as($platform, $w->school)->post($this->ruleUrl('disable'))->assertForbidden();

        $this->assertSame(AutomationRuleInstance::STATUS_ENABLED, $this->ruleInstance($w->school)->status);

        foreach (['school.admin@example.test', 'principal@example.test'] as $email) {
            $this->as($this->user($email), $w->school)->get('/app')
                ->assertInertia(fn (AssertableInertia $page) => $page->where('nav.canViewAutomation', true));
        }
    }

    #[Test]
    public function guests_are_sent_to_login_and_unknown_rule_types_are_not_found(): void
    {
        $w = $this->build();
        $this->app['auth']->forgetGuards();
        $this->get(self::URL)->assertRedirect('/login');

        $this->as($this->user('school.admin@example.test'), $w->school)
            ->post('/app/automation/rules/anything.else/enable')->assertNotFound();
    }

    #[Test]
    public function managers_disable_re_enable_and_take_ownership_each_audited_once(): void
    {
        $w = $this->build();
        $admin = $this->user('school.admin@example.test');
        $before = count($this->audit($w->school));

        $this->as($admin, $w->school)->post($this->ruleUrl('disable'))->assertRedirect(self::URL);
        $this->as($admin, $w->school)->post($this->ruleUrl('disable'))->assertRedirect(self::URL); // no-op, not re-audited
        $this->assertSame(AutomationRuleInstance::STATUS_DISABLED, $this->ruleInstance($w->school)->status);

        $this->as($admin, $w->school)->post($this->ruleUrl('enable'))->assertRedirect(self::URL);
        $this->assertSame(AutomationRuleInstance::STATUS_ENABLED, $this->ruleInstance($w->school)->status);

        $second = $this->createUser();
        $this->assignSchoolRole($this->createMembership($second, $w->school), 'school_admin');
        $this->as($second, $w->school)->post($this->ruleUrl('take-ownership'))->assertRedirect(self::URL);
        $this->assertSame($second->id, $this->ruleInstance($w->school)->owner_user_id);

        $events = array_slice($this->audit($w->school), $before);
        $this->assertSame(['automation.rule.disabled', 'automation.rule.enabled', 'automation.rule.owner_changed'], array_map(fn ($e) => $e->event_type, $events));
        $this->assertSame([$admin->id, $admin->id, $second->id], array_map(fn ($e) => $e->getAttribute('actor_user_id'), $events));

        $instanceId = $this->ruleInstance($w->school)->id;
        foreach ($events as $event) {
            $this->assertSame($instanceId, $event->metadata['ruleInstanceId']);
            $this->assertSame(self::RULE, $event->metadata['ruleType']);
            foreach ($event->metadata as $key => $value) {
                $this->assertTrue($value === null || is_string($value) && strlen($value) <= 64, "Audit metadata [{$key}] must be an id or code.");
            }
        }
        $this->assertSame($admin->id, $events[2]->metadata['previousOwnerUserId']);
    }

    #[Test]
    public function the_first_configuration_is_audited_as_created_then_enabled(): void
    {
        $w = $this->build();
        $annexeAdmin = $this->user('annexe.admin@example.test');

        $this->as($annexeAdmin, $w->secondSchool)->post($this->ruleUrl('enable'))->assertRedirect(self::URL);

        $events = $this->audit($w->secondSchool);
        $this->assertSame(['automation.rule.created', 'automation.rule.enabled'], array_map(fn ($e) => $e->event_type, $events));
        $this->assertSame('disabled', $events[1]->metadata['previousStatus']);
        $this->assertSame($annexeAdmin->id, $this->ruleInstance($w->secondSchool)->owner_user_id);

        // The Annexe's flag is still off: configuring does not switch it on.
        $this->as($annexeAdmin, $w->secondSchool)->get(self::URL)
            ->assertInertia(fn (AssertableInertia $page) => $page->where('automation.schoolEnabled', false));
    }

    #[Test]
    public function an_owner_must_hold_every_required_capability_and_the_flag_grants_none(): void
    {
        $w = $this->build();

        // Holds automation.manage but not academics.years.view: refused as owner.
        $partial = $this->createUserWithCapabilities($w->school, ['automation.view', 'automation.manage']);
        $this->as($partial, $w->school)->post($this->ruleUrl('take-ownership'))->assertSessionHasErrors('owner');
        $this->assertNotSame($partial->id, $this->ruleInstance($w->school)->owner_user_id);

        // The School flag is on, yet a user without automation.manage still
        // cannot configure anything.
        $viewer = $this->createUserWithCapabilities($w->school, ['automation.view', 'academics.years.view']);
        $this->as($viewer, $w->school)->post($this->ruleUrl('take-ownership'))->assertForbidden();

        $this->expectException(AuthorizationException::class);
        app(AutomationRuleService::class)->enable($w->school, self::RULE, $viewer);
    }

    #[Test]
    public function a_manager_of_one_school_cannot_touch_another_schools_rule(): void
    {
        $w = $this->build();
        $annexeAdmin = $this->user('annexe.admin@example.test');

        // Naming the Demo School is not membership: no context, refused.
        $this->as($annexeAdmin, $w->school)->post($this->ruleUrl('disable'))->assertForbidden();
        $this->assertSame(AutomationRuleInstance::STATUS_ENABLED, $this->ruleInstance($w->school)->status);

        // Multi-School member: Principal at the Demo School (view only),
        // School Admin at the Annexe (manage there only).
        $multi = $this->user('multi.school@example.test');
        $this->as($multi, $w->school)->post($this->ruleUrl('disable'))->assertForbidden();
        $this->as($multi, $w->secondSchool)->post($this->ruleUrl('enable'))->assertRedirect(self::URL);
        $this->assertSame(AutomationRuleInstance::STATUS_ENABLED, $this->ruleInstance($w->school)->status);
        $this->assertSame($multi->id, $this->ruleInstance($w->secondSchool)->owner_user_id);
    }
}

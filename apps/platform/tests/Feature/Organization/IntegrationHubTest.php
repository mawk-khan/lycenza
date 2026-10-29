<?php

namespace Tests\Feature\Organization;

use App\Models\School;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Support\Api\PartnerScopeRegistry;
use App\Support\ApiClients\ApiClientIntegrationReadiness;
use App\Support\ApiClients\ApiClientService;
use App\Support\Email\ProviderAuthPause;
use App\Support\Email\Providers\SubmissionResult;
use App\Support\Integrations\IntegrationReadiness;
use App\Support\Integrations\IntegrationReadinessProvider;
use App\Support\Integrations\IntegrationReadinessProviders;
use App\Support\Integrations\OrganizationIntegrationCatalogue;
use App\Support\Setup\SetupReadiness;
use App\Support\Setup\SetupReadinessProvider;
use App\Support\Setup\SetupReadinessProviders;
use App\Support\Tenancy\TenantContext;
use App\Support\Testing\LocalCatalogueFixtures;
use Database\Factories\SchoolFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesEmailFixtures;
use Tests\Concerns\ProvidesSensitiveActionMfa;
use Tests\Feature\Platform\Groups\GroupTestHelpers;
use Tests\TestCase;

/**
 * ORG.12G (ADR 0080 §12): the Integration & Device Readiness Hub.
 *
 * - Entry: an active School and `school.settings.view` School-wide; no
 *   platform, Group/Network, elevation or Campus authority.
 * - Status ladder: exists (NOT_AVAILABLE) -> entitled (NOT_APPLICABLE) ->
 *   configured -> healthy, each from the owning module's own records; a
 *   failing provider is ERROR and the page still renders.
 * - Never a secret, URL, name, recipient or count; never a write, audit or
 *   outbound call.
 */
class IntegrationHubTest extends TestCase
{
    use CreatesEmailFixtures, GroupTestHelpers, ProvidesSensitiveActionMfa;

    private const string PAGE = '/app/school-setup/integrations';

    /** Measured on the first run; the Hub's own reads are a handful of bounded aggregates. */
    private const int PAGE_QUERY_BUDGET = 30;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function enter(User $user, School $school, bool $assured = true): void
    {
        $this->actingAs($user)->post("/app/schools/{$school->id}/activate");
        if ($assured) {
            $this->withMfaAssurance($user); // the API clients family needs current MFA, as its own page does
        }
    }

    /** @return array<string, mixed> the `hub` prop */
    private function hub(TestResponse $response): array
    {
        $response->assertOk();
        $page = $response->viewData('page');
        $this->assertSame('App/SchoolSetup/Integrations', $page['component']);

        return $page['props']['hub'];
    }

    /** @return array<string, array<string, mixed>> */
    private function cards(array $hub): array
    {
        $cards = [];
        foreach ($hub['sections'] as $section) {
            foreach ($section['items'] as $card) {
                $cards[$card['key']] = $card;
            }
        }

        return $cards;
    }

    /** @return array<string, string> key => status for the School administrator */
    private function statuses(School $school, ?User $viewer = null): array
    {
        $this->enter($viewer ?? $this->schoolAdminFor($school), $school);

        return array_map(fn (array $card): string => $card['status'], $this->cards($this->hub($this->get(self::PAGE))));
    }

    private function endpoint(School $school, string $status = 'active'): WebhookEndpoint
    {
        return app(TenantContext::class)->withSchool($school, fn () => WebhookEndpoint::query()->create([
            'school_id' => $school->id,
            'name' => 'Quarvex Partner Feed',
            'url' => 'https://8.8.8.8/hook?token=quarvex-url-token',
            'secret_encrypted' => 'whsec_quarvex_plain_secret',
            'status' => $status,
            'created_by_user_id' => $this->createUser()->id,
        ]));
    }

    private function delivery(WebhookEndpoint $endpoint, string $status, int $ageSeconds): void
    {
        $school = School::query()->findOrFail($endpoint->school_id);
        app(TenantContext::class)->withSchool($school, function () use ($endpoint, $status, $ageSeconds) {
            $at = now()->subSeconds($ageSeconds);
            $delivery = WebhookDelivery::query()->create([
                'school_id' => $endpoint->school_id,
                'webhook_endpoint_id' => $endpoint->id,
                'event_id' => (string) Str::uuid(),
                'event_type' => 'school.setting.changed.v1',
                'status' => $status,
                'attempts' => 1,
                'delivered_at' => $status === 'delivered' ? $at : null,
            ]);
            WebhookDelivery::query()->whereKey($delivery->id)->update(['created_at' => $at, 'updated_at' => $at]);
        });
    }

    // -- Entry -----------------------------------------------------------------

    #[Test]
    public function the_school_administrator_sees_every_family_once_in_fixed_sections(): void
    {
        $school = $this->createSchool();
        $this->enter($this->schoolAdminFor($school), $school);
        $hub = $this->hub($this->get(self::PAGE));
        $cards = $this->cards($hub);

        $catalogueKeys = array_map(fn ($d) => $d->key, app(OrganizationIntegrationCatalogue::class)->all());
        $this->assertSame($catalogueKeys, array_keys($cards), 'every family exactly once, in catalogue order');
        $this->assertSame(['communications', 'payments', 'attendance_devices', 'transport_tracking', 'platform_other'], array_column($hub['sections'], 'key'));
        $this->assertSame(0, $hub['overview']['hiddenFamilies']);
        $this->assertSame([
            'communications.email' => 'configured',
            'communications.sms' => 'not_available',
            'payments.gateway' => 'not_available',
            'attendance.biometric' => 'not_available',
            'attendance.rfid' => 'not_available',
            'transport.rfid' => 'not_available',
            'transport.vts' => 'not_available',
            'integrations.webhooks' => 'not_configured',
            'integrations.api_clients' => 'not_configured',
            'platform.ai_gateway' => 'not_available',
        ], array_map(fn (array $c): string => $c['status'], $cards));

        $this->assertSame(['configure', '/app/integrations/api-clients'], [$cards['integrations.api_clients']['action']['kind'], $cards['integrations.api_clients']['action']['href']]);
        $this->assertSame('none', $cards['integrations.webhooks']['action']['kind'], 'webhooks are managed through the School API; no web page to link');
        foreach (['communications.sms', 'payments.gateway', 'transport.vts', 'communications.email'] as $key) {
            $this->assertSame('none', $cards[$key]['action']['kind'], "{$key} has nothing for the School to open");
        }
        $this->assertSame('platform', $cards['communications.email']['managedBy']);
        $this->assertSame(['Integrations', 'not_configured', '/app/school-setup/readiness'], [$hub['overview']['setupArea']['title'], $hub['overview']['setupArea']['status'], $hub['overview']['setupArea']['href']]);
        $this->assertSame(7, $hub['overview']['counts']['not_available']);
    }

    #[Test]
    public function api_client_status_needs_the_same_mfa_assurance_as_its_own_page(): void
    {
        $school = $this->createSchool();
        $admin = $this->schoolAdminFor($school);
        app(ApiClientService::class)->issue($school, $admin, 'Quarvex Unverified', [PartnerScopeRegistry::PROBE], 10);
        $this->enter($admin, $school, assured: false);
        $hub = $this->hub($this->get(self::PAGE));

        $this->assertArrayNotHasKey('integrations.api_clients', $this->cards($hub), 'no status (not even "needs attention") without current MFA');
        $this->assertSame([['key' => 'integrations.api_clients', 'title' => 'Partner API clients', 'href' => '/app/integrations/api-clients']], $hub['overview']['verificationRequired']);
        $this->assertSame(0, $hub['overview']['hiddenFamilies'], 'awaiting verification is not "no access"');
        $this->assertSame('not_configured', $this->cards($hub)['integrations.webhooks']['status'], 'other families are unaffected');

        $this->withMfaAssurance($admin);
        $hub = $this->hub($this->get(self::PAGE));
        $this->assertSame('attention_required', $this->cards($hub)['integrations.api_clients']['status']);
        $this->assertSame([], $hub['overview']['verificationRequired']);
    }

    #[Test]
    public function a_principal_sees_product_facts_and_email_but_never_integration_detail_they_cannot_read(): void
    {
        $school = $this->createSchool();
        $principal = $this->createUser();
        $this->assignSchoolRole($this->createMembership($principal, $school), 'principal');
        $this->enter($principal, $school);
        $hub = $this->hub($this->get(self::PAGE));
        $cards = $this->cards($hub);

        $this->assertArrayNotHasKey('integrations.webhooks', $cards);
        $this->assertArrayNotHasKey('integrations.api_clients', $cards);
        $this->assertSame(2, $hub['overview']['hiddenFamilies'], 'hidden families are counted, never named');
        $this->assertNull($hub['overview']['setupArea'], 'the setup area needs both integration view capabilities, as in ORG.12A');
        $this->assertSame('configured', $cards['communications.email']['status']);
        $this->assertSame('not_available', $cards['payments.gateway']['status']);
    }

    #[Test]
    public function ordinary_staff_campus_only_suspended_and_disabled_members_are_refused(): void
    {
        $school = $this->createSchool();
        $campus = $this->createCampus($school);

        $teacher = $this->createUser();
        $this->assignSchoolRole($this->createMembership($teacher, $school), 'teacher');
        $this->enter($teacher, $school);
        $this->get(self::PAGE)->assertForbidden();

        // A Campus grant -- even of the Hub floor and integration reads -- is never School-wide authority.
        $campusOnly = $this->createUser();
        LocalCatalogueFixtures::grantRole($this->createMembership($campusOnly, $school), $this->createFixtureRole(['school.settings.view', 'integrations.webhooks.view', 'integrations.api_clients.view']), $campus->id);
        $this->enter($campusOnly, $school);
        $this->get(self::PAGE)->assertForbidden();

        $suspended = $this->createUserWithCapabilities($school, ['school.settings.view'], 'suspended');
        $this->enter($suspended, $school);
        $this->assertNotSame(200, $this->get(self::PAGE)->status());

        $disabled = $this->schoolAdminFor($school);
        $this->enter($disabled, $school);
        $disabled->forceFill(['is_disabled' => true])->save();
        $this->assertNotSame(200, $this->get(self::PAGE)->status());
    }

    #[Test]
    public function platform_group_and_network_operators_never_open_a_schools_hub(): void
    {
        $school = $this->createSchool();
        $root = $this->createPlatformRoot();
        $manager = $this->createUser();
        $this->assignPlatformRole($manager, 'platform_manager', $root);
        $auditor = $this->createUser();
        $this->assignPlatformRole($auditor, 'platform_auditor', $root);
        $group = $this->createGroup([$school]);
        $groupAdmin = $this->groupAdmin($group);
        $networkManager = $this->createUser();
        $this->grantGroupRole($networkManager, $group, 'network_manager', $root);
        $networkViewer = $this->createUser();
        $this->grantGroupRole($networkViewer, $group, 'network_viewer', $root);

        foreach (['root' => $root, 'manager' => $manager, 'auditor' => $auditor, 'group admin' => $groupAdmin, 'network manager' => $networkManager, 'network viewer' => $networkViewer] as $who => $user) {
            $this->enter($user, $school);
            $this->assertNotSame(200, $this->get(self::PAGE)->status(), "{$who} must not open the Hub");
        }
    }

    #[Test]
    public function a_school_that_is_not_active_is_refused(): void
    {
        $school = $this->createSchool();
        $this->enter($this->schoolAdminFor($school), $school);
        $school->forceFill(['status' => 'suspended'])->save();

        $this->assertNotSame(200, $this->get(self::PAGE)->status());
    }

    #[Test]
    public function navigation_offers_the_hub_only_to_floor_holders(): void
    {
        $school = $this->createSchool();
        $keys = function (User $user) use ($school): array {
            $this->enter($user, $school);
            $keys = [];
            foreach ($this->get('/app')->viewData('page')['props']['navigation']['groups'] as $group) {
                array_push($keys, ...array_column($group['items'], 'key'));
            }

            return $keys;
        };
        $teacher = $this->createUser();
        $this->assignSchoolRole($this->createMembership($teacher, $school), 'teacher');

        $this->assertContains('integrations-hub', $keys($this->schoolAdminFor($school)));
        $this->assertNotContains('integrations-hub', $keys($teacher));
    }

    // -- Entitlement -----------------------------------------------------------

    #[Test]
    public function an_unentitled_integrations_module_is_not_applicable_and_never_evaluated(): void
    {
        $school = School::factory()->withCoreModulesOnly()->create();
        $this->endpoint($school); // would be CONFIGURED if evaluated
        $this->enter($this->schoolAdminFor($school), $school);
        $hub = $this->hub($this->get(self::PAGE));
        $cards = $this->cards($hub);

        $this->assertSame(['not_applicable', 'none'], [$cards['integrations.webhooks']['status'], $cards['integrations.webhooks']['action']['kind']]);
        $this->assertSame(['not_applicable', 'none'], [$cards['integrations.api_clients']['status'], $cards['integrations.api_clients']['action']['kind']]);
        $this->assertSame('not_applicable', $hub['overview']['setupArea']['status']);
        $this->assertSame('not_available', $cards['payments.gateway']['status'], 'existence is decided before entitlement');

        SchoolFactory::setOptionalModules($school, true, ['integrations']);
        $this->assertSame('configured', $this->cards($this->hub($this->get(self::PAGE)))['integrations.webhooks']['status'], 're-enabling shows current readiness on the next visit');
    }

    // -- Per-family readiness --------------------------------------------------

    #[Test]
    public function webhook_readiness_follows_active_endpoints_and_their_recent_deliveries(): void
    {
        $school = $this->createSchool();
        $this->endpoint($school, 'disabled');
        $this->assertSame('not_configured', $this->statuses($school)['integrations.webhooks'], 'a disabled endpoint is not an active integration');

        $endpoint = $this->endpoint($school);
        $this->assertSame('configured', $this->statuses($school)['integrations.webhooks']);

        $this->delivery($endpoint, 'delivered', 3600);
        $this->assertSame('healthy', $this->statuses($school)['integrations.webhooks']);

        $this->delivery($endpoint, 'retrying', 7200);
        $this->assertSame('degraded', $this->statuses($school)['integrations.webhooks']);

        $this->delivery($endpoint, 'failed', 600);
        $this->assertSame('attention_required', $this->statuses($school)['integrations.webhooks']);
        $this->assertSame('Integrations', $this->hub($this->get(self::PAGE))['overview']['setupArea']['title']);
    }

    #[Test]
    public function old_webhook_history_ages_out_of_the_window(): void
    {
        $school = $this->createSchool();
        $endpoint = $this->endpoint($school);
        $this->delivery($endpoint, 'failed', 8 * 86400);
        $this->delivery($endpoint, 'delivered', 9 * 86400);

        $this->assertSame('configured', $this->statuses($school)['integrations.webhooks']);
    }

    #[Test]
    public function api_client_readiness_follows_credential_lifecycle_and_use(): void
    {
        $school = $this->createSchool();
        $admin = $this->schoolAdminFor($school);
        $service = app(ApiClientService::class);

        [$client] = $service->issue($school, $admin, 'Quarvex Timetable Sync', [PartnerScopeRegistry::PROBE], 90);
        $this->assertSame('configured', $this->statuses($school, $admin)['integrations.api_clients']);

        app(TenantContext::class)->withSchool($school, fn () => DB::table('api_client_credentials')->where('api_client_id', $client->id)->update(['last_used_at' => now()->subDays(2)]));
        $this->assertSame('healthy', $this->statuses($school, $admin)['integrations.api_clients']);

        $service->issue($school, $admin, 'Quarvex Expiring', [PartnerScopeRegistry::PROBE], 10);
        $this->assertSame('attention_required', $this->statuses($school, $admin)['integrations.api_clients'], 'a credential expiring within 30 days needs attention');
    }

    #[Test]
    public function an_active_api_client_without_a_current_credential_needs_attention(): void
    {
        $school = $this->createSchool();
        $admin = $this->schoolAdminFor($school);
        app(ApiClientService::class)->issue($school, $admin, 'Quarvex Lapsed', [PartnerScopeRegistry::PROBE], 30);
        $this->travel(31)->days();

        $this->assertSame('attention_required', $this->statuses($school, $admin)['integrations.api_clients']);
    }

    #[Test]
    public function partner_api_access_is_not_available_where_no_partner_scope_is_approved(): void
    {
        $school = $this->createSchool();
        $provider = app(ApiClientIntegrationReadiness::class);
        $original = $this->app['env'];
        try {
            $this->app['env'] = 'production'; // PartnerScopeRegistry::PRODUCTION is empty (ADR 0057 §5.1)
            $readiness = app(TenantContext::class)->withSchool($school, fn () => $provider->evaluate('integrations.api_clients', $school));
        } finally {
            $this->app['env'] = $original;
        }

        $this->assertSame('not_available', $readiness->status->value);
    }

    #[Test]
    public function email_readiness_reflects_platform_delivery_and_this_schools_own_messages(): void
    {
        $school = $this->createSchool();
        $admin = $this->schoolAdminFor($school);
        $this->assertSame('configured', $this->statuses($school, $admin)['communications.email'], 'enabled, nothing sent yet');

        $this->fakeEmail();
        $this->holdEmailSubmission();
        [$sent] = $this->queueStandardEmail($school, $admin, 'quarvex.recipient@example.test', 'Quarvex Subject Line');
        $this->submitEmail($school, $sent->id);
        $this->assertSame('healthy', $this->statuses($school, $admin)['communications.email'], 'recently accepted for delivery');

        $this->emailFake()->queue(SubmissionResult::permanent('mailbox_unavailable'));
        [$failed] = $this->queueStandardEmail($school, $admin, 'quarvex.bounce@example.test');
        $this->submitEmail($school, $failed->id);
        $this->assertSame('attention_required', $this->statuses($school, $admin)['communications.email']);

        app(ProviderAuthPause::class)->open(600);
        $this->assertSame('degraded', $this->statuses($school, $admin)['communications.email'], 'a platform pause outranks School history');

        config(['email.provider' => 'none']);
        $this->assertSame('not_configured', $this->statuses($school, $admin)['communications.email']);
    }

    #[Test]
    public function an_older_message_that_ended_undelivered_recently_needs_attention(): void
    {
        $school = $this->createSchool();
        $admin = $this->schoolAdminFor($school);
        $this->fakeEmail();
        $this->holdEmailSubmission();
        $this->travel(-8)->days();
        [$old] = $this->queueStandardEmail($school, $admin, 'quarvex.late@example.test');
        $this->travelBack();
        $this->submitEmail($school, $old->id); // created 8 days ago, expires undelivered now

        $this->assertSame(['cancelled', 'expired'], [$this->emailRow($school, $old->id)->status->value, $this->emailRow($school, $old->id)->status_code]);
        $this->assertSame('attention_required', $this->statuses($school, $admin)['communications.email'], 'the failure window follows finished_at, not created_at');
    }

    #[Test]
    public function a_stuck_email_backlog_is_degraded(): void
    {
        $school = $this->createSchool();
        $admin = $this->schoolAdminFor($school);
        $this->holdEmailSubmission();
        $this->queueStandardEmail($school, $admin);
        $this->travel(config('observability.thresholds.backlog_high_seconds') + 60)->seconds();

        $this->assertSame('degraded', $this->statuses($school, $admin)['communications.email']);
    }

    #[Test]
    public function a_failing_provider_is_an_error_and_the_page_still_renders(): void
    {
        $school = $this->createSchool();
        $providers = new IntegrationReadinessProviders;
        foreach (app(IntegrationReadinessProviders::class)->keys() as $key) {
            if ($key !== 'integrations.webhooks') {
                $providers->register(app(IntegrationReadinessProviders::class)->for($key));
            }
        }
        $providers->register(new class implements IntegrationReadinessProvider
        {
            public function keys(): array
            {
                return ['integrations.webhooks'];
            }

            public function evaluate(string $key, School $school): IntegrationReadiness
            {
                throw new \RuntimeException('postgres://quarvex:leaked-password@db/x');
            }
        });
        $this->app->instance(IntegrationReadinessProviders::class, $providers);

        $this->enter($this->schoolAdminFor($school), $school);
        $response = $this->get(self::PAGE);
        $cards = $this->cards($this->hub($response));

        $this->assertSame('error', $cards['integrations.webhooks']['status']);
        $this->assertSame('configured', $cards['communications.email']['status'], 'one failure never blanks the others');
        $this->assertStringNotContainsString('leaked-password', (string) $response->getContent(), 'an exception message never reaches the page');
    }

    #[Test]
    public function a_failing_setup_area_provider_never_breaks_the_page(): void
    {
        $school = $this->createSchool();
        $setup = new SetupReadinessProviders;
        $registered = [];
        foreach (app(SetupReadinessProviders::class)->keys() as $key) {
            $provider = app(SetupReadinessProviders::class)->for($key);
            if ($key !== 'integrations.integrations' && ! in_array($provider, $registered, true)) {
                $setup->register($provider);
                $registered[] = $provider;
            }
        }
        $setup->register(new class implements SetupReadinessProvider
        {
            public function keys(): array
            {
                return ['integrations.integrations'];
            }

            public function evaluate(string $key, School $school): SetupReadiness
            {
                throw new \RuntimeException('quarvex-setup-leak');
            }
        });
        $this->app->instance(SetupReadinessProviders::class, $setup);

        $this->enter($this->schoolAdminFor($school), $school);
        $response = $this->get(self::PAGE);
        $hub = $this->hub($response);

        $this->assertNull($hub['overview']['setupArea']['status']);
        $this->assertSame('configured', $this->cards($hub)['communications.email']['status']);
        $this->assertStringNotContainsString('quarvex-setup-leak', (string) $response->getContent());
    }

    // -- Isolation, privacy, read-only ------------------------------------------

    #[Test]
    public function each_school_sees_only_its_own_integrations_and_a_shared_user_follows_the_selected_school(): void
    {
        $a = $this->createSchool();
        $b = $this->createSchool();
        $this->delivery($this->endpoint($a), 'delivered', 60);
        $shared = $this->schoolAdminFor($a);
        $this->assignSchoolRole($this->createMembership($shared, $b), 'school_admin');

        $this->assertSame('healthy', $this->statuses($a, $shared)['integrations.webhooks']);
        $this->assertSame('not_configured', $this->statuses($b, $shared)['integrations.webhooks'], 'B never sees A\'s endpoint');
        $this->assertSame('healthy', $this->statuses($a, $shared)['integrations.webhooks'], 'switching back recalculates for A');
    }

    #[Test]
    public function the_page_never_carries_a_secret_url_name_recipient_or_credential(): void
    {
        $school = $this->createSchool();
        $admin = $this->schoolAdminFor($school);
        $endpoint = $this->endpoint($school);
        $this->delivery($endpoint, 'failed', 60);
        [$client, $secret] = app(ApiClientService::class)->issue($school, $admin, 'Quarvex Timetable Sync', [PartnerScopeRegistry::PROBE], 90);
        $credential = app(TenantContext::class)->withSchool($school, fn () => DB::table('api_client_credentials')->where('api_client_id', $client->id)->first());
        $this->fakeEmail();
        $this->holdEmailSubmission();
        [$email] = $this->queueStandardEmail($school, $admin, 'quarvex.recipient@example.test', 'Quarvex Subject Line', 'Quarvex body text');
        $this->submitEmail($school, $email->id);

        $this->enter($admin, $school);
        $html = (string) $this->get(self::PAGE)->assertOk()->getContent();

        foreach (['whsec_quarvex_plain_secret', 'quarvex-url-token', '8.8.8.8', 'Quarvex Partner Feed', 'Quarvex Timetable Sync', $secret, $credential->key_id, $credential->secret_hash, 'quarvex.recipient', 'Quarvex Subject Line', 'Quarvex body', (string) config('email.sending_domain'), 'partner.probe.read', $endpoint->id, $client->id] as $absent) {
            $this->assertNotSame('', $absent);
            $this->assertStringNotContainsString($absent, $html, "the Hub never carries {$absent}");
        }
    }

    #[Test]
    public function opening_the_hub_writes_nothing_calls_nothing_outside_and_stays_within_its_query_budget(): void
    {
        $school = $this->createSchool();
        $admin = $this->schoolAdminFor($school);
        $this->delivery($this->endpoint($school), 'delivered', 60);
        app(ApiClientService::class)->issue($school, $admin, 'Quarvex', [PartnerScopeRegistry::PROBE], 90);
        $this->enter($admin, $school);
        $this->get(self::PAGE)->assertOk(); // warm caches as a returning visitor
        $ledgers = fn (): array => [DB::table('school_audit_events')->count(), DB::table('platform_audit_events')->count(), DB::table('domain_event_outbox')->count()];
        $before = $ledgers();

        $statements = [];
        DB::listen(function ($query) use (&$statements): void {
            $statements[] = strtolower(ltrim($query->sql));
        });
        $response = $this->get(self::PAGE)->assertOk();

        $writes = array_filter($statements, fn (string $sql): bool => preg_match('/^(insert|update|delete|merge|truncate)\b/', $sql) === 1);
        $this->assertSame([], array_values($writes), 'the Hub never writes');
        $this->assertSame($before, $ledgers(), 'reads are never audited');
        $this->assertLessThanOrEqual(self::PAGE_QUERY_BUDGET, count($statements), 'full page used '.count($statements).' queries');
        $this->assertLessThan(50_000, strlen(json_encode($response->viewData('page')['props']['hub'])));
        Http::assertNothingSent();
    }
}

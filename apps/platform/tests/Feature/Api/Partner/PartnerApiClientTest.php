<?php

namespace Tests\Feature\Api\Partner;

use App\Models\ApiClient;
use App\Models\ApiClientCredential;
use App\Models\School;
use App\Models\SchoolAuditEvent;
use App\Models\User;
use App\Support\Api\PartnerCredentialFormat;
use App\Support\Api\PartnerScopeRegistry;
use App\Support\ApiClients\ApiClientService;
use App\Support\ApiClients\PartnerCredentialAuthenticator;
use App\Support\Tenancy\TenantContext;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 0O.3 (ADR 0049 sections 3-5, 14-15): partner API clients, proven
 * end to end against the local/testing-only probe route -- one immutable
 * School, TenantContext and RLS for exactly that School, generic 401s,
 * bounded denial audit, expiry, rotation overlap, immediate revocation,
 * suspension, scopes and throttling.
 */
class PartnerApiClientTest extends TestCase
{
    use CreatesTenancyFixtures;

    private const PROBE = '/api/v1/partner/probe';

    /** @var list<string> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();
        Event::listen(MessageLogged::class, function (MessageLogged $e): void {
            $this->logged[] = $e->message.' '.json_encode($e->context);
        });
    }

    /** @return array{0: School, 1: User, 2: ApiClient, 3: string} */
    private function issued(?School $school = null, ?int $days = null): array
    {
        if ($school === null) {
            [$admin, $school] = $this->createSchoolAdmin('school_admin');
        } else {
            $admin = $this->createUser();
            $this->assignSchoolRole($this->createMembership($admin, $school), 'school_admin');
        }
        $this->createCampus($school);

        [$client, $credential] = app(ApiClientService::class)->issue($school, $admin, 'Timetable sync', [PartnerScopeRegistry::PROBE], $days);

        return [$school, $admin, $client, $credential];
    }

    private function probe(string $credential, array $headers = []): TestResponse
    {
        // Each request starts like a fresh one: no resolved guards, and the
        // default guard back to `web` (`auth:partner` switches it for the
        // rest of the request, which persists in a test's single app).
        $this->app['auth']->forgetGuards();
        config(['auth.defaults.guard' => 'web']);

        return $this->withToken($credential)->withHeaders($headers)->getJson(self::PROBE);
    }

    private function audits(School $school, string $type): int
    {
        return app(TenantContext::class)->withSchool($school, fn () => SchoolAuditEvent::query()->where('event_type', $type)->count());
    }

    #[Test]
    public function a_credential_runs_under_exactly_its_own_school_with_rls(): void
    {
        [$a, , $client, $credential] = $this->issued();
        [$b] = $this->issued($this->createSchool());

        // Neither a URL, a query parameter nor a School header can move it.
        $response = $this->probe($credential, ['X-School-Id' => $b->id])->assertOk();
        $this->withToken($credential)->getJson(self::PROBE.'?school='.$b->id)->assertOk()->assertJsonPath('data.schoolId', $a->id);

        $response->assertJsonPath('data.apiClientId', $client->id)
            ->assertJsonPath('data.schoolId', $a->id)
            ->assertJsonPath('data.rlsSchoolIds', [$a->id])
            ->assertJsonPath('data.sessionSchool', $a->id);

        // Nothing leaks past the request.
        $this->assertNull(app(TenantContext::class)->schoolId());
        $this->assertSame('', (string) DB::selectOne("select current_setting('app.current_school_id', true) as v")->v);

        // The runtime role that ran it holds no bypass.
        $role = DB::connection('pgsql_admin')->selectOne('select rolsuper, rolbypassrls from pg_roles where rolname = ?', [config('database.connections.pgsql.username')]);
        $this->assertFalse((bool) $role->rolsuper);
        $this->assertFalse((bool) $role->rolbypassrls);
        $this->assertStringStartsWith(PartnerCredentialFormat::PREFIX, $credential);
    }

    #[Test]
    public function every_failure_is_the_same_401_and_only_known_clients_are_audited(): void
    {
        [$school, , $client, $credential] = $this->issued();
        [$keyId] = PartnerCredentialFormat::parse($credential);

        foreach ([
            'garbage',
            'lyc_pk_'.str_repeat('0', 16).'.'.str_repeat('A', 43),        // unknown key
            PartnerCredentialFormat::compose($keyId, str_repeat('B', 43)), // wrong secret
        ] as $bad) {
            $response = $this->probe($bad)->assertUnauthorized();
            $this->assertSame(['message' => 'Unauthenticated.', 'status' => 401, 'code' => null, 'errors' => null], array_diff_key($response->json('error'), ['requestId' => true]));
            $this->probe($bad)->assertUnauthorized();
        }

        // Only the wrong-secret attempts against a KNOWN key were audited (twice each above).
        $this->assertSame(2, $this->audits($school, PartnerCredentialAuthenticator::DENIED));
        $event = app(TenantContext::class)->withSchool($school, fn () => SchoolAuditEvent::query()->where('event_type', PartnerCredentialAuthenticator::DENIED)->first());
        $this->assertNull($event->actor_user_id);
        $this->assertEquals(['api_client_id' => $client->id, 'credential_id' => ApiClientCredential::query()->where('key_id', $keyId)->value('id'), 'outcome_code' => 'secret_mismatch'], $event->metadata); // jsonb: key order is not kept

        // A human token is not a partner credential, and vice versa.
        [$human, $humanSchool] = $this->createSchoolAdmin('school_admin');
        $this->probe($human->createToken('h')->plainTextToken)->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->withToken($credential)->getJson("/api/v1/schools/{$school->id}/context")->assertUnauthorized();
    }

    #[Test]
    public function expiry_is_absolute_and_bounded(): void
    {
        [, , , $credential] = $this->issued(days: 90);

        $this->travel(90)->days();
        $this->travel(-1)->minutes();
        $this->probe($credential)->assertOk();
        $this->travel(1)->minutes();
        $this->probe($credential)->assertUnauthorized(); // at the boundary
        $this->travel(1)->days();
        $this->probe($credential)->assertUnauthorized();
        $this->travelBack();

        [$school, $admin] = $this->issued();
        foreach ([0, 366] as $days) {
            try {
                app(ApiClientService::class)->issue($school, $admin, 'X', [PartnerScopeRegistry::PROBE], $days);
                $this->fail('Lifetime must be 1..365 days.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('lifetime_days', $e->errors());
            }
        }
    }

    #[Test]
    public function rotation_overlaps_for_at_most_24_hours_and_never_leaves_three_usable(): void
    {
        [$school, $admin, $client, $first] = $this->issued();
        $service = app(ApiClientService::class);

        $second = $service->rotate($school, $admin, $client->id);
        $this->probe($first)->assertOk();
        $this->probe($second)->assertOk();

        $this->travel(23)->hours();
        $this->probe($first)->assertOk();
        $this->travel(1)->hours();
        $this->probe($first)->assertUnauthorized();
        $this->probe($second)->assertOk();

        // Rotating twice inside one overlap ends the earlier overlap at once.
        $third = $service->rotate($school, $admin, $client->id);
        $fourth = $service->rotate($school, $admin, $client->id);
        $this->probe($second)->assertUnauthorized();
        $this->probe($third)->assertOk();
        $this->probe($fourth)->assertOk();

        $usable = ApiClientCredential::query()->where('api_client_id', $client->id)->get()->filter->isUsable()->count();
        $this->assertSame(2, $usable);
        $this->assertSame(3, $this->audits($school, ApiClientService::ROTATED));
    }

    #[Test]
    public function revocation_is_immediate_and_final(): void
    {
        [$school, $admin, $client, $credential] = $this->issued();
        $this->probe($credential)->assertOk();

        app(ApiClientService::class)->revoke($school, $admin, $client->id);
        $this->probe($credential)->assertUnauthorized();
        $this->assertSame(1, $this->audits($school, ApiClientService::REVOKED));

        foreach (['rotate', 'revoke'] as $op) {
            try {
                app(ApiClientService::class)->{$op}($school, $admin, $client->id);
                $this->fail("A revoked client cannot {$op}.");
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function a_suspended_school_is_a_non_disclosing_404_and_resume_restores_access(): void
    {
        [$school, , , $credential] = $this->issued();

        $school->update(['status' => 'suspended']);
        $this->probe($credential)->assertNotFound()->assertJsonPath('error.message', 'Not found.');

        $school->update(['status' => 'active']);
        $this->probe($credential)->assertOk();
    }

    #[Test]
    public function scopes_fail_closed_and_management_needs_the_capability(): void
    {
        [$school, $admin] = $this->issued();

        // A stored scope outside the available catalog is refused (403, not access).
        $client = ApiClient::query()->create(['school_id' => $school->id, 'name' => 'Odd', 'scopes' => ['academic_structure.read'], 'created_by_user_id' => $admin->id]);
        $secret = PartnerCredentialFormat::newSecret();
        $keyId = PartnerCredentialFormat::newKeyId();
        ApiClientCredential::query()->create(['api_client_id' => $client->id, 'school_id' => $school->id, 'key_id' => $keyId, 'secret_hash' => PartnerCredentialFormat::hash($secret), 'issued_at' => now(), 'expires_at' => now()->addDay()]);
        $this->probe(PartnerCredentialFormat::compose($keyId, $secret))->assertForbidden()->assertJsonPath('error.code', 'API_SCOPE_INSUFFICIENT');

        foreach ([['*'], ['academic_structure.read'], []] as $scopes) {
            try {
                app(ApiClientService::class)->issue($school, $admin, 'X', $scopes);
                $this->fail('Only available partner scopes can be issued: '.json_encode($scopes));
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('scopes', $e->errors());
            }
        }

        $viewer = $this->createUserWithCapabilities($school, ['integrations.api_clients.view']);
        $this->expectException(AccessDeniedHttpException::class);
        app(ApiClientService::class)->issue($school, $viewer, 'X', [PartnerScopeRegistry::PROBE]);
    }

    #[Test]
    public function partner_traffic_is_throttled_per_client_and_failed_auth_is_bounded(): void
    {
        [$school, $admin, $client, $credential] = $this->issued();

        for ($i = 0; $i < 120; $i++) {
            $this->probe($credential)->assertOk();
        }
        $this->probe($credential)->assertStatus(429)->assertHeader('Retry-After');

        // Another client of the same School has its own bucket.
        [$other, $otherCredential] = app(ApiClientService::class)->issue($school, $admin, 'Other', [PartnerScopeRegistry::PROBE]);
        $this->probe($otherCredential)->assertOk();

        // 20 failed authentications for one key id, then 429 before authentication.
        [$keyId] = PartnerCredentialFormat::parse($otherCredential);
        $wrong = PartnerCredentialFormat::compose($keyId, str_repeat('C', 43));
        for ($i = 0; $i < 20; $i++) {
            $this->probe($wrong)->assertUnauthorized();
        }
        $this->probe($wrong)->assertStatus(429)->assertHeader('Retry-After');
        $this->assertSame(20, $this->audits($school, PartnerCredentialAuthenticator::DENIED), 'Denial audit is bounded by the limiter.');
    }

    #[Test]
    public function secrets_and_hashes_never_leave_the_one_time_response(): void
    {
        [$school, $admin, $client, $credential] = $this->issued();
        $rotated = app(ApiClientService::class)->rotate($school, $admin, $client->id);
        [, $secret] = PartnerCredentialFormat::parse($credential);
        $hash = PartnerCredentialFormat::hash($secret);

        $this->probe(PartnerCredentialFormat::compose(PartnerCredentialFormat::parse($credential)[0], str_repeat('D', 43)))->assertUnauthorized();
        app(ApiClientService::class)->revoke($school, $admin, $client->id);

        $audit = app(TenantContext::class)->withSchool($school, fn () => SchoolAuditEvent::query()->where('event_type', 'like', 'integrations.api_client.%')->get()->toJson());
        $columns = collect(DB::getSchemaBuilder()->getColumnListing('api_client_credentials'));
        $this->assertFalse($columns->contains(fn (string $c) => str_contains($c, 'secret') && $c !== 'secret_hash'));

        foreach ([$secret, $hash, $credential, $rotated, PartnerCredentialFormat::parse($rotated)[1]] as $canary) {
            $this->assertStringNotContainsString($canary, $audit);
            foreach ($this->logged as $line) {
                $this->assertStringNotContainsString($canary, $line);
            }
        }
        $this->assertStringNotContainsString($hash, json_encode(ApiClientCredential::query()->where('api_client_id', $client->id)->get()->toArray()));
        $this->assertTrue(Str::isUuid($client->id));
    }
}

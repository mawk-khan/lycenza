<?php

namespace Tests\Feature\CustomDomains;

use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RequireSchoolContext;
use App\Models\PlatformAuditEvent;
use App\Models\School;
use App\Models\User;
use App\Support\Auth\CredentialSession;
use App\Support\Auth\CrossHostHandoff;
use App\Support\Domains\DomainDirectory;
use App\Support\Observability\LogSanitizer;
use Illuminate\Auth\SessionGuard;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CapturesStructuredLogs;
use Tests\Concerns\CreatesSchoolDomains;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 0O.8A (ADR 0054 amendment, section 8.7): host-only cookies cannot
 * follow a School switch to another origin, so the switch carries the
 * sign-in with a one-time, 60 s, server-side ticket bound to the user, the
 * source session, the School and the exact target hostname -- and the
 * target re-checks everything before it signs anyone in. The ticket is
 * continuity, never authority.
 */
class CrossHostHandoffTest extends TestCase
{
    use CapturesStructuredLogs, CreatesSchoolDomains, CreatesTenancyFixtures;

    /** @return array{0: User, 1: School, 2: School} [user, platform-host School, custom-domain School] */
    private function scene(string $host = 'erp.northfield.org'): array
    {
        $user = $this->createUser();
        $plain = $this->createSchool(['name' => 'Plain School']);
        $custom = $this->createSchool(['name' => 'Northfield Academy']);
        $this->createMembership($user, $plain);
        $this->createMembership($user, $custom);
        $this->createSchoolDomain($custom, $host);

        return [$user, $plain, $custom];
    }

    /** Signed in the way a real login leaves the session (actingAs alone writes no session key). */
    private function signIn(User $user): static
    {
        /** @var SessionGuard $guard */
        $guard = Auth::guard('web');

        return $this->actingAs($user)->withSession([$guard->getName() => $user->id, CredentialSession::KEY => CredentialSession::current($user)]);
    }

    /** Switch on the platform host and return the handoff URL the browser is sent to. */
    private function switchTo(User $user, School $school): string
    {
        $response = $this->signIn($user)->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request())])
            ->post("http://localhost/app/schools/{$school->id}/activate");
        $this->flushHeaders();
        $response->assertStatus(409);

        return (string) $response->headers->get('X-Inertia-Location');
    }

    private function ticketOf(string $url): string
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return (string) $query['ticket'];
    }

    private function redeem(string $url, string $host = 'erp.northfield.org'): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->get('http://'.$host.'/session/handoff?ticket='.urlencode($this->ticketOf($url)));
    }

    #[Test]
    public function a_same_origin_switch_is_unchanged(): void
    {
        [$user, $plain] = $this->scene();

        $this->signIn($user)->post("http://localhost/app/schools/{$plain->id}/activate")->assertRedirect('/app');
        $this->assertSame($plain->id, session(RequireSchoolContext::SESSION_KEY));
    }

    #[Test]
    public function switching_to_a_school_with_a_custom_domain_navigates_there_with_a_one_time_ticket(): void
    {
        [$user, , $custom] = $this->scene();

        $url = $this->switchTo($user, $custom);

        $this->assertMatchesRegularExpression('#^https://erp\.northfield\.org/session/handoff\?ticket=[A-Za-z0-9_-]{43}$#', $url, 'the canonical origin, never the request Host');
        $audit = PlatformAuditEvent::query()->where('event_type', 'school_context.activated')->latest('occurred_at')->first();
        $this->assertEquals(['cross_host' => true], $audit->metadata);
        $this->assertStringNotContainsString($this->ticketOf($url), json_encode(PlatformAuditEvent::query()->get()->toArray()), 'the ticket is never audited');

        $response = $this->redeem($url);

        $response->assertStatus(303)->assertRedirect('/app');
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
        $this->assertAuthenticatedAs($user);
        $this->assertSame($custom->id, session(RequireSchoolContext::SESSION_KEY));
        $this->assertSame(1, PlatformAuditEvent::query()->where('event_type', 'auth.session_handoff_redeemed')->where('actor_user_id', $user->id)->count());
    }

    #[Test]
    public function a_ticket_is_single_use(): void
    {
        [$user, , $custom] = $this->scene();
        $url = $this->switchTo($user, $custom);

        $this->redeem($url)->assertStatus(303);
        $this->redeem($url)->assertForbidden()->assertInertia(fn ($page) => $page->component('Auth/HandoffFailed'));
    }

    #[Test]
    public function a_ticket_works_only_on_its_exact_target_host(): void
    {
        [$user, $plain, $custom] = $this->scene();
        $url = $this->switchTo($user, $custom);
        $this->createSchoolDomain($plain, 'erp.plain-school.org');

        $this->redeem($url, 'erp.plain-school.org')->assertForbidden();      // another School's host
        $this->redeem($this->switchTo($user, $custom), 'localhost')->assertForbidden(); // the platform host
        $this->assertNotRedeemed();
    }

    #[Test]
    public function a_ticket_for_one_school_cannot_sign_in_to_another_schools_host(): void
    {
        [$user, , $custom] = $this->scene();
        $url = $this->switchTo($user, $custom);

        // The hostname moves to another School between issue and redemption.
        DB::table('school_domains')->where('hostname', 'erp.northfield.org')->update(['state' => 'revoked', 'is_primary' => false, 'revoked_at' => now(), 'revocation_source' => 'school']);
        $other = $this->createSchool();
        $this->createMembership($user, $other);
        $this->createSchoolDomain($other, 'erp.northfield.org');
        app(DomainDirectory::class)->forget('erp.northfield.org');

        $this->redeem($url)->assertForbidden();
        $this->assertNotRedeemed();
    }

    #[Test]
    public function an_expired_ticket_is_refused(): void
    {
        [$user, , $custom] = $this->scene();
        $url = $this->switchTo($user, $custom);

        $this->travel(CrossHostHandoff::LIFETIME_SECONDS + 1)->seconds();

        $this->redeem($url)->assertForbidden();
        $this->assertNotRedeemed();
    }

    #[Test]
    public function losing_access_between_issue_and_redemption_voids_the_ticket(): void
    {
        foreach (['membership', 'user', 'school', 'domain', 'source'] as $change) {
            $host = "erp.{$change}-school.org";
            [$user, , $custom] = $this->scene($host);
            $url = $this->switchTo($user, $custom);

            match ($change) {
                'membership' => DB::table('school_memberships')->where('user_id', $user->id)->where('school_id', $custom->id)->update(['status' => 'suspended']),
                'user' => DB::table('users')->where('id', $user->id)->update(['is_disabled' => true]),
                'school' => DB::table('schools')->where('id', $custom->id)->update(['status' => 'suspended']),
                'domain' => DB::table('school_domains')->where('school_id', $custom->id)->update(['state' => 'suspended', 'is_primary' => false, 'suspended_at' => now(), 'suspension_reason' => 'tls']),
                'source' => app('session')->driver()->getHandler()->destroy($this->sourceSessionOf($url)),
            };
            app(DomainDirectory::class)->forgetSchool($custom->id);

            $response = $this->redeem($url, $host);
            $this->assertContains($response->getStatusCode(), [403, 421], $change);
            $this->assertNotRedeemed($change);
        }
    }

    /** No sign-in was established anywhere by a refused redemption. */
    private function assertNotRedeemed(string $message = ''): void
    {
        $this->assertSame(0, PlatformAuditEvent::query()->where('event_type', 'auth.session_handoff_redeemed')->count(), $message);
    }

    private function sourceSessionOf(string $url): string
    {
        $key = 'session-handoff:'.hash('sha256', $this->ticketOf($url));

        return (string) (cache()->get($key)['source_session'] ?? '');
    }

    #[Test]
    public function a_ticket_never_replaces_a_different_signed_in_user_on_the_target_host(): void
    {
        [$user, , $custom] = $this->scene();
        $url = $this->switchTo($user, $custom);
        $someoneElse = $this->createUser();
        $this->createMembership($someoneElse, $custom);

        $this->actingAs($someoneElse)->get('http://erp.northfield.org/session/handoff?ticket='.$this->ticketOf($url))->assertForbidden();
        $this->assertAuthenticatedAs($someoneElse);
    }

    #[Test]
    public function mfa_assurance_is_carried_unchanged_never_refreshed(): void
    {
        [$user, , $custom] = $this->scene();
        $verifiedAt = now()->subMinutes(3)->toIso8601String();
        $this->withSession(['mfa_verified_at' => $verifiedAt]);
        $url = $this->switchTo($user, $custom);

        $this->redeem($url)->assertStatus(303);
        $this->assertSame($verifiedAt, session('mfa_verified_at'));

        [$user2, , $custom2] = [$this->createUser(), null, $this->createSchool()];
        $this->createMembership($user2, $custom2);
        $this->createSchoolDomain($custom2, 'erp.southfield.org');
        $this->withSession(['mfa_verified_at' => now()->subDays(2)->toIso8601String()]);
        $stale = $this->switchTo($user2, $custom2);
        $this->redeem($stale, 'erp.southfield.org')->assertStatus(303);
        $this->assertNull(session('mfa_verified_at'), 'a stale assurance is not carried');
    }

    #[Test]
    public function a_handoff_store_outage_fails_closed_without_touching_same_host_work(): void
    {
        [$user, $plain, $custom] = $this->scene();
        config(['domains.handoff_store' => 'unavailable-store', 'cache.stores.unavailable-store' => ['driver' => 'redis', 'connection' => 'unreachable']]);
        config(['database.redis.unreachable' => ['host' => '127.0.0.1', 'port' => 1, 'timeout' => 0.2, 'read_timeout' => 0.2]]);

        $this->actingAs($user)->post("http://localhost/app/schools/{$custom->id}/activate")
            ->assertSessionHasErrors(['school' => 'Switching to that School is temporarily unavailable. Try again shortly.']);
        $this->actingAs($user)->post("http://localhost/app/schools/{$plain->id}/activate")->assertRedirect('/app');

        $this->app['auth']->forgetGuards();
        $this->get('http://erp.northfield.org/session/handoff?ticket='.str_repeat('A', 43))->assertForbidden();
        $this->assertNotRedeemed();
    }

    #[Test]
    public function malformed_tickets_are_refused_and_no_ticket_ever_reaches_a_log(): void
    {
        [$user, , $custom] = $this->scene();
        $this->captureLogs();

        foreach (['', 'short', str_repeat('A', 44), str_repeat('!', 43)] as $bad) {
            $this->get('http://erp.northfield.org/session/handoff?ticket='.urlencode($bad))->assertForbidden();
        }

        $url = $this->switchTo($user, $custom);
        $this->redeem($url)->assertStatus(303);
        $this->redeem($url)->assertForbidden();

        $this->assertStringNotContainsString($this->ticketOf($url), $this->capturedOutput(), 'the ticket never reaches a log');
        $this->assertStringContainsString('session_handoff.failed', $this->capturedOutput());
        $this->assertSame('/session/handoff?ticket=[redacted]', app(LogSanitizer::class)->sanitizeString('/session/handoff?ticket='.$this->ticketOf($url)));
    }
}

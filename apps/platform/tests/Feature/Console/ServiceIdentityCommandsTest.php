<?php

namespace Tests\Feature\Console;

use App\Models\PlatformAuditEvent;
use App\Models\ServiceIdentity;
use App\Support\ServiceIdentities\ServiceIdentityAuthenticator;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 0O.1: operator issue/disable of service identities. The credential
 * is printed exactly once and never persisted in cleartext, audited or
 * logged; only the two internal-service capabilities are issuable; no
 * rotation, no HTTP surface.
 */
class ServiceIdentityCommandsTest extends TestCase
{
    /** @var list<string> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();
        Event::listen(MessageLogged::class, function (MessageLogged $event): void {
            $this->logged[] = $event->message.' '.json_encode($event->context);
        });
    }

    private function issue(string $slug, array $capabilities = ['ai.tools.invoke'], bool $force = true): array
    {
        $exit = Artisan::call('platform:service-identity-issue', array_filter([
            'slug' => $slug,
            '--capability' => $capabilities,
            '--force' => $force,
            '--no-interaction' => true,
        ]));

        return [$exit, Artisan::output()];
    }

    #[Test]
    public function issuing_prints_the_credential_once_and_stores_only_its_hash(): void
    {
        [$exit, $output] = $this->issue('ops-gateway-a', ['ai.tools.invoke', 'ai.audit.write']);
        $this->assertSame(0, $exit, $output);

        $this->assertSame(1, preg_match('/^([A-Za-z0-9]{64})$/m', $output, $m), $output);
        $credential = $m[1];

        $identity = ServiceIdentity::query()->where('slug', 'ops-gateway-a')->firstOrFail();
        $this->assertSame(hash('sha256', $credential), $identity->credential_hash);
        $this->assertTrue($identity->enabled);
        $this->assertEqualsCanonicalizing(['ai.tools.invoke', 'ai.audit.write'], $identity->capabilities()->pluck('key')->all());

        // It authenticates, with exactly its capabilities.
        $authenticator = app(ServiceIdentityAuthenticator::class);
        $resolved = $authenticator->resolve($credential);
        $this->assertSame($identity->id, $resolved?->id);
        $this->assertTrue($authenticator->authorize($resolved, 'ai.audit.write'));
        $this->assertFalse($authenticator->authorize($resolved, 'platform.schools.manage'));

        // The cleartext is nowhere: not in any column, the audit event, or a log line.
        $event = PlatformAuditEvent::query()->where('event_type', 'platform.service_identity.issued')->where('subject_id', $identity->id)->sole();
        $this->assertSame(['slug' => 'ops-gateway-a', 'capabilities' => ['ai.tools.invoke', 'ai.audit.write']], $event->metadata);
        $this->assertNull($event->actor_user_id);
        $this->assertStringNotContainsString($credential, json_encode(DB::table('service_identities')->where('id', $identity->id)->first()));
        $this->assertStringNotContainsString($credential, json_encode(DB::table('platform_audit_events')->where('subject_id', $identity->id)->get()));
        foreach ($this->logged as $line) {
            $this->assertStringNotContainsString($credential, $line);
        }
    }

    #[Test]
    public function issuing_refuses_bad_input_an_existing_slug_and_any_other_capability(): void
    {
        foreach ([
            ['Bad_Slug', ['ai.tools.invoke']],
            ['ab', ['ai.tools.invoke']],
            ['ops-gateway-b', []],
            ['ops-gateway-b', ['platform.schools.manage']],
            ['ops-gateway-b', ['ai.tools.invoke', 'platform.role_grants.manage']],
            ['ai-gateway', ['ai.tools.invoke']],
        ] as [$slug, $capabilities]) {
            [$exit, $output] = $this->issue($slug, $capabilities);
            $this->assertSame(1, $exit, "{$slug}: {$output}");
            $this->assertStringContainsString('Refused', $output);
        }

        $this->assertFalse(ServiceIdentity::query()->where('slug', 'ops-gateway-b')->exists());
        $this->assertSame(0, PlatformAuditEvent::query()->where('event_type', 'platform.service_identity.issued')->count());
    }

    #[Test]
    public function issuing_requires_confirmation_unless_forced(): void
    {
        [$exit, $output] = $this->issue('ops-gateway-c', force: false);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('confirmation is required', $output);
        $this->assertFalse(ServiceIdentity::query()->where('slug', 'ops-gateway-c')->exists());

        $this->artisan('platform:service-identity-issue', ['slug' => 'ops-gateway-c', '--capability' => ['ai.tools.invoke']])
            ->expectsConfirmation('Issue service identity [ops-gateway-c] with: ai.tools.invoke?', 'no')
            ->assertFailed();
        $this->assertFalse(ServiceIdentity::query()->where('slug', 'ops-gateway-c')->exists());

        $this->artisan('platform:service-identity-issue', ['slug' => 'ops-gateway-c', '--capability' => ['ai.tools.invoke']])
            ->expectsConfirmation('Issue service identity [ops-gateway-c] with: ai.tools.invoke?', 'yes')
            ->assertSuccessful();
        $this->assertTrue(ServiceIdentity::query()->where('slug', 'ops-gateway-c')->exists());
    }

    #[Test]
    public function disabling_stops_authentication_and_is_idempotent(): void
    {
        [, $output] = $this->issue('ops-gateway-d');
        preg_match('/^([A-Za-z0-9]{64})$/m', $output, $m);
        $identity = ServiceIdentity::query()->where('slug', 'ops-gateway-d')->firstOrFail();

        $this->assertSame(1, Artisan::call('platform:service-identity-disable', ['slug' => 'ops-gateway-d', '--no-interaction' => true]));
        $this->assertStringContainsString('confirmation is required', Artisan::output());
        $this->assertTrue($identity->fresh()->enabled);

        $this->assertSame(0, Artisan::call('platform:service-identity-disable', ['slug' => 'ops-gateway-d', '--force' => true]));
        $this->assertFalse($identity->fresh()->enabled);
        $this->assertNull(app(ServiceIdentityAuthenticator::class)->resolve($m[1]));

        $this->assertSame(0, Artisan::call('platform:service-identity-disable', ['slug' => 'ops-gateway-d', '--force' => true]));
        $this->assertStringContainsString('Already disabled', Artisan::output());
        $this->assertSame(1, PlatformAuditEvent::query()->where('event_type', 'platform.service_identity.disabled')->where('subject_id', $identity->id)->count());

        $this->assertSame(1, Artisan::call('platform:service-identity-disable', ['slug' => 'no-such-identity', '--force' => true]));
        $this->assertStringContainsString('Refused', Artisan::output());
    }

    #[Test]
    public function there_is_no_http_surface_for_service_identity_administration(): void
    {
        foreach (app(Router::class)->getRoutes()->getRoutes() as $route) {
            $this->assertStringNotContainsString('ServiceIdentit', (string) $route->getActionName(), $route->uri());
            $this->assertStringNotContainsString('service-identit', $route->uri());
        }
    }
}

<?php

namespace Tests\Feature\Platform\Roles;

use App\Domain\Platform\Application\Roles\PlatformRootProvisioningService;
use App\Models\User;
use App\Support\Authorization\CapabilityResolver;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Concerns\ForcesConcurrentOverlap;
use Tests\TestCase;

/**
 * Phase 0O.1A: first-boot bootstrap (`platform:bootstrap-root`) -- the one
 * operator-console way a fresh installation gets its first platform
 * account. Non-transactional: the command commits on the admin
 * connection; TestCase purges committed platform-role fixtures afterwards
 * and tearDown() removes the accounts this test created.
 */
class PlatformRootBootstrapTest extends TestCase
{
    use CreatesTenancyFixtures, ForcesConcurrentOverlap;

    private const PASSWORD = 'Canary-Bootstrap-Passw0rd-7f3a9c';

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    /** @var list<string> */
    private array $emails = [];

    /** @var list<string> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();
        Event::listen(MessageLogged::class, function (MessageLogged $event): void {
            $this->logged[] = $event->message.' '.json_encode($event->context);
        });
    }

    protected function tearDown(): void
    {
        $admin = DB::connection('pgsql_admin');
        $ids = $admin->table('users')->whereIn('email', $this->emails)->pluck('id')->all();
        $grants = $admin->table('platform_role_assignments')->whereIn('user_id', $ids)->pluck('id')->all();
        $admin->table('platform_audit_events')->whereIn('subject_id', $grants)->delete();
        $admin->table('platform_role_assignments')->whereIn('user_id', $ids)->delete();
        $admin->table('users')->whereIn('id', $ids)->delete();

        parent::tearDown();
    }

    private function email(): string
    {
        return $this->emails[] = 'first.root.'.bin2hex(random_bytes(4)).'@example.test';
    }

    private function roots(): int
    {
        return DB::table('platform_role_assignments')
            ->where('role_id', app(PlatformRootProvisioningService::class)->rootRole()->id)->whereNull('revoked_at')->count();
    }

    #[Test]
    public function a_fresh_installation_bootstraps_one_real_root_account(): void
    {
        $this->assertSame(0, $this->roots(), 'The test database holds no root at rest.');
        $email = $this->email();

        $this->artisan('platform:bootstrap-root')
            ->expectsQuestion('Full name', 'First Operator')
            ->expectsQuestion('Email address (the sign-in identity)', strtoupper($email))
            ->expectsQuestion('Password (hidden)', self::PASSWORD)
            ->expectsQuestion('Confirm password (hidden)', self::PASSWORD)
            ->expectsConfirmation('Create this account and grant it the root platform role?', 'yes')
            ->doesntExpectOutputToContain(self::PASSWORD)
            ->assertSuccessful();

        $user = User::query()->where('email', $email)->sole();
        $this->assertFalse($user->isDisabled());
        $this->assertTrue(Hash::check(self::PASSWORD, $user->getAuthPassword()));
        $this->assertNotSame(self::PASSWORD, $user->getAuthPassword());

        // It is a real account that can sign in with the chosen password.
        $this->assertTrue(Auth::validate(['email' => $email, 'password' => self::PASSWORD]));
        $this->post('/login', ['email' => $email, 'password' => self::PASSWORD])->assertRedirect();
        $this->assertAuthenticatedAs($user);

        // Exactly the root grant: no School membership, no Group grant.
        $grant = DB::table('platform_role_assignments')->where('user_id', $user->id)->sole();
        $this->assertNull($grant->granted_by_user_id);
        $this->assertSame(1, $this->roots());
        $this->assertTrue(app(CapabilityResolver::class)->canPlatform($user, 'platform.role_grants.manage'));
        $this->assertSame(0, DB::connection('pgsql_admin')->table('school_memberships')->where('user_id', $user->id)->count());
        $this->assertSame(0, DB::table('group_role_assignments')->where('user_id', $user->id)->count());

        // ADR 0046 audit contract; never the password or its hash.
        $event = DB::table('platform_audit_events')->where('event_type', PlatformRootProvisioningService::EVENT)->where('subject_id', $grant->id)->sole();
        $this->assertNull($event->actor_user_id);
        $this->assertEquals(['role_key' => app(PlatformRootProvisioningService::class)->rootRole()->key, 'user_id' => $user->id, 'method' => 'console'], json_decode($event->metadata, true));
        foreach ([self::PASSWORD, $user->getAuthPassword()] as $secret) {
            $this->assertStringNotContainsString($secret, json_encode(DB::table('platform_audit_events')->where('subject_id', $grant->id)->get()));
            foreach ($this->logged as $line) {
                $this->assertStringNotContainsString($secret, $line);
            }
        }
    }

    #[Test]
    public function once_a_root_exists_bootstrap_refuses_and_asks_for_nothing(): void
    {
        $existing = $this->createPlatformRoot(['email' => $this->email()]);

        $this->artisan('platform:bootstrap-root')
            ->expectsOutputToContain('a root platform account already exists')
            ->assertFailed();

        $this->assertSame(1, $this->roots());
        $this->assertSame(1, User::query()->whereIn('email', $this->emails)->count());
        $this->assertSame($existing->id, User::query()->whereIn('email', $this->emails)->value('id'));
    }

    #[Test]
    public function it_refuses_non_interactive_runs_bad_passwords_and_existing_accounts(): void
    {
        $this->assertSame(1, Artisan::call('platform:bootstrap-root', ['--no-interaction' => true]));
        $this->assertStringContainsString('interactive only', Artisan::output());

        $email = $this->email();
        foreach ([['short', 'short'], [self::PASSWORD, self::PASSWORD.'x']] as [$password, $confirmation]) {
            $this->artisan('platform:bootstrap-root')
                ->expectsQuestion('Full name', 'First Operator')
                ->expectsQuestion('Email address (the sign-in identity)', $email)
                ->expectsQuestion('Password (hidden)', $password)
                ->expectsQuestion('Confirm password (hidden)', $confirmation)
                ->doesntExpectOutputToContain($password)
                ->assertFailed();
        }

        $this->artisan('platform:bootstrap-root')
            ->expectsQuestion('Full name', 'First Operator')
            ->expectsQuestion('Email address (the sign-in identity)', 'not-an-email')
            ->assertFailed();

        // An existing account is never converted by bootstrap.
        $existing = User::factory()->connection('pgsql_admin')->create(['email' => $this->email()]);
        $this->artisan('platform:bootstrap-root')
            ->expectsQuestion('Full name', 'First Operator')
            ->expectsQuestion('Email address (the sign-in identity)', $existing->email)
            ->expectsQuestion('Password (hidden)', self::PASSWORD)
            ->expectsQuestion('Confirm password (hidden)', self::PASSWORD)
            ->expectsConfirmation('Create this account and grant it the root platform role?', 'yes')
            ->expectsOutputToContain('use platform:provision-root')
            ->assertFailed();

        $this->assertFalse(User::query()->where('email', $email)->exists());
        $this->assertSame(0, $this->roots());
        $this->assertSame(0, DB::table('platform_audit_events')->where('event_type', PlatformRootProvisioningService::EVENT)->count());
    }

    #[Test]
    public function two_concurrent_first_boots_leave_exactly_one_root(): void
    {
        $script = __DIR__.'/../../../Support/bootstrap-root-op.php';
        [$first, $second] = [$this->email(), $this->email()];

        [$holder, $contender] = $this->raceWithHeldHolder(['php', $script, $first], ['php', $script, $second]);

        $this->assertSame('bootstrapped', $holder);
        $this->assertSame('refused:root_already_exists', $contender);
        $this->assertSame(1, $this->roots());
        $this->assertTrue(User::query()->where('email', $first)->exists());
        $this->assertFalse(User::query()->where('email', $second)->exists(), 'The refused bootstrap created no account.');
    }

    #[Test]
    public function bootstrap_has_no_http_surface(): void
    {
        foreach (app(Router::class)->getRoutes()->getRoutes() as $route) {
            $this->assertStringNotContainsString('BootstrapPlatformRoot', (string) $route->getActionName(), $route->uri());
            $this->assertStringNotContainsString('bootstrap-root', $route->uri());
        }
    }
}

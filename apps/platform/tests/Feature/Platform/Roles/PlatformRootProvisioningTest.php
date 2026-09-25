<?php

namespace Tests\Feature\Platform\Roles;

use App\Domain\Platform\Application\Roles\PlatformRoleGovernanceService;
use App\Domain\Platform\Application\Roles\PlatformRootProvisioningService;
use App\Models\PlatformRoleAssignment;
use App\Models\User;
use App\Support\Authorization\CapabilityResolver;
use Illuminate\Database\QueryException;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Concerns\ForcesConcurrentOverlap;
use Tests\TestCase;

/**
 * Phase 0O.1 (ADR 0046 section 2): the operator console provisioning of
 * the root platform role. Non-transactional: the command writes on the
 * admin connection, which cannot see a test transaction's rows, so
 * fixtures are committed and removed in tearDown().
 */
class PlatformRootProvisioningTest extends TestCase
{
    use CreatesTenancyFixtures, ForcesConcurrentOverlap;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    /** @var list<string> */
    private array $userIds = [];

    protected function tearDown(): void
    {
        $admin = DB::connection('pgsql_admin');
        $assignments = $admin->table('platform_role_assignments')->whereIn('user_id', $this->userIds)->pluck('id');
        $admin->table('platform_audit_events')->whereIn('subject_id', $assignments)->delete();
        $admin->table('platform_audit_events')->whereIn('actor_user_id', $this->userIds)->delete();
        $admin->table('platform_role_assignments')->whereIn('user_id', $this->userIds)->delete();
        $admin->table('users')->whereIn('id', $this->userIds)->delete();

        parent::tearDown();
    }

    private function operator(array $attributes = []): User
    {
        $user = $this->createUser(['email' => 'root.'.bin2hex(random_bytes(4)).'@example.test', ...$attributes]);
        $this->userIds[] = $user->id;

        return $user;
    }

    private function rootRoleId(): string
    {
        return app(PlatformRootProvisioningService::class)->rootRole()->id;
    }

    private function activeRoots(User $user): int
    {
        return DB::connection('pgsql_admin')->table('platform_role_assignments')
            ->where('user_id', $user->id)->where('role_id', $this->rootRoleId())->whereNull('revoked_at')->count();
    }

    private function provisionedEvents(User $user): int
    {
        return DB::connection('pgsql_admin')->table('platform_audit_events')
            ->where('event_type', PlatformRootProvisioningService::EVENT)->whereJsonContains('metadata->user_id', $user->id)->count();
    }

    #[Test]
    public function it_provisions_the_root_role_with_the_adr_audit_event_and_takes_effect_at_once(): void
    {
        $user = $this->operator();
        $resolver = app(CapabilityResolver::class);
        $this->assertFalse($resolver->canPlatform($user, 'platform.role_grants.manage'));

        $this->assertSame(0, Artisan::call('platform:provision-root', ['user' => strtoupper($user->email), '--force' => true]));
        $this->assertStringContainsString('Provisioned', Artisan::output());

        $assignment = PlatformRoleAssignment::query()->where('user_id', $user->id)->sole();
        $this->assertNull($assignment->granted_by_user_id);
        $this->assertSame($this->rootRoleId(), $assignment->role_id);

        $event = DB::connection('pgsql_admin')->table('platform_audit_events')->where('event_type', PlatformRootProvisioningService::EVENT)->where('subject_id', $assignment->id)->sole();
        $this->assertNull($event->actor_user_id);
        $this->assertSame(PlatformRoleAssignment::class, $event->subject_type);
        $roleKey = DB::table('roles')->where('id', $this->rootRoleId())->value('key');
        $this->assertEquals(['role_key' => $roleKey, 'user_id' => $user->id, 'method' => 'console'], json_decode($event->metadata, true)); // jsonb: key order is not kept

        // The capability cache was forgotten: authority applies immediately.
        $this->assertTrue($resolver->canPlatform($user->fresh(), 'platform.role_grants.manage'));
    }

    #[Test]
    public function it_is_idempotent_by_id_or_email(): void
    {
        $user = $this->operator();

        $this->assertSame(0, Artisan::call('platform:provision-root', ['user' => $user->id, '--force' => true]));
        $this->assertSame(0, Artisan::call('platform:provision-root', ['user' => $user->email, '--force' => true]));
        $this->assertStringContainsString('Already provisioned', Artisan::output());
        $this->assertSame('already_provisioned', app(PlatformRootProvisioningService::class)->provision($user));

        $this->assertSame(1, $this->activeRoots($user));
        $this->assertSame(1, $this->provisionedEvents($user));
    }

    #[Test]
    public function it_refuses_unknown_disabled_and_malformed_identifiers_without_echoing_them(): void
    {
        $disabled = $this->operator(['is_disabled' => true, 'disabled_at' => now()]);

        foreach ([
            'nobody.'.bin2hex(random_bytes(4)).'@example.test' => 'No account has exactly',
            '0199aaaa-0000-7000-8000-000000000000' => 'No account has exactly',
            'root%' => 'exact email address or user id',
            'admin' => 'exact email address or user id',
            '' => 'exact email address or user id',
            $disabled->email => 'disabled',
        ] as $identifier => $message) {
            $this->assertSame(1, Artisan::call('platform:provision-root', ['user' => $identifier, '--force' => true]), (string) $identifier);
            $output = Artisan::output();
            $this->assertStringContainsString('Refused', $output);
            $this->assertStringContainsString($message, $output);
            if ($identifier !== '') {
                $this->assertStringNotContainsString((string) $identifier, $output);
            }
        }

        $this->assertSame(0, $this->activeRoots($disabled));
        $this->assertSame(0, DB::connection('pgsql_admin')->table('platform_audit_events')->where('event_type', PlatformRootProvisioningService::EVENT)->whereJsonContains('metadata->user_id', $disabled->id)->count());
    }

    #[Test]
    public function it_needs_an_exact_typed_confirmation_or_an_explicit_force(): void
    {
        $user = $this->operator();

        // Non-interactive without --force: refused.
        $this->assertSame(1, Artisan::call('platform:provision-root', ['user' => $user->email, '--no-interaction' => true]));
        $this->assertStringContainsString('confirmation is required', Artisan::output());

        $this->artisan('platform:provision-root', ['user' => $user->id])
            ->expectsOutputToContain($user->email)
            ->expectsQuestion('Type the account\'s email address exactly to confirm', 'someone.else@example.test')
            ->expectsOutput('Confirmation did not match. Nothing changed.')
            ->assertFailed();
        $this->assertSame(0, $this->activeRoots($user));

        $this->artisan('platform:provision-root', ['user' => $user->id])
            ->expectsQuestion('Type the account\'s email address exactly to confirm', $user->email)
            ->assertSuccessful();
        $this->assertSame(1, $this->activeRoots($user));
    }

    #[Test]
    public function it_refuses_when_the_admin_connection_is_the_runtime_role(): void
    {
        $user = $this->operator();
        $adminUsername = config('database.connections.pgsql_admin.username');
        config(['database.connections.pgsql_admin.username' => config('database.connections.pgsql.username')]);

        try {
            $this->assertSame(1, Artisan::call('platform:provision-root', ['user' => $user->email, '--force' => true]));
            $this->assertStringContainsString('separate migration/admin database connection', Artisan::output());
        } finally {
            config(['database.connections.pgsql_admin.username' => $adminUsername]);
            DB::purge('pgsql_admin');
        }

        $this->assertSame(0, PlatformRoleAssignment::query()->where('user_id', $user->id)->count());
    }

    #[Test]
    public function two_concurrent_provisionings_leave_one_assignment_and_one_event(): void
    {
        $user = $this->operator();
        $script = __DIR__.'/../../../Support/provision-root-op.php';

        [$holder, $contender] = $this->raceWithHeldHolder(
            ['php', $script, $user->email],
            ['php', $script, $user->email],
        );

        $this->assertSame('provisioned', $holder);
        $this->assertSame('already_provisioned', $contender);
        $this->assertSame(1, $this->activeRoots($user));
        $this->assertSame(1, $this->provisionedEvents($user));
    }

    #[Test]
    public function the_runtime_paths_still_refuse_the_root_role(): void
    {
        $root = $this->operator();
        $this->assertSame(0, Artisan::call('platform:provision-root', ['user' => $root->email, '--force' => true]));
        $target = $this->operator();
        $roleKey = DB::table('roles')->where('id', $this->rootRoleId())->value('key');

        // The governance service refuses to grant it...
        try {
            app(PlatformRoleGovernanceService::class)->grant($root->fresh(), $target->email, $roleKey);
            $this->fail('The runtime grant path must refuse the root role.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('role', $e->errors());
        }

        // ...and the database refuses a runtime (grantor-named) grant of it.
        try {
            DB::table('platform_role_assignments')->insert([
                'id' => (string) Str::uuid7(), 'user_id' => $target->id, 'role_id' => $this->rootRoleId(),
                'granted_by_user_id' => $root->id, 'granted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->fail('The database must refuse a runtime grant of the root role.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('only a runtime-assignable role can be granted at runtime', $e->getMessage());
        }

        $this->assertSame(0, $this->activeRoots($target));
    }

    #[Test]
    public function only_the_governance_service_and_the_console_provisioner_write_platform_role_grants(): void
    {
        $writers = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path())) as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $code = (string) file_get_contents($file->getPathname());
            if (preg_match('/PlatformRoleAssignment::(query\(\)->create|create|on\([^)]*\)->create|insert)|table\([\'"]platform_role_assignments[\'"]\)->insert/', $code) === 1) {
                $writers[] = str_replace(app_path().'/', '', $file->getPathname());
            }
        }
        sort($writers);

        $this->assertSame([
            'Domain/Platform/Application/Roles/PlatformRoleGovernanceService.php',
            'Domain/Platform/Application/Roles/PlatformRootProvisioningService.php',
        ], $writers);

        // Seeders: only the local demo writes a platform grant directly, and
        // only a runtime-assignable one naming a grantor; its root account
        // goes through the provisioning service (Phase 0O.1A).
        $seederWriters = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(database_path('seeders'))) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php' && str_contains((string) file_get_contents($file->getPathname()), 'PlatformRoleAssignment::')) {
                $seederWriters[] = basename($file->getPathname());
            }
        }
        $this->assertSame(['DemoDataBuilder.php'], $seederWriters);
        $demo = (string) file_get_contents(database_path('seeders/Demo/DemoDataBuilder.php'));
        $this->assertSame(1, substr_count($demo, 'PlatformRoleAssignment::query()->create('));
        $this->assertStringContainsString("->provision(\$platformAdmin, 'demo_seed')", $demo);
        $this->assertMatchesRegularExpression("/PlatformRoleAssignment::query\\(\\)->create\\(\\[[^\\]]*'platform_auditor'[^\\]]*'granted_by_user_id' => \\\$platformAdmin->id/s", $demo);

        // The runtime governance service always names a grantor, so only the
        // console provisioner writes a grantor-less (out-of-band) grant.
        $governance = (string) file_get_contents(app_path('Domain/Platform/Application/Roles/PlatformRoleGovernanceService.php'));
        $this->assertStringContainsString('\'granted_by_user_id\' => $actor->id', $governance);
        $this->assertStringNotContainsString("'granted_by_user_id' => null", $governance);
    }

    #[Test]
    public function provisioning_has_no_http_surface(): void
    {
        foreach (app(Router::class)->getRoutes()->getRoutes() as $route) {
            $this->assertStringNotContainsString('Provision', (string) $route->getActionName(), $route->uri());
            $this->assertStringNotContainsString('provision-root', $route->uri());
        }

        foreach (['app/Http', 'routes'] as $dir) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($dir)));
            foreach ($files as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $this->assertStringNotContainsString('PlatformRootProvisioningService', (string) file_get_contents($file->getPathname()), $file->getPathname());
                }
            }
        }
    }
}

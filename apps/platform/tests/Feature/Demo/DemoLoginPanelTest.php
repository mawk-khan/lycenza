<?php

namespace Tests\Feature\Demo;

use App\Models\Capability;
use App\Models\User;
use Database\Seeders\Demo\DemoAccountCatalog;
use Database\Seeders\Demo\DemoDataBuilder;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The login page's "Demo accounts" shortcuts (App\Support\Demo\DemoLoginPanel)
 * must appear ONLY in a local DDEV demo environment -- decided server-side
 * by DemoEnvironmentGuard -- and must only prefill the normal login form.
 */
class DemoLoginPanelTest extends TestCase
{
    private string|false $originalIsDdevProject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalIsDdevProject = getenv('IS_DDEV_PROJECT');

        foreach (DemoAccountCatalog::loginShortcuts() as $account) {
            User::factory()->create(['email' => $account['email'], 'password' => DemoDataBuilder::DEMO_PASSWORD]);
        }
    }

    protected function tearDown(): void
    {
        putenv($this->originalIsDdevProject === false ? 'IS_DDEV_PROJECT' : 'IS_DDEV_PROJECT='.$this->originalIsDdevProject);

        parent::tearDown();
    }

    /**
     * Simulate the DDEV web container: environment name, DDEV's own
     * marker variable, and both connections on DDEV's private `db`
     * database. (Only configuration values change -- the test's already
     * open connection to school_os_test is reused.)
     */
    private function environment(string $appEnv, ?string $isDdevProject, string $dbHost = 'db', string $dbName = 'db'): void
    {
        $this->app['env'] = $appEnv;
        putenv($isDdevProject === null ? 'IS_DDEV_PROJECT' : "IS_DDEV_PROJECT={$isDdevProject}");

        foreach (['pgsql', 'pgsql_admin'] as $connection) {
            config([
                "database.connections.{$connection}.host" => $dbHost,
                "database.connections.{$connection}.database" => $dbName,
                "database.connections.{$connection}.url" => null,
            ]);
        }
    }

    #[Test]
    public function the_panel_is_shown_in_a_local_ddev_demo_environment_and_carries_only_the_catalogued_accounts(): void
    {
        $this->environment('local', 'true');

        $response = $this->get('/login')->assertOk();

        $response->assertInertia(fn ($page) => $page
            ->component('Auth/Login')
            ->where('demo.password', DemoDataBuilder::DEMO_PASSWORD)
            ->has('demo.accounts', count(DemoAccountCatalog::loginShortcuts())));

        $emails = array_column($response->viewData('page')['props']['demo']['accounts'], 'email');
        $this->assertSame(array_column(DemoAccountCatalog::loginShortcuts(), 'email'), $emails);

        foreach ($emails as $email) {
            $this->assertStringEndsWith('@example.test', $email);
        }
    }

    /**
     * @return iterable<string, array{0: string, 1: ?string, 2: string, 3: string}>
     */
    public static function nonDemoEnvironments(): iterable
    {
        yield 'production (even inside DDEV)' => ['production', 'true', 'db', 'db'];
        yield 'staging (even inside DDEV)' => ['staging', 'true', 'db', 'db'];
        yield 'testing' => ['testing', 'true', 'db', 'db'];
        yield 'an unknown environment name' => ['qa-shared', 'true', 'db', 'db'];
        yield 'local but not inside DDEV' => ['local', null, 'db', 'db'];
        yield 'local, DDEV marker false' => ['local', 'false', 'db', 'db'];
        yield 'local DDEV but another database host' => ['local', 'true', '10.0.0.5', 'db'];
        yield 'local DDEV but another database name' => ['local', 'true', 'db', 'school_os'];
    }

    #[Test]
    #[DataProvider('nonDemoEnvironments')]
    public function the_panel_and_its_credentials_are_absent_everywhere_else(string $appEnv, ?string $isDdev, string $dbHost, string $dbName): void
    {
        $this->environment($appEnv, $isDdev, $dbHost, $dbName);

        $response = $this->get('/login')->assertOk();

        $response->assertInertia(fn ($page) => $page->component('Auth/Login')->where('demo', null));
        $this->assertStringNotContainsString(DemoDataBuilder::DEMO_PASSWORD, $response->getContent());
        $this->assertStringNotContainsString('school.admin@example.test', $response->getContent());
    }

    #[Test]
    public function the_panel_is_absent_until_the_demo_accounts_exist(): void
    {
        // E21.4 (F1): the runtime role can no longer delete a User; moving the demo
        // addresses aside inside the test transaction removes the accounts just as well.
        User::query()->whereIn('email', array_column(DemoAccountCatalog::loginShortcuts(), 'email'))->get()
            ->each(fn (User $user) => $user->forceFill(['email' => 'moved-'.$user->id.'@example.test'])->save());
        $this->environment('local', 'true');

        $this->get('/login')->assertOk()->assertInertia(fn ($page) => $page->where('demo', null));
    }

    #[Test]
    public function a_prefilled_demo_account_signs_in_through_the_normal_login_endpoint_only(): void
    {
        // Posted in the ordinary testing environment on purpose: the demo
        // panel changes nothing about POST /login, which authenticates a
        // demo account exactly like any other User (and a local-env POST
        // here would only trip CSRF, which the test harness skips solely
        // in `testing`).
        $this->post('/login', ['email' => 'principal@example.test', 'password' => DemoDataBuilder::DEMO_PASSWORD])
            ->assertRedirect('/app');
        $this->assertAuthenticatedAs(User::query()->where('email', 'principal@example.test')->firstOrFail());

        $this->post('/logout');
        $this->post('/login', ['email' => 'principal@example.test', 'password' => 'not-the-demo-password'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();

        // No alternative/auto-login route exists: the only POST route that
        // authenticates is the ordinary /login.
        $authRoutes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => str_contains($route->uri(), 'login'))
            ->map(fn ($route) => implode('|', $route->methods()).' '.$route->uri())
            ->values()
            ->all();
        $this->assertSame(['GET|HEAD login', 'POST login', 'GET|HEAD login/mfa', 'POST login/mfa'], $authRoutes);
    }

    #[Test]
    public function operations_desk_roles_use_only_existing_catalog_capabilities(): void
    {
        $existing = Capability::query()->pluck('key')->all();

        foreach (DemoAccountCatalog::OPERATIONS_DESK_ROLES as $key => $role) {
            $this->assertStringStartsWith('demo.', $key);
            $this->assertStringStartsWith('Demo: ', $role['name']);
            foreach ($role['capabilities'] as $capability) {
                $this->assertContains($capability, $existing, "{$key} uses unknown capability {$capability}");
            }
        }
    }
}

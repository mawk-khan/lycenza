<?php

namespace Tests\Feature\Auth;

use App\Models\PlatformAuditEvent;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\Demo\DemoAccountCatalog;
use Database\Seeders\Demo\DemoBuildResult;
use Database\Seeders\Demo\DemoDataBuilder;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Log out is available to every authenticated account, whatever its
 * School, role or capabilities: the Inertia default layout
 * (resources/js/Layouts/AccountLayout.vue) shows it whenever the shared
 * `auth.user` prop is present, and the web 403 page
 * (resources/views/errors/403.blade.php) carries its own form. Both use
 * the existing POST /logout (LoginController::destroy()) unchanged.
 * Proven against the real demo personas.
 */
class LogoutAccessTest extends TestCase
{
    private function build(): DemoBuildResult
    {
        return app(DemoDataBuilder::class)->build();
    }

    private function user(string $email): User
    {
        return User::query()->where('email', $email)->firstOrFail();
    }

    private function resetBetweenActors(): void
    {
        app(TenantContext::class)->clearAllTolerantly();
        $this->flushSession();
    }

    #[Test]
    public function the_logout_endpoint_is_the_existing_authenticated_post_route(): void
    {
        $route = Route::getRoutes()->getByName('logout');

        $this->assertNotNull($route);
        $this->assertSame('logout', $route->uri());
        $this->assertSame(['POST'], $route->methods());
        $this->assertContains('web', $route->gatherMiddleware());
        $this->assertContains('auth', $route->gatherMiddleware());

        $this->get('/logout')->assertStatus(405);
        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
    }

    #[Test]
    public function guests_get_no_signed_in_user_so_no_logout_control(): void
    {
        $this->get('/login')->assertOk()->assertInertia(fn ($page) => $page->where('auth.user', null));
    }

    #[Test]
    public function every_demo_persona_sees_its_account_and_can_log_out_without_a_selected_school(): void
    {
        $this->build();

        $emails = array_column(DemoAccountCatalog::loginShortcuts(), 'email');
        $this->assertCount(18, $emails);
        $this->assertContains('platform.admin@example.test', $emails);
        $this->assertContains('group.admin@example.test', $emails);

        foreach ($emails as $email) {
            $user = $this->user($email);

            // No School has been selected in this session.
            $this->actingAs($user)->get('/app')
                ->assertOk()
                ->assertInertia(fn ($page) => $page
                    ->where('activeSchool', null)
                    ->where('auth.user.email', $email));

            $this->post('/logout')->assertRedirect('/login');
            $this->assertGuest();
            $this->get('/app')->assertRedirect('/login');

            $this->assertSame(
                1,
                PlatformAuditEvent::query()->where('event_type', 'auth.logout')->where('actor_user_id', $user->id)->count(),
                "{$email} logout was not audited exactly once",
            );

            $this->resetBetweenActors();
        }
    }

    #[Test]
    public function logging_out_invalidates_the_session_and_rotates_the_csrf_token(): void
    {
        $this->build();

        $this->post('/login', ['email' => 'student@example.test', 'password' => DemoDataBuilder::DEMO_PASSWORD])
            ->assertRedirect('/app');
        $this->get('/app')->assertOk()->assertInertia(fn ($page) => $page->where('auth.user.email', 'student@example.test'));

        $tokenBefore = session()->token();
        $sessionBefore = session()->getId();

        $this->post('/logout')->assertRedirect('/login');

        $this->assertGuest();
        $this->assertNotSame($tokenBefore, session()->token());
        $this->assertNotSame($sessionBefore, session()->getId());
        $this->get('/app')->assertRedirect('/login');
        $this->get('/app/account/security')->assertRedirect('/login');

        // The ordinary login still works afterwards (switching persona);
        // it returns to the last protected URL requested, as before.
        $this->post('/login', ['email' => 'guardian01@example.test', 'password' => DemoDataBuilder::DEMO_PASSWORD])
            ->assertRedirect('/app/account/security');
        $this->assertAuthenticatedAs($this->user('guardian01@example.test'));
    }

    #[Test]
    public function a_selected_school_page_and_a_stale_school_selection_keep_the_account_bar(): void
    {
        $result = $this->build();

        // Selected School: the School Admin's dashboard still shares the user.
        $admin = $this->user('school.admin@example.test');
        $this->actingAs($admin)->post("/app/schools/{$result->school->id}/activate")->assertRedirect('/app');
        $this->actingAs($admin)->get('/app')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('activeSchool.id', $result->school->id)
                ->where('auth.user.email', 'school.admin@example.test'));
        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
        $this->resetBetweenActors();

        // Stale selection: a School the Teacher has no membership in is
        // ignored, the layout still renders, and logout still works.
        $teacher = $this->user('teacher@example.test');
        $this->actingAs($teacher)
            ->withSession(['active_school_id' => $result->secondSchool->id])
            ->get('/app')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('activeSchool', null)
                ->where('auth.user.email', 'teacher@example.test'));
        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
    }

    #[Test]
    public function the_403_page_offers_logout_to_a_signed_in_user(): void
    {
        $result = $this->build();
        $teacher = $this->user('teacher@example.test');

        $this->actingAs($teacher)->post("/app/schools/{$result->school->id}/activate")->assertRedirect('/app');

        $response = $this->actingAs($teacher)->get('/app/analytics/curriculum-coverage')->assertForbidden();
        $response->assertSee('action="'.route('logout').'"', false);
        $response->assertSee('name="_token"', false);
        $response->assertSee('Log out');
        $response->assertSee('teacher@example.test');

        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
    }

    #[Test]
    public function the_account_bar_is_the_default_layout_and_depends_only_on_authentication(): void
    {
        $app = (string) file_get_contents(resource_path('js/app.ts'));
        $this->assertStringContainsString("import AccountLayout from './Layouts/AccountLayout.vue';", $app);
        $this->assertStringContainsString('layout: () => AccountLayout,', $app);

        $layout = (string) file_get_contents(resource_path('js/Layouts/AccountLayout.vue'));
        $this->assertStringContainsString('v-if="user"', $layout);
        $this->assertStringContainsString('page.props.auth?.user', $layout);
        $this->assertStringContainsString('href="/logout"', $layout);
        $this->assertStringContainsString('method="post"', $layout);
        $this->assertStringContainsString('<slot />', $layout);

        // Not gated by a School, role or capability.
        foreach (['activeSchool', 'capabilit', 'role', 'school'] as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase($forbidden, preg_replace('/^\/\/.*$/m', '', $layout), "AccountLayout must not depend on [{$forbidden}]");
        }

        // No page overrides the default layout.
        $pages = 0;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('js/Pages'))) as $file) {
            if ($file->getExtension() === 'vue') {
                $pages++;
                $this->assertStringNotContainsString('layout', (string) file_get_contents($file->getPathname()), $file->getPathname());
            }
        }
        $this->assertGreaterThan(50, $pages);
    }
}

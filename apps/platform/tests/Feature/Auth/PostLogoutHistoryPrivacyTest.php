<?php

namespace Tests\Feature\Auth;

use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\PreventAuthenticatedPageCaching;
use App\Models\PlatformAuditEvent;
use App\Models\User;
use Database\Seeders\Demo\DemoBuildResult;
use Database\Seeders\Demo\DemoDataBuilder;
use Illuminate\Auth\SessionGuard;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Inertia\Support\SessionKey;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Tests\TestCase;

/**
 * After logout the browser must not be able to redraw a previously
 * signed-in page with Back. Three separate browser mechanisms are
 * covered, each by the narrowest server-side control:
 *
 * - Inertia history state: every page rendered for a signed-in user is
 *   sent with `encryptHistory` (HandleInertiaRequests), and the logout
 *   response sets Inertia's clear-history flag in the NEW session, so
 *   the /login page it redirects to carries `clearHistory` and the
 *   browser discards the history key.
 * - HTTP cache / back-forward cache: HTML and Inertia responses for a
 *   signed-in user are `Cache-Control: no-store, private`
 *   (PreventAuthenticatedPageCaching), so Back re-requests them and the
 *   server answers a guest with a redirect to /login. The three guest
 *   pages a sign-in happens on (whose document then holds signed-in
 *   state) use the existing `private-no-store` route middleware.
 *
 * Browser Back itself cannot be simulated here; it is verified with a
 * real Chromium run (docs/development/DDEV-DEMO-REVIEW.md).
 */
class PostLogoutHistoryPrivacyTest extends TestCase
{
    private function build(): DemoBuildResult
    {
        return app(DemoDataBuilder::class)->build();
    }

    private function user(string $email): User
    {
        return User::query()->where('email', $email)->firstOrFail();
    }

    private function inertiaHeaders(): array
    {
        return ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request())];
    }

    /** @return array<string, mixed> */
    private function page(TestResponse $response): array
    {
        return $response->viewData('page');
    }

    private function assertNoStore(TestResponse $response): void
    {
        $cacheControl = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertStringContainsString('private', $cacheControl);
        $this->assertStringNotContainsString('public', $cacheControl);
    }

    private function assertDefaultCaching(TestResponse $response): void
    {
        $this->assertStringNotContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    #[Test]
    public function signed_in_pages_encrypt_history_and_are_not_stored(): void
    {
        $result = $this->build();
        $admin = $this->user('school.admin@example.test');

        $this->actingAs($admin)->post("/app/schools/{$result->school->id}/activate")->assertRedirect('/app');

        // Full-page HTML load.
        $response = $this->actingAs($admin)->get('/app/students')->assertOk();
        $this->assertTrue($this->page($response)['encryptHistory'] ?? false);
        $this->assertArrayNotHasKey('clearHistory', $this->page($response));
        $this->assertNoStore($response);

        // Inertia (XHR) visit.
        $json = $this->actingAs($admin)->get('/app', $this->inertiaHeaders())->assertOk();
        $this->assertTrue($json->json('encryptHistory'));
        $this->assertSame('school.admin@example.test', $json->json('props.auth.user.email'));
        $this->assertNoStore($json);

        // No School selected (Platform Admin) -- still a signed-in page.
        $this->flushSession();
        $platform = $this->user('platform.admin@example.test');
        $response = $this->actingAs($platform)->get('/app')->assertOk();
        $this->assertTrue($this->page($response)['encryptHistory'] ?? false);
        $this->assertNoStore($response);
    }

    #[Test]
    public function guests_are_unaffected(): void
    {
        $response = $this->get('/login')->assertOk();
        $this->assertArrayNotHasKey('encryptHistory', $this->page($response));
        $this->assertArrayNotHasKey('clearHistory', $this->page($response));

        $this->assertDefaultCaching($this->get('/')->assertOk());
        $this->assertDefaultCaching($this->get('/app')->assertRedirect('/login'));
        $this->assertDefaultCaching($this->get('/definitely-not-a-route')->assertNotFound());
    }

    #[Test]
    public function guest_pages_that_become_signed_in_pages_are_not_stored(): void
    {
        // Signing in on these pages turns the same browser document into
        // a signed-in one (Inertia visit, no full reload), so the document
        // must not be kept for back/forward restore either.
        $this->assertNoStore($this->get('/login')->assertOk());

        foreach (['login', 'login.mfa', 'invitations.show'] as $name) {
            $this->assertContains('private-no-store', Route::getRoutes()->getByName($name)->gatherMiddleware(), $name);
        }
        foreach (['login.store', 'login.mfa.store', 'invitations.store', 'system.status'] as $name) {
            $this->assertNotContains('private-no-store', Route::getRoutes()->getByName($name)->gatherMiddleware(), $name);
        }
    }

    #[Test]
    public function logout_keeps_its_semantics_and_clears_inertia_history_on_the_login_page(): void
    {
        $this->build();

        $this->post('/login', ['email' => 'student@example.test', 'password' => DemoDataBuilder::DEMO_PASSWORD])
            ->assertRedirect('/app');
        $this->get('/app')->assertOk();
        $user = $this->user('student@example.test');

        $tokenBefore = session()->token();
        $sessionBefore = session()->getId();

        $this->post('/logout')->assertRedirect('/login');

        // Existing logout requirements, unchanged.
        $this->assertGuest();
        $this->assertNotSame($tokenBefore, session()->token());
        $this->assertNotSame($sessionBefore, session()->getId());
        $this->assertSame(1, PlatformAuditEvent::query()->where('event_type', 'auth.logout')->where('actor_user_id', $user->id)->count());
        // The only thing carried into the new, empty session is the flag.
        $this->assertTrue(session(SessionKey::CLEAR_HISTORY));
        $this->assertNull(session('login_web_'.sha1(SessionGuard::class)));

        // The login page the redirect lands on tells Inertia to clear
        // history -- once, and without encrypting a guest page.
        $login = $this->get('/login')->assertOk();
        $this->assertTrue($this->page($login)['clearHistory'] ?? false);
        $this->assertArrayNotHasKey('encryptHistory', $this->page($login));
        $this->assertArrayNotHasKey('clearHistory', $this->page($this->get('/login')->assertOk()));

        $this->get('/app')->assertRedirect('/login');
    }

    #[Test]
    public function an_inertia_logout_lands_on_a_login_page_that_clears_history(): void
    {
        $this->build();

        $this->actingAs($this->user('hr.payroll@example.test'));
        $this->post('/logout', [], $this->inertiaHeaders())->assertRedirect('/login');
        $this->assertGuest();

        $login = $this->get('/login', $this->inertiaHeaders())->assertOk();
        $this->assertSame('Auth/Login', $login->json('component'));
        $this->assertTrue($login->json('clearHistory'));
        $this->assertNull($login->json('encryptHistory'));
        $this->assertNull($login->json('props.auth.user'));
    }

    #[Test]
    public function logout_is_still_csrf_protected(): void
    {
        $this->build();

        // The framework's CSRF middleware skips itself under unit tests;
        // switch that bypass off so the real check runs.
        $this->app->bind(PreventRequestForgery::class, fn ($app) => new class($app, $app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });

        $this->actingAs($this->user('guardian01@example.test'))
            ->withSession(['_token' => 'known-token']);

        $this->post('/logout')->assertStatus(419);
        $this->assertAuthenticatedAs($this->user('guardian01@example.test'));
        $this->assertNull(session(SessionKey::CLEAR_HISTORY));

        $this->post('/logout', ['_token' => 'known-token'])->assertRedirect('/login');
        $this->assertGuest();
        $this->assertTrue(session(SessionKey::CLEAR_HISTORY));
    }

    #[Test]
    public function the_signed_in_403_page_is_not_stored_and_still_offers_logout(): void
    {
        $result = $this->build();
        $student = $this->user('student@example.test');

        $this->actingAs($student)->post("/app/schools/{$result->school->id}/activate")->assertRedirect('/app');

        $response = $this->actingAs($student)->get('/app/students')->assertForbidden();
        $response->assertSee('action="'.route('logout').'"', false);
        $response->assertSee('student@example.test');
        $this->assertNoStore($response);

        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
        $this->get('/app/students')->assertRedirect('/login');
    }

    #[Test]
    public function the_cache_policy_only_touches_signed_in_html_and_inertia_responses(): void
    {
        $this->build();
        $middleware = new PreventAuthenticatedPageCaching;
        $run = function (SymfonyResponse $response, bool $signedIn, array $headers = []) use ($middleware): string {
            Auth::logout();
            if ($signedIn) {
                Auth::login($this->user('teacher@example.test'));
            }
            $request = Request::create('/app/x', 'GET', server: collect($headers)->mapWithKeys(fn ($v, $k) => ['HTTP_'.strtoupper(str_replace('-', '_', $k)) => $v])->all());
            $request->setUserResolver(fn () => Auth::user());

            return (string) $middleware->handle($request, fn () => $response)->headers->get('Cache-Control');
        };

        $html = fn () => new Response('<html></html>', 200, ['Content-Type' => 'text/html; charset=UTF-8']);
        $inertiaJson = fn () => new Response('{}', 200, ['Content-Type' => 'application/json', 'X-Inertia' => 'true']);
        $pdf = fn () => new Response('%PDF', 200, ['Content-Type' => 'application/pdf']);
        $json = fn () => new Response('{}', 200, ['Content-Type' => 'application/json']);

        $this->assertStringContainsString('no-store', $run($html(), true));
        $this->assertStringContainsString('no-store', $run(new Response('<html></html>', 403, ['Content-Type' => 'text/html']), true));
        $this->assertStringContainsString('no-store', $run($inertiaJson(), true));

        // Downloads, plain JSON, redirects and every guest response keep
        // the framework default.
        $this->assertStringNotContainsString('no-store', $run($pdf(), true));
        $this->assertStringNotContainsString('no-store', $run($json(), true));
        $this->assertStringNotContainsString('no-store', $run(redirect('/app'), true));
        $this->assertStringNotContainsString('no-store', $run($html(), false));
        $this->assertStringNotContainsString('no-store', $run($inertiaJson(), false));
    }

    #[Test]
    public function the_policy_is_wired_into_the_web_group_only(): void
    {
        $groups = app('router')->getMiddlewareGroups();
        $this->assertContains(PreventAuthenticatedPageCaching::class, $groups['web']);
        $this->assertNotContains(PreventAuthenticatedPageCaching::class, $groups['api']);
    }
}

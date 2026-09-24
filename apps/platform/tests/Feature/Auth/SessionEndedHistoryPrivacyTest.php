<?php

namespace Tests\Feature\Auth;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\PlatformAuditEvent;
use App\Models\User;
use App\Support\Auth\SessionEndedResponder;
use Database\Seeders\Demo\DemoBuildResult;
use Database\Seeders\Demo\DemoDataBuilder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;
use Inertia\Support\SessionKey;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A signed-in page whose session ends WITHOUT an explicit logout (it
 * expired in the session store, or was signed out elsewhere). The next
 * request that page makes to a route requiring sign-in must hand the
 * browser a fresh guest /login document and clear Inertia's history key
 * -- the same end state as an explicit logout -- instead of swapping the
 * login page into the old signed-in document (SessionEndedResponder).
 *
 * The session is ended the way the store does it: the test session is
 * flushed and the guard's per-request user cache forgotten, with no call
 * to POST /logout. What the browser then does with the response (fresh
 * document, Back/Forward) is verified with a real Chromium run
 * (docs/development/DDEV-DEMO-REVIEW.md).
 */
class SessionEndedHistoryPrivacyTest extends TestCase
{
    private function build(): DemoBuildResult
    {
        return app(DemoDataBuilder::class)->build();
    }

    private function user(string $email): User
    {
        return User::query()->where('email', $email)->firstOrFail();
    }

    /** A real session-backed sign-in (not actingAs(), which bypasses the session). */
    private function signIn(string $email): User
    {
        $user = $this->user($email);
        $this->withSession([Auth::guard('web')->getName() => $user->getAuthIdentifier()]);

        return $user;
    }

    /** The session disappears from the store; no logout request is made. */
    private function endSessionElsewhere(): void
    {
        $this->flushSession();
        $this->app['auth']->forgetGuards();
    }

    /** @return array<string, string> */
    private function inertiaHeaders(): array
    {
        return ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request())];
    }

    /** @return array<string, mixed> */
    private function page(TestResponse $response): array
    {
        return $response->viewData('page');
    }

    private function enforceCsrf(): void
    {
        // The framework's CSRF middleware skips itself under unit tests;
        // switch that bypass off so the real check runs.
        $this->app->bind(PreventRequestForgery::class, fn ($app) => new class($app, $app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
    }

    private function assertSessionEndedHandoff(TestResponse $response, bool $notice): void
    {
        $this->assertGuest();
        $this->assertTrue(session(SessionKey::CLEAR_HISTORY));
        $this->assertSame($notice ?: null, session(SessionEndedResponder::FLASH_KEY));
    }

    private function assertHardNavigationToLogin(TestResponse $response): void
    {
        $response->assertStatus(409);
        $response->assertHeader('X-Inertia-Location', route('login'));
    }

    #[Test]
    public function the_signed_in_session_is_really_session_backed_before_it_ends(): void
    {
        $this->build();
        $this->signIn('school.admin@example.test');

        $this->get('/app')->assertOk();
        $this->assertAuthenticatedAs($this->user('school.admin@example.test'));

        $this->endSessionElsewhere();
        $this->get('/app')->assertRedirect('/login');
    }

    #[Test]
    public function an_html_request_after_the_session_ended_redirects_to_login_and_clears_history(): void
    {
        $this->build();
        $this->signIn('school.admin@example.test');
        $this->get('/app')->assertOk();

        $this->endSessionElsewhere();

        $response = $this->get('/app/analytics/curriculum-coverage');

        // Same status and target as before; the intended URL is kept.
        $response->assertRedirect('/login');
        $this->assertSame(url('/app/analytics/curriculum-coverage'), session('url.intended'));
        // A plain GET may be a bookmark: no "session ended" notice.
        $this->assertSessionEndedHandoff($response, notice: false);

        $login = $this->get('/login')->assertOk();
        $this->assertTrue($this->page($login)['clearHistory'] ?? false);
        $this->assertNull($this->page($login)['props']['auth']['user']);
        $this->assertFalse($this->page($login)['props']['sessionEnded']);
    }

    #[Test]
    public function an_inertia_visit_after_the_session_ended_is_a_hard_navigation_to_a_fresh_login_document(): void
    {
        $this->build();
        $this->signIn('school.admin@example.test');
        $this->get('/app', $this->inertiaHeaders())->assertOk();

        $this->endSessionElsewhere();

        $response = $this->get('/app/analytics/curriculum-coverage', $this->inertiaHeaders());

        $this->assertHardNavigationToLogin($response);
        $this->assertSame('', $response->getContent());
        $this->assertSessionEndedHandoff($response, notice: true);

        // The browser's top-level load of /login: guest page, history
        // cleared, one-time notice.
        $login = $this->get('/login')->assertOk();
        $this->assertSame('Auth/Login', $this->page($login)['component']);
        $this->assertTrue($this->page($login)['clearHistory'] ?? false);
        $this->assertNull($this->page($login)['props']['auth']['user']);
        $this->assertTrue($this->page($login)['props']['sessionEnded']);
        $this->assertStringContainsString('no-store', (string) $login->headers->get('Cache-Control'));

        $again = $this->get('/login')->assertOk();
        $this->assertFalse($this->page($again)['props']['sessionEnded']);
        $this->assertArrayNotHasKey('clearHistory', $this->page($again));
    }

    #[Test]
    public function an_inertia_mutation_after_the_session_ended_is_a_hard_navigation_and_changes_nothing(): void
    {
        $result = $this->build();
        $this->signIn('multi.school@example.test');
        $this->get('/app', $this->inertiaHeaders())->assertOk();

        $this->endSessionElsewhere();

        $response = $this->post("/app/schools/{$result->secondSchool->id}/activate", [], $this->inertiaHeaders());

        $this->assertHardNavigationToLogin($response);
        $this->assertSessionEndedHandoff($response, notice: true);
        $this->assertNull(session('active_school_id'));
    }

    #[Test]
    public function a_csrf_failure_caused_by_the_ended_session_on_a_protected_route_goes_to_login(): void
    {
        $this->build();
        $this->enforceCsrf();
        $this->signIn('student@example.test');
        $this->withSession(['_token' => 'known-token']);
        $this->get('/app')->assertOk();

        $this->endSessionElsewhere();

        // An Inertia mutation without a valid token (the token lived in
        // the ended session): no 419 error dialog over the old page.
        $inertia = $this->post('/logout', [], $this->inertiaHeaders());
        $this->assertHardNavigationToLogin($inertia);
        $this->assertSessionEndedHandoff($inertia, notice: true);

        // The Blade 403 page's plain form Log out, after the session ended.
        $this->flushSession();
        $form = $this->post('/logout');
        $form->assertRedirect('/login');
        $this->assertSessionEndedHandoff($form, notice: true);

        // Nothing was logged out, so no logout is audited.
        $this->assertSame(0, PlatformAuditEvent::query()->where('event_type', 'auth.logout')->count());
    }

    #[Test]
    public function a_csrf_failure_for_a_signed_in_user_keeps_its_419(): void
    {
        $this->build();
        $this->enforceCsrf();
        $this->signIn('student@example.test');
        $this->withSession(['_token' => 'known-token']);

        $this->post('/logout', [], $this->inertiaHeaders())->assertStatus(419);
        $this->post('/logout')->assertStatus(419);

        $this->assertAuthenticatedAs($this->user('student@example.test'));
        $this->assertNull(session(SessionKey::CLEAR_HISTORY));
        $this->assertNull(session(SessionEndedResponder::FLASH_KEY));
    }

    #[Test]
    public function a_csrf_failure_on_a_guest_route_keeps_its_419(): void
    {
        $this->build();
        $this->enforceCsrf();
        $this->withSession(['_token' => 'known-token']);

        $this->post('/login', ['email' => 'student@example.test', 'password' => DemoDataBuilder::DEMO_PASSWORD], $this->inertiaHeaders())
            ->assertStatus(419);
        $this->post('/login/mfa', ['code' => '000000'])->assertStatus(419);

        $this->assertGuest();
        $this->assertNull(session(SessionKey::CLEAR_HISTORY));
        $this->assertNull(session(SessionEndedResponder::FLASH_KEY));
    }

    #[Test]
    public function a_genuine_403_stays_a_403_and_is_not_treated_as_a_session_end(): void
    {
        $result = $this->build();
        $this->signIn('student@example.test');
        $this->post("/app/schools/{$result->school->id}/activate")->assertRedirect('/app');

        $this->get('/app/students')->assertForbidden();
        $this->get('/app/students', $this->inertiaHeaders())->assertForbidden();

        $this->assertAuthenticatedAs($this->user('student@example.test'));
        $this->assertNull(session(SessionKey::CLEAR_HISTORY));
        $this->assertNull(session(SessionEndedResponder::FLASH_KEY));
    }

    #[Test]
    public function json_and_api_requests_keep_their_401(): void
    {
        $this->build();
        $this->signIn('school.admin@example.test');
        $this->get('/app')->assertOk();

        $this->endSessionElsewhere();

        $this->getJson('/app')->assertStatus(401);
        $this->getJson('/api/v1/education-boards')->assertStatus(401)->assertJsonPath('error.status', 401);
        $this->assertNull(session(SessionKey::CLEAR_HISTORY));
    }

    #[Test]
    public function a_guest_who_never_signed_in_is_redirected_to_login_as_before(): void
    {
        $this->build();

        // Same status and target. The clear-history flag is set for any
        // unauthenticated web request (an ended session and no session
        // look the same to the server; a real guest has no key to clear),
        // but a plain GET gets no "session ended" notice.
        $this->get('/app')->assertRedirect('/login');
        $this->assertTrue(session(SessionKey::CLEAR_HISTORY));
        $this->assertNull(session(SessionEndedResponder::FLASH_KEY));

        $login = $this->get('/login')->assertOk();
        $this->assertFalse($this->page($login)['props']['sessionEnded']);
        $this->get('/login/mfa')->assertRedirect('/login');
    }

    #[Test]
    public function recovery_works_without_a_selected_school_and_for_the_platform_admin(): void
    {
        $this->build();

        foreach (['school.admin@example.test', 'platform.admin@example.test'] as $email) {
            // Signed in, no School selected.
            $this->signIn($email);
            $this->get('/app', $this->inertiaHeaders())->assertOk();
            $this->assertNull(session('active_school_id'));

            $this->endSessionElsewhere();

            $response = $this->get('/app', $this->inertiaHeaders());
            $this->assertHardNavigationToLogin($response);
            $this->assertSessionEndedHandoff($response, notice: true);

            // Signing in again resumes normally.
            $this->post('/login', ['email' => $email, 'password' => DemoDataBuilder::DEMO_PASSWORD])->assertRedirect('/app');
            $this->assertAuthenticatedAs($this->user($email));
            $this->get('/app')->assertOk();

            $this->post('/logout')->assertRedirect('/login');
            $this->flushSession();
            $this->app['auth']->forgetGuards();
        }
    }
}

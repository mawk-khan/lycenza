<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Support\Auth\Exceptions\FreshPasswordConfirmationRequiredException;
use App\Support\Auth\PasswordConfirmationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Unit-level tests against a properly session-bound Request (built via
 * requestWithSession(), not the bare `request()` helper -- outside a
 * real dispatched HTTP request, that helper's Request has no session
 * store attached, since StartSession middleware never ran for it).
 * MfaAuditPayloadSecurityTest/MfaTwoStageLoginTest exercise this same
 * service end-to-end through real HTTP requests.
 */
class PasswordConfirmationServiceTest extends TestCase
{
    private function requestWithSession(): Request
    {
        $request = Request::create('/');
        $request->setLaravelSession($this->app['session.store']);

        return $request;
    }

    #[Test]
    public function confirm_returns_false_for_the_wrong_password(): void
    {
        $user = User::factory()->create(['password' => Hash::make('correct-password')]);

        $this->assertFalse(app(PasswordConfirmationService::class)->confirm($this->requestWithSession(), $user, 'wrong-password'));
    }

    #[Test]
    public function confirm_returns_true_and_records_a_grant_for_the_correct_password(): void
    {
        $user = User::factory()->create(['password' => Hash::make('correct-password')]);
        $request = $this->requestWithSession();

        $this->assertTrue(app(PasswordConfirmationService::class)->confirm($request, $user, 'correct-password'));
        $this->assertNotNull($request->session()->get('password_confirmed_at'));
    }

    #[Test]
    public function require_throws_when_no_confirmation_has_been_recorded(): void
    {
        $this->expectException(FreshPasswordConfirmationRequiredException::class);

        app(PasswordConfirmationService::class)->require($this->requestWithSession());
    }

    #[Test]
    public function require_passes_within_the_configured_window(): void
    {
        $request = $this->requestWithSession();
        $request->session()->put('password_confirmed_at', now()->toIso8601String());

        app(PasswordConfirmationService::class)->require($request);

        $this->assertTrue(true); // no exception thrown
    }

    #[Test]
    public function require_throws_once_the_window_has_expired(): void
    {
        $request = $this->requestWithSession();
        $request->session()->put(
            'password_confirmed_at',
            now()->subMinutes((int) config('mfa.password_confirmation_window_minutes') + 1)->toIso8601String(),
        );

        $this->expectException(FreshPasswordConfirmationRequiredException::class);

        app(PasswordConfirmationService::class)->require($request);
    }
}

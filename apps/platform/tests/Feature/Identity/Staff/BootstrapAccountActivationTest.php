<?php

namespace Tests\Feature\Identity\Staff;

use App\Domain\Identity\Application\Staff\AccountActivationService;
use App\Domain\Identity\Application\Staff\BootstrapAccountProvisioningService;
use App\Domain\Identity\Infrastructure\AccountActivationCredential;
use App\Domain\Identity\Infrastructure\AccountRecoveryRequest;
use App\Http\Controllers\Auth\AccountActivationController;
use App\Models\PlatformAuditEvent;
use App\Models\SchoolMembership;
use App\Models\User;
use App\Support\Domains\SchoolHostSurface;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Concerns\FakesEmail;
use Tests\Feature\Auth\Mfa\Concerns\CreatesMfaFixtures;
use Tests\TestCase;

/**
 * Phase 0O.12B (ADR 0059 sections 4, 5, 10, 13): flow A -- the operator
 * console creates a credential-less bootstrap account (no membership, role,
 * Employee or grant) and shows its one-time activation link once; the
 * person sets their first password on the platform host. A credential-less
 * account cannot sign in, is not recovery-eligible and is refused by the
 * operator password reset.
 */
class BootstrapAccountActivationTest extends TestCase
{
    use CreatesMfaFixtures, CreatesTenancyFixtures, FakesEmail;

    private const PASSWORD = 'first-admin-password-77';

    /** @return array{0: User, 1: string, 2: string, 3: string} [user, link, selector, secret] */
    private function provisioned(string $email = 'first.admin@example.test'): array
    {
        $issued = app(BootstrapAccountProvisioningService::class)->provision($email, 'First Admin', null);
        $this->assertSame(1, preg_match('#^http://localhost:8000/account-activation/([A-Za-z0-9_-]{22})\#([A-Za-z0-9_-]{43})$#', $issued->link, $m));

        return [$issued->user, $issued->link, $m[1], $m[2]];
    }

    private function activate(string $selector, string $secret, string $password = self::PASSWORD)
    {
        return $this->post("http://localhost/account-activation/{$selector}", [
            'secret' => $secret, 'password' => $password, 'password_confirmation' => $password,
        ]);
    }

    #[Test]
    public function the_console_creates_a_credentialless_account_and_shows_the_link_once(): void
    {
        $school = $this->createSchool(['status' => 'provisioning']);
        $email = 'first.admin@example.test';

        $this->artisan('platform:provision-school-admin-account', ['email' => 'First.Admin@Example.test', '--name' => 'First Admin', '--school' => $school->slug])
            ->expectsQuestion('Type the account\'s email address exactly to confirm', $email)
            ->expectsOutputToContain('ONE-TIME ACTIVATION LINK')
            ->expectsOutputToContain('http://localhost:8000/account-activation/')
            ->assertSuccessful();

        $user = User::query()->where('email', $email)->sole();
        $this->assertNull($user->getAttributes()['password'], 'No placeholder password: NULL.');
        $this->assertFalse($user->hasLocalCredential());
        $this->assertSame(0, SchoolMembership::query()->where('user_id', $user->id)->count(), 'No membership.');
        $this->assertSame(0, DB::table('platform_role_assignments')->where('user_id', $user->id)->count());
        $this->assertSame(0, DB::table('group_role_assignments')->where('user_id', $user->id)->count());
        $credential = AccountActivationCredential::query()->where('user_id', $user->id)->sole();
        $this->assertSame('console', $credential->created_via);
        $this->assertEqualsWithDelta(now()->addHours(24)->timestamp, $credential->expires_at->timestamp, 5);

        $event = PlatformAuditEvent::query()->where('event_type', BootstrapAccountProvisioningService::PROVISIONED)->where('subject_id', $user->id)->sole();
        $this->assertNull($event->actor_user_id);
        $this->assertEquals(['user_id' => $user->id, 'activation_credential_id' => $credential->id, 'method' => 'console', 'school_id' => $school->id], $event->metadata);
        $this->assertStringNotContainsString('example.test', json_encode($event->metadata));
    }

    #[Test]
    public function the_console_refuses_non_interactive_runs_mismatched_confirmation_and_ineligible_targets(): void
    {
        $this->assertSame(1, Artisan::call('platform:provision-school-admin-account', ['email' => 'a@example.test', '--name' => 'A', '--no-interaction' => true]));
        $this->assertStringContainsString('interactive only', Artisan::output());

        $this->artisan('platform:provision-school-admin-account', ['email' => 'a@example.test', '--name' => 'A'])
            ->expectsQuestion('Type the account\'s email address exactly to confirm', 'someone.else@example.test')
            ->expectsOutputToContain('Nothing changed')->assertFailed();
        $this->assertSame(0, User::query()->where('email', 'a@example.test')->count());

        $withPassword = $this->createUser(['email' => 'has.password@example.test']);
        $this->artisan('platform:provision-school-admin-account', ['email' => $withPassword->email])
            ->expectsQuestion('Type the account\'s email address exactly to confirm', $withPassword->email)
            ->expectsOutputToContain('already has a password')->assertFailed();

        $active = $this->createSchool();
        $this->artisan('platform:provision-school-admin-account', ['email' => 'b@example.test', '--name' => 'B', '--school' => $active->id])
            ->expectsQuestion('Type the account\'s email address exactly to confirm', 'b@example.test')
            ->expectsOutputToContain('not provisioning')->assertFailed();

        $this->artisan('platform:provision-school-admin-account', ['email' => 'c@example.test', '--name' => 'C', '--hours' => '73'])
            ->expectsQuestion('Type the account\'s email address exactly to confirm', 'c@example.test')
            ->expectsOutputToContain('between 1 and 72')->assertFailed();
    }

    #[Test]
    public function activation_sets_the_first_password_once_and_never_logs_in(): void
    {
        [$user, , $selector, $secret] = $this->provisioned();

        $this->get("http://localhost/account-activation/{$selector}")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Auth/AccountActivation/Activate')->where('selector', $selector)->missing('secret'));
        $this->assertTrue(AccountActivationCredential::query()->where('selector', $selector)->sole()->isOpen(), 'A GET never consumes.');

        $this->activate($selector, $secret, 'short')->assertSessionHasErrors('password');
        $this->assertTrue(AccountActivationCredential::query()->where('selector', $selector)->sole()->isOpen(), 'A rejected password consumes nothing.');

        $this->activate($selector, $secret)->assertRedirect('http://localhost:8000/login');
        $this->assertGuest();

        $fresh = $user->fresh();
        $this->assertTrue(Hash::check(self::PASSWORD, $fresh->getAuthPassword()));
        $this->assertSame(2, $fresh->credential_version);
        $this->assertNotNull(AccountActivationCredential::query()->where('selector', $selector)->sole()->consumed_at);
        $event = PlatformAuditEvent::query()->where('event_type', AccountActivationService::ACTIVATED)->where('subject_id', $user->id)->sole();
        $this->assertSame($user->id, $event->actor_user_id);
        $this->assertStringNotContainsString($secret, json_encode($event->metadata));

        // Single use; an established credential is never reset by activation.
        $this->activate($selector, $secret, 'another-password-88')->assertSessionHasErrors(['secret' => AccountActivationController::INVALID_LINK]);
        $this->assertTrue(Hash::check(self::PASSWORD, $user->fresh()->getAuthPassword()));

        $this->post('http://localhost/login', ['email' => $user->email, 'password' => self::PASSWORD])->assertRedirect();
        $this->assertAuthenticatedAs($user);
    }

    #[Test]
    public function every_activation_failure_is_the_same_invalid_answer(): void
    {
        [$user, , $selector, $secret] = $this->provisioned();
        $invalid = ['secret' => AccountActivationController::INVALID_LINK];

        $this->activate($selector, str_repeat('A', 43))->assertSessionHasErrors($invalid);
        $this->activate(str_repeat('B', 22), $secret)->assertSessionHasErrors($invalid);

        // A re-issue supersedes the old link.
        $reissued = app(BootstrapAccountProvisioningService::class)->reissue($user, null);
        $this->assertTrue($reissued->reissued);
        $this->activate($selector, $secret)->assertSessionHasErrors($invalid);
        $this->assertSame('superseded', AccountActivationCredential::query()->where('selector', $selector)->sole()->invalidation_reason);

        // Expired.
        preg_match('#/account-activation/([A-Za-z0-9_-]{22})\#([A-Za-z0-9_-]{43})#', $reissued->link, $m);
        $this->travel(25)->hours();
        $this->activate($m[1], $m[2])->assertSessionHasErrors($invalid);

        // Disabled.
        $this->travelBack();
        $third = app(BootstrapAccountProvisioningService::class)->reissue($user, null);
        preg_match('#/account-activation/([A-Za-z0-9_-]{22})\#([A-Za-z0-9_-]{43})#', $third->link, $n);
        $user->forceFill(['is_disabled' => true, 'disabled_at' => now()])->save();
        $this->activate($n[1], $n[2])->assertSessionHasErrors($invalid);
        $this->assertSame('account_ineligible', AccountActivationCredential::query()->where('selector', $n[1])->sole()->invalidation_reason, 'Disabling ends open credentials in the database.');
        $this->assertFalse($user->fresh()->hasLocalCredential());
    }

    #[Test]
    public function a_credentialless_account_cannot_sign_in_recover_or_be_reset_by_the_operator(): void
    {
        config(['account_recovery.enabled' => true]);
        $this->fakeEmail();
        [$user] = $this->provisioned();

        $this->post('http://localhost/login', ['email' => $user->email, 'password' => ''])->assertSessionHasErrors();
        $this->post('http://localhost/login', ['email' => $user->email, 'password' => 'anything-at-all'])->assertSessionHasErrors();
        $this->assertFalse(Auth::check());

        $this->post('http://localhost/account-recovery', ['email' => $user->email])->assertRedirect('/account-recovery');
        $this->assertSame(0, AccountRecoveryRequest::query()->where('user_id', $user->id)->count(), 'Recovery never acts as activation.');
        $this->assertNoEmailAccepted();

        $this->artisan('platform:user-password-reset', ['user' => $user->email])
            ->expectsOutputToContain('has not been activated yet')->assertFailed();
        $this->assertFalse($user->fresh()->hasLocalCredential());
    }

    #[Test]
    public function the_activation_page_is_served_on_the_platform_host_only(): void
    {
        $this->get('http://localhost/account-activation/'.str_repeat('a', 22))->assertOk();
        $this->assertStringNotContainsString('account-activation', implode(',', SchoolHostSurface::PREFIXES));
    }
}

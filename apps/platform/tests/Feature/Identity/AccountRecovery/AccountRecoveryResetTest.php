<?php

namespace Tests\Feature\Identity\AccountRecovery;

use App\Domain\Identity\Infrastructure\AccountRecoveryRequest;
use App\Http\Controllers\Auth\AccountRecoveryController;
use App\Models\ApiClient;
use App\Models\EmailMessage;
use App\Models\PlatformAuditEvent;
use App\Models\SchoolElevation;
use App\Models\User;
use App\Models\UserMfaFactor;
use App\Support\Email\EmailPurpose;
use App\Support\Email\PlatformEmailScope;
use App\Support\Email\Providers\OutboundEmail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CapturesStructuredLogs;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Concerns\FakesEmail;
use Tests\Feature\Auth\Mfa\Concerns\CreatesMfaFixtures;
use Tests\TestCase;

/**
 * Phase 0O.10A (ADR 0056 sections 8-12): the reset. GET never consumes; the
 * CSRF-protected POST verifies the fragment secret, applies the one password
 * policy and -- atomically -- changes the password, ends every session,
 * token and elevation, keeps MFA, and follows up with a security notice.
 * Every credential failure is the same "invalid or expired".
 */
class AccountRecoveryResetTest extends TestCase
{
    use CapturesStructuredLogs, CreatesMfaFixtures, CreatesTenancyFixtures, FakesEmail;

    private const OLD = 'old-password-123';

    private const NEW = 'brand-new-password-456';

    protected function setUp(): void
    {
        parent::setUp();
        config(['account_recovery.enabled' => true]);
        $this->fakeEmail();
    }

    private function person(string $email = 'person@example.test'): User
    {
        return $this->createUser(['email' => $email, 'password' => Hash::make(self::OLD)]);
    }

    /** @return array{0: string, 1: string} [selector, secret] from the last recovery email */
    private function issueLink(string $email = 'person@example.test'): array
    {
        $this->flushSession();
        $this->post('/account-recovery', ['email' => $email])->assertRedirect('/account-recovery');
        $mail = $this->lastAcceptedEmail();
        $this->assertSame(EmailPurpose::AccountRecovery, $mail->purpose);

        return $this->linkIn($mail);
    }

    /** @return array{0: string, 1: string} */
    private function linkIn(OutboundEmail $mail): array
    {
        $this->assertSame(1, preg_match('#http://localhost:8000/account-recovery/([A-Za-z0-9_-]{22})\#([A-Za-z0-9_-]{43})#', $mail->text, $m), 'the link carries the secret in its fragment');

        return [$m[1], $m[2]];
    }

    private function reset(string $selector, string $secret, string $password = self::NEW, ?string $confirmation = null): TestResponse
    {
        return $this->from("/account-recovery/{$selector}")->post("/account-recovery/{$selector}", [
            'secret' => $secret, 'password' => $password, 'password_confirmation' => $confirmation ?? $password,
        ]);
    }

    private function assertInvalid(TestResponse $response): void
    {
        $response->assertSessionHasErrors(['secret' => AccountRecoveryController::INVALID_LINK]);
    }

    #[Test]
    public function the_email_link_resets_the_password_once_and_sends_a_security_notice(): void
    {
        $user = $this->person();
        [$selector, $secret] = $this->issueLink();

        // Only hashes are stored.
        $request = AccountRecoveryRequest::query()->firstOrFail();
        $this->assertSame(hash('sha256', $secret), $request->secret_hash);
        $this->assertStringNotContainsString($secret, json_encode(AccountRecoveryRequest::query()->get()->toArray()));
        $this->assertSame(30, (int) round($request->created_at->diffInMinutes($request->expires_at)));

        $this->reset($selector, $secret)
            ->assertRedirect('http://localhost:8000/login')
            ->assertSessionHas('status_message');

        $this->assertGuest();
        $fresh = $user->fresh();
        $this->assertTrue(Hash::check(self::NEW, $fresh->password));
        $this->assertNotNull($request->fresh()->consumed_at);
        $this->assertSame(1, PlatformAuditEvent::query()->where('event_type', 'auth.password_recovered')->where('actor_user_id', $user->id)->count());

        // The notice follows, identity-level, and carries no link or secret.
        $notice = $this->lastAcceptedEmail();
        $this->assertSame(EmailPurpose::SecurityNotice, $notice->purpose);
        $this->assertSame('person@example.test', $notice->to);
        $this->assertStringNotContainsString('/account-recovery/', $notice->text);

        // Single use.
        $this->assertInvalid($this->reset($selector, $secret, 'yet-another-password-789'));
        $this->assertTrue(Hash::check(self::NEW, $user->fresh()->password));

        // The old password no longer signs in; the new one does.
        $this->post('/login', ['email' => 'person@example.test', 'password' => self::OLD])->assertSessionHasErrors('email');
        $this->post('/login', ['email' => 'person@example.test', 'password' => self::NEW])->assertRedirect('/app');
    }

    #[Test]
    public function opening_the_link_consumes_nothing_and_never_sees_the_secret(): void
    {
        $this->person();
        [$selector] = $this->issueLink();

        foreach (range(1, 3) as $scan) {
            $this->get("/account-recovery/{$selector}")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Auth/AccountRecovery/Reset')
                ->where('selector', $selector)
                ->where('invalidMessage', AccountRecoveryController::INVALID_LINK)
                ->missing('secret'));
        }

        $this->assertTrue(AccountRecoveryRequest::query()->firstOrFail()->isOpen(), 'a scanner or preview never consumes');

        // A malformed selector is simply not a route.
        $this->get('/account-recovery/short')->assertNotFound();
    }

    #[Test]
    public function every_credential_failure_is_the_same_invalid_or_expired(): void
    {
        $this->person();
        [$selector, $secret] = $this->issueLink();
        $unknownSelector = str_repeat('Q', 22);

        $this->assertInvalid($this->reset($selector, str_repeat('A', 43)));             // wrong secret
        $this->assertInvalid($this->reset($selector, 'short'));                         // malformed secret
        $this->assertInvalid($this->reset($unknownSelector, $secret));                  // unknown selector
        $this->assertInvalid($this->reset($selector, substr($secret, 0, 42).'!'));      // tampered

        $this->travel(31)->minutes();
        $this->assertInvalid($this->reset($selector, $secret));                         // expired
        $this->assertNull(AccountRecoveryRequest::query()->firstOrFail()->consumed_at);
    }

    #[Test]
    public function the_password_policy_rejects_without_consuming_the_link(): void
    {
        $user = $this->person();
        [$selector, $secret] = $this->issueLink();

        $this->reset($selector, $secret, 'short')->assertSessionHasErrors('password');
        $this->reset($selector, $secret, self::NEW, 'a-different-confirmation')->assertSessionHasErrors('password');

        $this->assertTrue(Hash::check(self::OLD, $user->fresh()->password));
        $this->assertTrue(AccountRecoveryRequest::query()->firstOrFail()->isOpen());

        $this->reset($selector, $secret)->assertRedirect('http://localhost:8000/login');
    }

    #[Test]
    public function a_new_request_never_invalidates_an_older_one_and_a_reset_supersedes_them_all(): void
    {
        $this->person();
        [$firstSelector, $firstSecret] = $this->issueLink();
        [$secondSelector, $secondSecret] = $this->issueLink();
        [$thirdSelector, $thirdSecret] = $this->issueLink();

        $this->assertSame(3, AccountRecoveryRequest::query()->whereNull('invalidated_at')->count());

        // A fourth open credential is never issued (the cap), silently.
        $this->flushSession();
        $this->post('/account-recovery', ['email' => 'person@example.test'])->assertRedirect('/account-recovery');
        $this->assertSame(3, AccountRecoveryRequest::query()->count());

        // The OLDEST still works.
        $this->reset($firstSelector, $firstSecret)->assertRedirect('http://localhost:8000/login');

        $this->assertSame(['superseded_by_reset'], AccountRecoveryRequest::query()->whereNotNull('invalidated_at')->distinct()->pluck('invalidation_reason')->all());
        $this->assertInvalid($this->reset($secondSelector, $secondSecret));
        $this->assertInvalid($this->reset($thirdSelector, $thirdSecret));
    }

    #[Test]
    public function a_reset_ends_sessions_tokens_remember_me_and_elevation_but_keeps_mfa(): void
    {
        $user = $this->person();
        $factor = $this->enrollActiveMfaFactor($user);
        $user->createToken('phone');
        $user->createToken('tablet');
        $rememberBefore = $user->fresh()->remember_token;

        // An elevation of this (non-root) platform auditor.
        $this->assignPlatformRole($user, 'platform_auditor');
        $school = $this->createSchool();
        $elevation = SchoolElevation::query()->create([
            'actor_user_id' => $user->id, 'school_id' => $school->id, 'authority_type' => 'platform',
            'reason_code' => 'operational_support', 'status' => 'active', 'started_at' => now(), 'expires_at' => now()->addMinutes(30),
        ]);

        // A partner client the User created belongs to the School, not the User.
        $client = ApiClient::query()->create(['school_id' => $school->id, 'name' => 'Sync', 'scopes' => ['academic_structure.read'], 'created_by_user_id' => $user->id]);

        $versionBefore = (int) DB::table('users')->where('id', $user->id)->value('credential_version');
        [$selector, $secret] = $this->issueLink();
        $this->reset($selector, $secret)->assertRedirect('http://localhost:8000/login');

        $fresh = $user->fresh();
        $this->assertSame($versionBefore + 1, $fresh->credential_version);
        $this->assertNotSame($rememberBefore, $fresh->remember_token);
        $this->assertSame(0, $fresh->tokens()->count(), 'human personal access tokens revoked');
        $this->assertNotNull(ApiClient::query()->find($client->id), 'partner clients untouched');
        $elevation->refresh();
        $this->assertSame(['terminated', 'credential_reset'], [$elevation->status, $elevation->end_reason]);

        // MFA is preserved and still required.
        $this->assertSame('active', UserMfaFactor::query()->findOrFail($factor->id)->status);
        $this->post('/login', ['email' => 'person@example.test', 'password' => self::NEW])->assertRedirect('/login/mfa');
        $this->assertGuest();

        $audit = PlatformAuditEvent::query()->where('event_type', 'auth.password_recovered')->where('actor_user_id', $user->id)->firstOrFail();
        $this->assertSame(2, $audit->metadata['revoked_personal_access_tokens']);
        $this->assertTrue($audit->metadata['ended_elevation']);
    }

    #[Test]
    public function a_change_of_email_or_eligibility_after_issuance_voids_the_link(): void
    {
        $user = $this->person();
        [$selector, $secret] = $this->issueLink();
        $user->forceFill(['email' => 'renamed@example.test'])->save();
        $this->assertInvalid($this->reset($selector, $secret));
        $this->assertSame('email_changed', AccountRecoveryRequest::query()->firstOrFail()->invalidation_reason);

        $other = $this->person('other@example.test');
        [$selector, $secret] = $this->issueLink('other@example.test');
        $other->forceFill(['is_disabled' => true, 'disabled_at' => now()])->save();
        $this->assertInvalid($this->reset($selector, $secret));
        $this->assertSame('account_ineligible', AccountRecoveryRequest::query()->where('selector', $selector)->value('invalidation_reason'));
        $this->assertTrue(Hash::check(self::OLD, $other->fresh()->password));
    }

    #[Test]
    public function the_reset_submissions_are_throttled_per_selector(): void
    {
        $this->person();
        [$selector, $secret] = $this->issueLink();

        foreach (range(1, 5) as $i) {
            $this->withServerVariables(['REMOTE_ADDR' => "192.0.2.{$i}"]);
            $this->assertInvalid($this->reset($selector, str_repeat('B', 43)));
        }

        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.99']);
        $this->reset($selector, $secret)->assertStatus(429);
    }

    #[Test]
    public function nothing_sensitive_reaches_logs_audit_or_the_email_row(): void
    {
        $this->captureLogs();
        $this->person();
        [$selector, $secret] = $this->issueLink();
        $this->reset($selector, $secret);

        $output = $this->capturedOutput();
        foreach ([$secret, $selector, self::NEW, 'person@example.test'] as $canary) {
            $this->assertStringNotContainsString($canary, $output);
        }
        $audit = json_encode(PlatformAuditEvent::query()->get()->toArray());
        foreach ([$secret, $selector, self::NEW] as $canary) {
            $this->assertStringNotContainsString($canary, (string) $audit);
        }

        // The sealed content of the recovery email is purged once it left.
        $row = app(PlatformEmailScope::class)->run(fn () => EmailMessage::query()->where('purpose', 'account_recovery')->firstOrFail());
        $this->assertNull($row->school_id);
        $this->assertNull($row->sealed_content);
    }
}

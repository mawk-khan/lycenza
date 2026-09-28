<?php

namespace Tests\Feature\Identity\AccountRecovery;

use App\Domain\Identity\Application\AccountRecovery\RecoveryCredential;
use App\Domain\Identity\Infrastructure\AccountRecoveryRequest;
use App\Http\Controllers\Auth\AccountRecoveryController;
use App\Models\EmailMessage;
use App\Models\PlatformAuditEvent;
use App\Models\User;
use App\Support\Auth\IdentityFingerprint;
use App\Support\Configuration\ProductionConfigurationGuard;
use App\Support\Email\EmailPurpose;
use App\Support\Email\OutboundEmailGateway;
use App\Support\Email\PlatformEmailScope;
use App\Support\Observability\LogSanitizer;
use App\Support\Observability\Metrics\MetricsExporter;
use App\Support\Observability\OperationalStatus;
use App\Support\Observability\OperationalStatusService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Finder\Finder;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Concerns\FakesEmail;
use Tests\TestCase;

/**
 * Phase 0O.10A (ADR 0056 sections 4.4, 9.3, 13-17): what recovery changes
 * around it -- canonical, private login; identity-level email under its own
 * RLS scope; the operator console; the production guard, status and metrics;
 * and the removal of Laravel's stock reset broker.
 */
class AccountRecoveryPlatformTest extends TestCase
{
    use CreatesTenancyFixtures, FakesEmail;

    // --- Login -----------------------------------------------------------

    #[Test]
    public function login_accepts_any_case_and_surrounding_whitespace(): void
    {
        $user = $this->createUser(['email' => 'teacher@example.test', 'password' => Hash::make('correct-password-1')]);

        $this->post('/login', ['email' => '  Teacher@EXAMPLE.test ', 'password' => 'correct-password-1'])->assertRedirect('/app');
        $this->assertAuthenticatedAs($user);
    }

    #[Test]
    public function a_failed_login_audits_a_fingerprint_never_the_typed_address(): void
    {
        $this->from('/login')->post('/login', ['email' => 'Somebody.Private@Example.test', 'password' => 'wrong-password'])->assertSessionHasErrors('email');

        $event = PlatformAuditEvent::query()->where('event_type', 'auth.login_failed')->latest('occurred_at')->firstOrFail();
        $this->assertSame('invalid_credentials', $event->metadata['outcome']);
        $this->assertSame(app(IdentityFingerprint::class)->of(IdentityFingerprint::LOGIN, 'somebody.private@example.test'), $event->metadata['identity_fingerprint']);
        $this->assertStringNotContainsStringIgnoringCase('somebody.private', (string) json_encode($event->toArray()));

        // Purpose-separated: the login fingerprint is not the limiter's.
        $this->assertNotSame(
            app(IdentityFingerprint::class)->of(IdentityFingerprint::LOGIN, 'a@example.test'),
            app(IdentityFingerprint::class)->of(IdentityFingerprint::RECOVERY_THROTTLE, 'a@example.test'),
        );
    }

    #[Test]
    public function the_login_throttle_counts_every_spelling_of_one_address_together(): void
    {
        $this->createUser(['email' => 'teacher@example.test', 'password' => Hash::make('correct-password-1')]);

        foreach (['teacher@example.test', 'TEACHER@example.test', ' Teacher@Example.Test', 'teacher@EXAMPLE.TEST', 'Teacher@example.test', 'tEacher@example.test'] as $spelling) {
            $this->post('/login', ['email' => $spelling, 'password' => 'wrong']);
        }

        // One canonical identity key collected all six attempts.
        $key = 'login:'.app(IdentityFingerprint::class)->of(IdentityFingerprint::LOGIN, 'teacher@example.test').'|127.0.0.1';
        $this->assertSame(6, RateLimiter::attempts($key));
        $this->assertTrue(RateLimiter::tooManyAttempts($key, 6));
        $this->assertGuest();
    }

    // --- Identity-level email and RLS ------------------------------------

    #[Test]
    public function identity_email_is_invisible_to_every_school_and_to_no_context(): void
    {
        config(['account_recovery.enabled' => true]);
        $this->fakeEmail();
        $this->createUser(['email' => 'person@example.test']);
        $this->post('/account-recovery', ['email' => 'person@example.test']);

        $scope = app(PlatformEmailScope::class);
        $this->assertSame(1, $scope->run(fn () => EmailMessage::query()->where('purpose', 'account_recovery')->count()));
        $this->assertSame(1, (int) $scope->run(fn () => DB::selectOne("select count(*) as c from email_messages where purpose = 'account_recovery'")->c), 'raw SQL under the scope');

        // No context: nothing (RLS, not just the model scope).
        $this->assertSame(0, (int) DB::selectOne("select count(*) as c from email_messages where purpose = 'account_recovery'")->c);

        // Any School: nothing.
        [, $school] = $this->createSchoolAdmin('school_admin');
        $this->assertSame(0, app(TenantContext::class)->withSchool($school, fn () => (int) DB::selectOne('select count(*) as c from email_messages where school_id is null')->c));
        $this->assertSame(0, app(TenantContext::class)->withSchool($school, fn () => EmailMessage::query()->whereNull('school_id')->count()));

        // The scope is refused inside a School, and a School cannot be entered inside it.
        app(TenantContext::class)->withSchool($school, function () use ($scope): void {
            try {
                $scope->run(fn () => null);
                $this->fail('the platform email scope never runs inside a School');
            } catch (LogicException) {
            }
        });
        $scope->run(function () use ($school): void {
            try {
                app(TenantContext::class)->set($school);
                $this->fail('no School context inside the platform email scope');
            } catch (LogicException) {
            }
        });

        // The scope ends with its callback.
        $this->assertSame('', (string) DB::selectOne("select current_setting('app.platform_email_scope', true) as v")->v);
    }

    #[Test]
    public function the_database_ties_a_missing_school_to_the_identity_purposes(): void
    {
        [, $school] = $this->createSchoolAdmin('school_admin');
        $row = fn (array $o) => array_merge([
            'id' => (string) Str::uuid(), 'school_id' => null, 'purpose' => 'school_communication', 'kind' => 'standard',
            'source_type' => 'communication_delivery', 'source_id' => (string) Str::uuid(), 'recipient_encrypted' => 'x',
            'from_mailbox' => 'notifications', 'from_display_name' => 'Lycenza', 'subject' => 'S', 'sealed_content' => 'x',
            'status' => 'pending', 'expires_at' => now()->addHour(), 'created_at' => now(), 'updated_at' => now(),
        ], $o);

        // School mail without a School.
        $this->assertRefused(fn () => app(PlatformEmailScope::class)->run(fn () => DB::table('email_messages')->insert($row([]))), 'email_messages');
        // Identity mail with a School.
        $this->assertRefused(fn () => app(TenantContext::class)->withSchool($school, fn () => DB::table('email_messages')->insert($row([
            'school_id' => $school->id, 'purpose' => 'account_recovery', 'kind' => 'critical', 'source_type' => 'account_recovery_request',
        ]))), 'email_messages_identity_level_check');
        // Identity mail outside the scope (RLS WITH CHECK).
        $this->assertRefused(fn () => DB::table('email_messages')->insert($row(['purpose' => 'security_notice', 'kind' => 'critical', 'source_type' => 'user_security_notice'])), 'row-level security');

        $this->assertInstanceOf(OutboundEmailGateway::class, app(OutboundEmailGateway::class));
        $this->assertTrue(EmailPurpose::AccountRecovery->isIdentityLevel());
    }

    private function assertRefused(callable $write, string $needle): void
    {
        try {
            DB::transaction(fn () => $write());
            $this->fail("refused: {$needle}");
        } catch (QueryException $e) {
            $this->assertStringContainsString($needle, $e->getMessage());
        }
    }

    // --- Operator console ------------------------------------------------

    #[Test]
    public function the_operator_reset_is_interactive_audited_and_works_for_root(): void
    {
        $this->fakeEmail();
        $root = $this->createPlatformRoot(['email' => 'root-person@example.test']);
        $root->createToken('cli');
        $version = (int) DB::table('users')->where('id', $root->id)->value('credential_version');

        $this->artisan('platform:user-password-reset', ['user' => 'Root-Person@Example.test'])
            ->expectsQuestion('New password (hidden)', 'operator-chosen-password-1')
            ->expectsQuestion('Confirm new password (hidden)', 'operator-chosen-password-1')
            ->expectsConfirmation('Set this new password?', 'yes')
            ->assertSuccessful();

        $fresh = User::query()->findOrFail($root->id);
        $this->assertTrue(Hash::check('operator-chosen-password-1', $fresh->password));
        $this->assertSame($version + 1, $fresh->credential_version);
        $this->assertSame(0, $fresh->tokens()->count());

        $audit = PlatformAuditEvent::query()->where('event_type', 'auth.password_reset_by_operator')->where('subject_id', $root->id)->firstOrFail();
        $this->assertStringNotContainsString('operator-chosen-password-1', (string) json_encode($audit->toArray()));
        $this->assertSame(EmailPurpose::SecurityNotice, $this->lastAcceptedEmail()->purpose);
    }

    #[Test]
    public function the_operator_reset_refuses_non_interactive_use_weak_passwords_and_unknown_accounts(): void
    {
        $user = $this->createUser(['email' => 'person@example.test', 'password' => Hash::make('original-password-1')]);

        $this->artisan('platform:user-password-reset', ['user' => 'person@example.test', '--no-interaction' => true])->assertFailed();

        $this->artisan('platform:user-password-reset', ['user' => 'person@example.test'])
            ->expectsQuestion('New password (hidden)', 'short')
            ->expectsQuestion('Confirm new password (hidden)', 'short')
            ->assertFailed();

        $this->artisan('platform:user-password-reset', ['user' => 'person@example.test'])
            ->expectsQuestion('New password (hidden)', 'a-good-password-1')
            ->expectsQuestion('Confirm new password (hidden)', 'a-good-password-1')
            ->expectsConfirmation('Set this new password?', 'no')
            ->assertFailed();

        $this->artisan('platform:user-password-reset', ['user' => 'nobody@example.test'])->assertFailed();

        $this->assertTrue(Hash::check('original-password-1', $user->fresh()->password));
        $this->assertSame(0, PlatformAuditEvent::query()->where('event_type', 'auth.password_reset_by_operator')->where('subject_id', $user->id)->count());
    }

    #[Test]
    public function the_status_command_shows_metadata_only_and_is_audited(): void
    {
        $user = $this->createUser(['email' => 'person@example.test']);
        $credential = RecoveryCredential::generate();
        AccountRecoveryRequest::query()->create([
            'selector' => $credential->selector, 'user_id' => $user->id, 'secret_hash' => RecoveryCredential::hash($credential->secret),
            'credential_version' => 1, 'email_hash' => hash('sha256', $user->email), 'created_at' => now(), 'expires_at' => now()->addMinutes(30),
        ]);

        $this->artisan('platform:account-recovery-status', ['user' => 'person@example.test'])
            ->expectsOutputToContain('open')
            ->doesntExpectOutputToContain($credential->selector)
            ->doesntExpectOutputToContain(RecoveryCredential::hash($credential->secret))
            ->assertSuccessful();

        $this->assertSame(1, PlatformAuditEvent::query()->where('event_type', 'auth.account_recovery_status_viewed')->where('subject_id', $user->id)->count());
    }

    #[Test]
    public function ended_credentials_are_pruned_after_24_hours_and_open_ones_are_kept(): void
    {
        $user = $this->createUser();
        $make = function (array $state) use ($user): string {
            $credential = RecoveryCredential::generate();

            return AccountRecoveryRequest::query()->create([
                'selector' => $credential->selector, 'user_id' => $user->id, 'secret_hash' => RecoveryCredential::hash($credential->secret),
                'credential_version' => 1, 'email_hash' => hash('sha256', $user->email), 'created_at' => now(), 'expires_at' => now()->addMinutes(30),
                ...$state,
            ])->id;
        };

        $consumed = $make(['consumed_at' => now()]);
        $invalidated = $make(['invalidated_at' => now(), 'invalidation_reason' => 'operator']);
        $expiring = $make([]);

        $this->travel(23)->hours();
        $this->artisan('platform:account-recovery-prune')->assertSuccessful();
        $this->assertSame(3, AccountRecoveryRequest::query()->count());

        $this->travel(2)->hours();
        $open = $make([]);
        $this->artisan('platform:account-recovery-prune')->assertSuccessful();

        $this->assertSame([$open], AccountRecoveryRequest::query()->pluck('id')->all());
        $this->assertNull(AccountRecoveryRequest::query()->find($consumed));
        $this->assertNull(AccountRecoveryRequest::query()->find($invalidated));
        $this->assertNull(AccountRecoveryRequest::query()->find($expiring));
    }

    // --- Production guard, status, metrics -------------------------------

    #[Test]
    public function production_refuses_enabled_recovery_without_critical_email(): void
    {
        $guard = fn () => (new ProductionConfigurationGuard(config()))->violations();

        config(['account_recovery.enabled' => false, 'email.provider' => 'none']);
        $this->assertNotContains('account_recovery_email_disabled', $guard());

        config(['account_recovery.enabled' => true]);
        $this->assertContains('account_recovery_email_disabled', $guard());

        config(['email.provider' => 'smtp', 'email.sending_domain' => '']);
        $this->assertContains('account_recovery_email_disabled', $guard());

        config(['email.sending_domain' => 'notify.lycenza.example']);
        $this->assertNotContains('account_recovery_email_disabled', $guard());
    }

    #[Test]
    public function the_status_component_is_degraded_at_worst_and_never_readiness(): void
    {
        $status = app(OperationalStatusService::class);

        config(['account_recovery.enabled' => false]);
        $this->assertSame([OperationalStatus::Degraded, 'disabled'], [$status->accountRecovery()->status, $status->accountRecovery()->reason]);

        config(['account_recovery.enabled' => true, 'email.provider' => 'none']);
        $this->assertSame([OperationalStatus::Degraded, 'unavailable'], [$status->accountRecovery()->status, $status->accountRecovery()->reason]);

        config(['email.provider' => 'fake']);
        $this->assertSame(OperationalStatus::Healthy, $status->accountRecovery()->status);

        $this->assertContains('account_recovery', array_map(fn ($c) => $c->component, $status->full()));
        $this->assertStringNotContainsString('accountRecovery', $this->methodBody($status, 'readiness'));
        $this->assertStringNotContainsString('email', $this->methodBody($status, 'readiness'));
    }

    private function methodBody(object $object, string $method): string
    {
        $reflection = new \ReflectionMethod($object, $method);
        $lines = file((string) $reflection->getFileName());

        return implode('', array_slice($lines, $reflection->getStartLine() - 1, $reflection->getEndLine() - $reflection->getStartLine() + 1));
    }

    #[Test]
    public function the_enabled_and_available_gauges_are_exported(): void
    {
        config(['account_recovery.enabled' => true, 'email.provider' => 'none']);
        $text = app(MetricsExporter::class)->render();

        $this->assertStringContainsString('lycenza_account_recovery_enabled{state="enabled"} 1', $text);
        $this->assertStringContainsString('lycenza_account_recovery_enabled{state="available"} 0', $text);
    }

    // --- The stock Laravel reset broker is gone --------------------------

    #[Test]
    public function the_stock_password_broker_cannot_run(): void
    {
        $this->assertFalse(Schema::hasTable('password_reset_tokens'));
        $this->assertNull(config('auth.defaults.passwords'));

        try {
            Password::broker();
            $this->fail('no default broker');
        } catch (\InvalidArgumentException) {
        }

        try {
            $this->createUser()->sendPasswordResetNotification('token');
            $this->fail('the stock notification never sends');
        } catch (LogicException) {
        }

        foreach (['password.request', 'password.email', 'password.reset', 'password.update'] as $name) {
            $this->assertFalse(app('router')->has($name), "stock route {$name}");
        }
    }

    #[Test]
    public function no_code_uses_the_stock_reset_machinery(): void
    {
        foreach ((new Finder)->files()->in([app_path(), base_path('routes'), resource_path('js')])->name(['*.php', '*.ts', '*.vue']) as $file) {
            $source = $file->getContents();
            foreach (['Password::broker', 'Password::sendResetLink', 'Password::reset(', 'sendResetLink(', 'PasswordBroker', 'ResetPassword', 'CanResetPassword', 'password_reset_tokens', "'/forgot-password'", "'/reset-password"] as $forbidden) {
                if ($file->getRelativePathname() === 'Models/User.php' && $forbidden === 'CanResetPassword') {
                    continue; // implemented only to throw (sendPasswordResetNotification)
                }
                $this->assertStringNotContainsString($forbidden, $source, $file->getRelativePathname());
            }
        }

        // Recovery credentials are touched only by the recovery domain and its operator commands.
        foreach ((new Finder)->files()->in(app_path())->name('*.php') as $file) {
            if (str_contains($file->getContents(), 'AccountRecoveryRequest')) {
                $this->assertMatchesRegularExpression('#^(Domain/Identity/(Application/AccountRecovery|Infrastructure)/|Console/Commands/(ShowAccountRecoveryStatus|PruneAccountRecoveryRequests)\.php$)#', $file->getRelativePathname());
            }
        }
    }

    #[Test]
    public function the_request_path_does_no_account_dependent_work(): void
    {
        // ADR 0056 section 5.2: the response time never depends on the
        // account -- the controller's request action only normalizes, counts
        // and dispatches; every lookup happens in IssueAccountRecoveryJob.
        $method = new \ReflectionMethod(AccountRecoveryController::class, 'store');
        $lines = file((string) $method->getFileName());
        $body = implode('', array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));

        foreach (['User::', 'AccountRecoveryIssuer', 'AccountRecoveryEligibility', 'AccountRecoveryRequest', 'DB::', '->where(', 'dispatchSync', 'dispatch_sync'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $body, "the request path must not {$forbidden}");
        }
        $this->assertStringContainsString('IssueAccountRecoveryJob::dispatch(', $body);
    }

    #[Test]
    public function the_log_sanitizer_redacts_recovery_material(): void
    {
        $sanitizer = new LogSanitizer;
        $secret = str_repeat('s', 43);
        $selector = str_repeat('k', 22);

        $clean = $sanitizer->sanitize([
            'new_password' => 'x', 'password_confirmation' => 'x', 'account_recovery_secret' => 'x', 'recovery_token' => 'x',
            'recovery_selector' => $selector, 'recovery_link' => 'x', 'reset_url' => 'x',
            'message' => "open https://lycenza.example/account-recovery/{$selector}#{$secret} now",
            'other' => "a fragment #{$secret}",
        ]);

        foreach (['new_password', 'password_confirmation', 'account_recovery_secret', 'recovery_token', 'recovery_selector', 'recovery_link', 'reset_url'] as $key) {
            $this->assertSame(LogSanitizer::REDACTED, $clean[$key], $key);
        }
        $this->assertStringNotContainsString($selector, $clean['message']);
        $this->assertStringNotContainsString($secret, $clean['message']);
        $this->assertStringNotContainsString($secret, $clean['other']);
    }
}

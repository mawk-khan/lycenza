<?php

namespace Tests\Feature\Identity\AccountRecovery;

use App\Domain\Identity\Application\AccountRecovery\RecoveryCredential;
use App\Domain\Identity\Infrastructure\AccountRecoveryRequest;
use App\Http\Middleware\EnforceCredentialVersion;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\ResolveSchoolContext;
use App\Models\User;
use App\Support\Auth\CredentialSession;
use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use PragmaRX\Google2FA\Google2FA;
use Symfony\Component\Finder\Finder;
use Tests\Concerns\CreatesSchoolDomains;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Feature\Auth\Mfa\Concerns\CreatesMfaFixtures;
use Tests\TestCase;

/**
 * Phase 0O.10A (ADR 0056 section 11): `users.credential_version` is the one
 * security generation of a User. The database bumps it on any password,
 * email or disable change (also a direct write) and voids every open
 * recovery credential; every session-establishing path stamps it; the
 * global middleware signs out any session whose stamp differs -- on every
 * host.
 */
class CredentialVersionTest extends TestCase
{
    use CreatesMfaFixtures, CreatesSchoolDomains, CreatesTenancyFixtures;

    private function version(User $user): int
    {
        return (int) DB::table('users')->where('id', $user->id)->value('credential_version');
    }

    /** A real session-backed sign-in, stamped the way every login path stamps. */
    private function signIn(User $user): static
    {
        /** @var SessionGuard $guard */
        $guard = Auth::guard('web');

        return $this->withSession([$guard->getName() => $user->id, CredentialSession::KEY => CredentialSession::current($user)]);
    }

    private function openRequest(User $user): AccountRecoveryRequest
    {
        $credential = RecoveryCredential::generate();

        return AccountRecoveryRequest::query()->create([
            'selector' => $credential->selector, 'user_id' => $user->id,
            'secret_hash' => RecoveryCredential::hash($credential->secret),
            'credential_version' => $this->version($user), 'email_hash' => hash('sha256', $user->email),
            'created_at' => now(), 'expires_at' => now()->addMinutes(30),
        ]);
    }

    #[Test]
    public function the_database_bumps_the_version_on_password_email_and_disable_and_never_lowers_it(): void
    {
        $user = $this->createUser();
        $this->assertSame(1, $this->version($user));

        DB::table('users')->where('id', $user->id)->update(['password' => Hash::make('something-else-1')]);
        $this->assertSame(2, $this->version($user), 'a direct password write bumps too');

        DB::table('users')->where('id', $user->id)->update(['email' => 'changed@example.test']);
        $this->assertSame(3, $this->version($user));

        DB::table('users')->where('id', $user->id)->update(['is_disabled' => true, 'disabled_at' => now()]);
        $this->assertSame(4, $this->version($user));

        DB::table('users')->where('id', $user->id)->update(['name' => 'Renamed', 'is_disabled' => false]);
        $this->assertSame(4, $this->version($user), 'unrelated changes and re-enabling do not');

        try {
            DB::transaction(fn () => DB::table('users')->where('id', $user->id)->update(['credential_version' => 1]));
            $this->fail('the version never decreases');
        } catch (QueryException $e) {
            $this->assertStringContainsString('users_credential_version', $e->getMessage());
        }
    }

    #[Test]
    public function the_database_keeps_every_email_canonical(): void
    {
        $user = $this->createUser(['email' => 'Mixed.Case@Example.TEST']);
        $this->assertSame('mixed.case@example.test', $user->fresh()->email, 'the model writes the canonical form');

        foreach (['Upper@Example.test', ' padded@example.test'] as $bad) {
            try {
                DB::transaction(fn () => DB::table('users')->where('id', $user->id)->update(['email' => $bad]));
                $this->fail("{$bad} must be refused");
            } catch (QueryException $e) {
                $this->assertStringContainsString('users_email_canonical_check', $e->getMessage());
            }
        }
    }

    #[Test]
    public function a_version_bump_voids_every_open_recovery_request_with_its_cause(): void
    {
        foreach ([
            'credential_changed' => ['password' => Hash::make('other-password-1')],
            'email_changed' => ['email' => 'moved-'.strtolower(Str::random(6)).'@example.test'],
            'account_ineligible' => ['is_disabled' => true, 'disabled_at' => now()],
        ] as $reason => $change) {
            $user = $this->createUser();
            $open = $this->openRequest($user);
            $consumed = $this->openRequest($user);
            $consumed->forceFill(['consumed_at' => now()])->save();

            DB::table('users')->where('id', $user->id)->update($change);

            $this->assertSame($reason, $open->fresh()->invalidation_reason, $reason);
            $this->assertNull($consumed->fresh()->invalidated_at, 'an ended request stays as it ended');
        }
    }

    #[Test]
    public function the_recovery_request_table_refuses_unsafe_rows(): void
    {
        $user = $this->createUser();
        $request = $this->openRequest($user);

        foreach ([
            ['expires_at' => now()->addHour()],                                 // beyond 30 minutes
            ['consumed_at' => now(), 'invalidated_at' => now(), 'invalidation_reason' => 'operator'], // both
            ['selector' => 'short'],
            ['secret_hash' => 'not-a-hash'],
        ] as $bad) {
            try {
                DB::transaction(fn () => DB::table('account_recovery_requests')->where('id', $request->id)->update($bad));
                $this->fail('refused: '.json_encode(array_keys($bad)));
            } catch (QueryException $e) {
                $this->assertStringContainsString('account_recovery_requests', $e->getMessage());
            }
        }

        DB::table('account_recovery_requests')->where('id', $request->id)->update(['consumed_at' => now()]);
        try {
            DB::transaction(fn () => DB::table('account_recovery_requests')->where('id', $request->id)->update(['consumed_at' => null]));
            $this->fail('an ended request never reopens');
        } catch (QueryException $e) {
            $this->assertStringContainsString('account_recovery_requests', $e->getMessage());
        }
    }

    #[Test]
    public function a_session_with_a_stale_or_missing_stamp_is_signed_out_on_the_platform_host(): void
    {
        $user = $this->createUser();

        $this->signIn($user)->get('/app')->assertOk();
        $this->assertAuthenticatedAs($user);

        DB::table('users')->where('id', $user->id)->update(['password' => Hash::make('changed-elsewhere-1')]);
        $this->app['auth']->forgetGuards();

        $this->get('/app')->assertRedirect('/login');
        $this->assertGuest();

        // A session from before 0O.10A (no stamp at all) is signed out too.
        /** @var SessionGuard $guard */
        $guard = Auth::guard('web');
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $this->withSession([$guard->getName() => $user->id])->get('/app')->assertRedirect('/login');
        $this->assertGuest();
    }

    #[Test]
    public function a_session_on_a_custom_school_host_is_signed_out_too(): void
    {
        $user = $this->createUser();
        $school = $this->createSchool();
        $this->createMembership($user, $school);
        $this->createSchoolDomain($school, 'erp.northfield.org');

        $this->signIn($user)->get('http://erp.northfield.org/app')->assertOk();
        $this->assertAuthenticatedAs($user);

        DB::table('users')->where('id', $user->id)->update(['password' => Hash::make('changed-elsewhere-1')]);
        $this->app['auth']->forgetGuards();

        $this->get('http://erp.northfield.org/app')->assertRedirect('/login');
        $this->assertGuest();
    }

    #[Test]
    public function an_mfa_challenge_started_before_a_reset_cannot_complete(): void
    {
        $user = $this->createUser(['password' => Hash::make('correct-password-1')]);
        $secret = app(Google2FA::class)->generateSecretKey();
        $this->enrollActiveMfaFactor($user, $secret);

        $this->post('/login', ['email' => $user->email, 'password' => 'correct-password-1'])->assertRedirect('/login/mfa');
        $this->assertSame(1, session('mfa_pending_credential_version'));

        DB::table('users')->where('id', $user->id)->update(['password' => Hash::make('reset-meanwhile-1')]);

        // Even the right code no longer completes it.
        $this->post('/login/mfa', ['code' => $this->currentTotpCodeFor($secret)])->assertRedirect('/login');
        $this->assertGuest();
    }

    #[Test]
    public function every_session_establishing_path_stamps_the_version(): void
    {
        $user = $this->createUser(['password' => Hash::make('correct-password-1')]);
        DB::table('users')->where('id', $user->id)->update(['password' => Hash::make('correct-password-2')]);

        $this->post('/login', ['email' => $user->email, 'password' => 'correct-password-2'])->assertRedirect('/app');
        $this->assertSame(2, session(CredentialSession::KEY));

        // Every code path that signs a browser in stamps the version.
        $signIns = [];
        foreach ((new Finder)->files()->in(app_path())->name('*.php') as $file) {
            if (preg_match('/Auth::(login|loginUsingId|attempt)\(|->(login|loginUsingId)\(\$/', $file->getContents()) === 1) {
                $signIns[] = $file->getRelativePathname();
                $this->assertStringContainsString('CredentialSession::stamp', $file->getContents(), $file->getRelativePathname().' signs in without stamping');
            }
        }
        sort($signIns);
        $this->assertSame([
            'Http/Controllers/Auth/LoginController.php',
            'Http/Controllers/Auth/MfaChallengeController.php',
            'Http/Controllers/Auth/SessionHandoffController.php',
            'Http/Controllers/Identity/InvitationAcceptanceController.php',
        ], $signIns);
    }

    #[Test]
    public function the_middleware_is_global_and_runs_before_school_resolution(): void
    {
        $router = app('router');
        $this->assertContains(EnforceCredentialVersion::class, $router->getMiddlewareGroups()['web']);

        $priority = app(Kernel::class)->getMiddlewarePriority();
        $this->assertLessThan(array_search(ResolveSchoolContext::class, $priority, true), array_search(EnforceCredentialVersion::class, $priority, true));
        $this->assertLessThan(array_search(HandleInertiaRequests::class, $router->getMiddlewareGroups()['web'], true), array_search(EnforceCredentialVersion::class, $router->getMiddlewareGroups()['web'], true));
    }
}

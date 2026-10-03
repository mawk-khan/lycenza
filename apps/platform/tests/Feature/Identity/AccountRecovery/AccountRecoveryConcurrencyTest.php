<?php

namespace Tests\Feature\Identity\AccountRecovery;

use App\Domain\Identity\Application\AccountRecovery\RecoveryCredential;
use App\Domain\Identity\Infrastructure\AccountRecoveryRequest;
use App\Models\User;
use App\Support\Tenancy\TenantRls;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ForcesConcurrentOverlap;
use Tests\TestCase;

/**
 * Phase 0O.10A (ADR 0056 section 10.2): REAL concurrency -- two separate OS
 * processes against real PostgreSQL, the overlap forced and observed (the
 * contender is seen blocked on the holder's lock before the holder commits),
 * never assumed.
 *
 * Every credential writer locks the User row first, so a reset serializes
 * with any other change of the same User; the loser re-reads and sees the
 * change (consumed, superseded, a new credential version, a new email, a
 * disabled account) and gets the generic invalid outcome. Exactly one
 * password change ever wins.
 *
 * Committed fixtures (no DatabaseTransactions): the child processes are
 * separate sessions and could never see uncommitted rows.
 */
class AccountRecoveryConcurrencyTest extends TestCase
{
    use ForcesConcurrentOverlap;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    private ?User $user = null;

    protected function tearDown(): void
    {
        if ($this->user !== null) {
            DB::table('personal_access_tokens')->where('tokenable_id', $this->user->id)->delete();
            // E21.4 (F1): only the migration role can delete a User (test cleanup).
            DB::connection('pgsql_admin')->table('users')->where('id', $this->user->id)->delete(); // cascades its recovery requests
        }

        // The winners' post-commit security notices were committed by the
        // child processes; left behind, a later test's sweeper would submit
        // them. The test database holds no identity-level email at rest.
        DB::connection('pgsql_admin')->transaction(function ($admin): void {
            $admin->select("SELECT set_config('".TenantRls::PLATFORM_EMAIL_SCOPE_VAR."', 'on', true)");
            $admin->table('operational_work_backlog')->whereIn('source', ['email:account_recovery', 'email:security_notice'])->delete();
            $admin->table('email_provider_references')->whereNull('school_id')->delete();
            $admin->table('email_messages')->whereNull('school_id')->delete();
        });

        parent::tearDown();
    }

    private function person(): User
    {
        return $this->user = User::factory()->create(['email' => 'race-'.bin2hex(random_bytes(4)).'@example.test', 'password' => Hash::make('original-password-1')]);
    }

    /** @return array{0: string, 1: string} [selector, secret] of a committed open request */
    private function link(User $user): array
    {
        $credential = RecoveryCredential::generate();
        AccountRecoveryRequest::query()->create([
            'selector' => $credential->selector, 'user_id' => $user->id, 'secret_hash' => RecoveryCredential::hash($credential->secret),
            'credential_version' => (int) DB::table('users')->where('id', $user->id)->value('credential_version'),
            'email_hash' => hash('sha256', $user->email), 'created_at' => now(), 'expires_at' => now()->addMinutes(30),
        ]);

        return [$credential->selector, $credential->secret];
    }

    /** @param array<int, string> $holder @param array<int, string> $contender */
    private function race(array $holder, array $contender): array
    {
        $script = base_path('tests/Support/race-account-recovery.php');

        return $this->raceWithHeldHolder([PHP_BINARY, $script, ...$holder], [PHP_BINARY, $script, ...$contender]);
    }

    private function passwordIs(string $plain): bool
    {
        return Hash::check($plain, (string) DB::table('users')->where('id', $this->user->id)->value('password'));
    }

    #[Test]
    public function the_same_link_submitted_twice_concurrently_resets_once(): void
    {
        [$selector, $secret] = $this->link($this->person());

        [$holder, $contender] = $this->race(['reset', $selector, $secret, 'first-password-111'], ['reset', $selector, $secret, 'second-password-222']);

        $this->assertSame(['succeeded', 'invalid'], [$holder, $contender]);
        $this->assertTrue($this->passwordIs('first-password-111'));
        $this->assertSame(2, (int) DB::table('users')->where('id', $this->user->id)->value('credential_version'));
    }

    #[Test]
    public function two_different_links_of_one_user_submitted_concurrently_reset_once(): void
    {
        $user = $this->person();
        [$a, $aSecret] = $this->link($user);
        [$b, $bSecret] = $this->link($user);

        [$holder, $contender] = $this->race(['reset', $a, $aSecret, 'first-password-111'], ['reset', $b, $bSecret, 'second-password-222']);

        $this->assertSame(['succeeded', 'invalid'], [$holder, $contender]);
        $this->assertTrue($this->passwordIs('first-password-111'));
        $this->assertSame('superseded_by_reset', AccountRecoveryRequest::query()->where('selector', $b)->value('invalidation_reason'));
    }

    #[Test]
    public function disabling_the_account_while_a_reset_waits_voids_the_reset(): void
    {
        [$selector, $secret] = $this->link($this->person());

        [$holder, $contender] = $this->race(['disable', $this->user->id], ['reset', $selector, $secret, 'new-password-333']);

        $this->assertSame(['disabled', 'invalid'], [$holder, $contender]);
        $this->assertTrue($this->passwordIs('original-password-1'));
        $this->assertSame('account_ineligible', AccountRecoveryRequest::query()->where('selector', $selector)->value('invalidation_reason'));
    }

    #[Test]
    public function an_email_change_while_a_reset_waits_voids_the_reset(): void
    {
        [$selector, $secret] = $this->link($this->person());

        [$holder, $contender] = $this->race(['email', $this->user->id, 'moved-'.bin2hex(random_bytes(4)).'@example.test'], ['reset', $selector, $secret, 'new-password-333']);

        $this->assertSame(['email_changed', 'invalid'], [$holder, $contender]);
        $this->assertTrue($this->passwordIs('original-password-1'));
        $this->assertSame('email_changed', AccountRecoveryRequest::query()->where('selector', $selector)->value('invalidation_reason'));
    }

    #[Test]
    public function an_operator_reset_and_a_self_service_reset_never_both_apply_to_one_version(): void
    {
        [$selector, $secret] = $this->link($this->person());

        [$holder, $contender] = $this->race(['operator', $this->user->id, 'operator-password-444'], ['reset', $selector, $secret, 'self-service-password-555']);

        $this->assertSame(['operator_reset', 'invalid'], [$holder, $contender]);
        $this->assertTrue($this->passwordIs('operator-password-444'));
        $this->assertSame('credential_changed', AccountRecoveryRequest::query()->where('selector', $selector)->value('invalidation_reason'));

        // The other order: the self-service reset wins its version; the
        // operator's later change is a new, separate version.
        [$selector, $secret] = $this->link($this->user);
        [$holder, $contender] = $this->race(['reset', $selector, $secret, 'self-service-password-666'], ['operator', $this->user->id, 'operator-password-777']);

        $this->assertSame(['succeeded', 'operator_reset'], [$holder, $contender]);
        $this->assertTrue($this->passwordIs('operator-password-777'));
    }

    #[Test]
    public function a_personal_access_token_in_use_during_a_reset_is_still_revoked(): void
    {
        $user = $this->person();
        $plain = $user->createToken('phone')->plainTextToken;
        [$selector, $secret] = $this->link($user);

        // The token's use holds its row; the reset waits for it, then revokes it.
        [$holder, $contender] = $this->race(['pat', $plain], ['reset', $selector, $secret, 'new-password-888']);
        $this->assertSame(['token_used', 'succeeded'], [$holder, $contender]);
        $this->assertNull(PersonalAccessToken::findToken($plain));

        // The reverse: a use racing a committed-later reset finds nothing to use.
        $plain = $this->user->createToken('tablet')->plainTextToken;
        [$selector, $secret] = $this->link($this->user);
        [$holder, $contender] = $this->race(['reset', $selector, $secret, 'new-password-999'], ['pat', $plain]);
        $this->assertSame(['succeeded', 'token_gone'], [$holder, $contender]);
        $this->assertNull(PersonalAccessToken::findToken($plain));
    }
}

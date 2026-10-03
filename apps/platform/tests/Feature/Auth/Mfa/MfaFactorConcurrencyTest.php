<?php

namespace Tests\Feature\Auth\Mfa;

use App\Models\User;
use App\Models\UserMfaFactor;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * REQUIRED real-concurrency proof (Phase 0H.4D-P1 section 3/9): two
 * genuinely separate OS processes race to activate two DIFFERENT
 * pending UserMfaFactor rows for the SAME User against real
 * PostgreSQL. The partial unique index
 * `user_mfa_factors_one_active_per_user` (`ON user_mfa_factors
 * (user_id) WHERE status = 'active'`) is the actual guarantee this
 * proves -- not a controller-level check-then-insert. Mirrors
 * GradeScaleConcurrencyTest's "two concurrent activation attempts"
 * scenario/reasoning.
 */
class MfaFactorConcurrencyTest extends TestCase
{
    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    private ?User $user = null;

    protected function tearDown(): void
    {
        if ($this->user !== null) {
            // E21.4 (F1): only the migration role can delete a User (test cleanup).
            DB::connection('pgsql_admin')->table('users')->where('id', $this->user->id)->delete();
        }

        parent::tearDown();
    }

    #[Test]
    public function two_concurrent_activations_of_different_factors_for_the_same_user_leave_exactly_one_active(): void
    {
        $this->user = User::factory()->create();

        $factorA = UserMfaFactor::create([
            'user_id' => $this->user->id,
            'type' => 'totp',
            'secret_encrypted' => 'AAAAAAAAAAAAAAAA',
            'status' => 'pending',
        ]);
        $factorB = UserMfaFactor::create([
            'user_id' => $this->user->id,
            'type' => 'totp',
            'secret_encrypted' => 'BBBBBBBBBBBBBBBB',
            'status' => 'pending',
        ]);

        $script = __DIR__.'/../../../Support/activate-mfa-factor.php';

        $processA = new Process(['php', $script, $factorA->id]);
        $processB = new Process(['php', $script, $factorB->id]);
        $processA->start();
        $processB->start();
        $processA->wait();
        $processB->wait();

        $outputs = ['A' => $processA->getOutput(), 'B' => $processB->getOutput()];

        $activated = collect($outputs)->filter(fn ($o) => str_starts_with($o, 'activated:'));
        $rejected = collect($outputs)->filter(fn ($o) => $o === 'rejected:unique_violation');

        $this->assertCount(1, $activated, 'Exactly one of the two concurrent factor activations must succeed. Outputs: '.json_encode($outputs));
        $this->assertCount(1, $rejected, 'The loser must be rejected by the DB-level unique index. Outputs: '.json_encode($outputs));

        $activeCount = UserMfaFactor::query()->where('user_id', $this->user->id)->where('status', 'active')->count();
        $this->assertSame(1, $activeCount, 'A User must never end up with two simultaneously active MFA factors.');
    }
}

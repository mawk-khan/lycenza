<?php

namespace Tests\Feature\Auth\Mfa;

use App\Models\User;
use App\Support\Auth\Mfa\MfaRecoveryCodeService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * REQUIRED real-concurrency proof (Phase 0H.4D-P1 section 11/24): two
 * genuinely separate OS processes race to consume the SAME valid
 * recovery code against real PostgreSQL -- never two sequential calls
 * inside one PHP process. The atomic `WHERE consumed_at IS NULL`
 * conditional UPDATE in MfaRecoveryCodeService::consume() (not a
 * check-then-update) is what makes this safe. Mirrors
 * GradeScaleConcurrencyTest's exact pattern/reasoning.
 */
class MfaRecoveryCodeConcurrencyTest extends TestCase
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
    public function two_concurrent_attempts_to_consume_the_same_recovery_code_leave_exactly_one_winner(): void
    {
        $this->user = User::factory()->create();
        $codes = app(MfaRecoveryCodeService::class)->issue($this->user);
        $code = $codes[0];

        $script = __DIR__.'/../../../Support/consume-recovery-code.php';

        $processA = new Process(['php', $script, $this->user->id, $code]);
        $processB = new Process(['php', $script, $this->user->id, $code]);
        $processA->start();
        $processB->start();
        $processA->wait();
        $processB->wait();

        $outputs = [$processA->getOutput(), $processB->getOutput()];

        $wins = array_filter($outputs, fn ($o) => $o === 'consumed:true');
        $losses = array_filter($outputs, fn ($o) => $o === 'consumed:false');

        $this->assertCount(1, $wins, 'Exactly one concurrent consumption must succeed. Outputs: '.implode(' | ', $outputs));
        $this->assertCount(1, $losses, 'The loser must observe the code as already consumed. Outputs: '.implode(' | ', $outputs));

        $stillUsable = app(MfaRecoveryCodeService::class)->consume($this->user, $code);
        $this->assertFalse($stillUsable, 'The code must not be consumable a third time.');
    }
}

<?php

namespace Tests\Feature\Students\ProcessingAuthorization;

use App\Domain\Students\Application\StudentProcessingAuthorizationService;
use App\Domain\Students\Domain\ProcessingAuthorizationBasisType;
use App\Domain\Students\Domain\ProcessingAuthorizationPurpose;
use App\Domain\Students\Infrastructure\StudentProcessingAuthorization;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\Feature\Students\ProcessingAuthorization\Concerns\CreatesProcessingAuthorizationFixtures;
use Tests\TestCase;

/**
 * REQUIRED real-concurrency proofs for Phase 0H.4D-P2 §51: two
 * genuinely separate OS processes racing the same
 * StudentProcessingAuthorization row against real PostgreSQL -- never
 * two sequential calls inside one PHP process.
 */
class ProcessingAuthorizationConcurrencyTest extends TestCase
{
    use CreatesProcessingAuthorizationFixtures;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    private ?School $school = null;

    protected function tearDown(): void
    {
        if ($this->school !== null) {
            $this->school->delete();
        }

        parent::tearDown();
    }

    #[Test]
    public function two_concurrent_terminal_actions_against_the_same_grant_leave_exactly_one_winner(): void
    {
        $this->school = $this->createSchool();
        $student = $this->createStudent($this->school, ['date_of_birth' => '2000-01-01']);
        $actor = $this->staffActor($this->school);
        $grant = app(StudentProcessingAuthorizationService::class)->recordAdultStudentConsent(
            $this->school, $student, ProcessingAuthorizationPurpose::AcademicRecords, $actor,
        );

        $barrier = tempnam(sys_get_temp_dir(), 'spa_barrier_');
        unlink($barrier);

        $script = __DIR__.'/../../../Support/terminate-processing-authorization.php';
        $processA = new Process(['php', $script, $this->school->id, $grant->id, $actor->id, 'withdraw', $barrier]);
        $processB = new Process(['php', $script, $this->school->id, $grant->id, $actor->id, 'revoke', $barrier]);
        $processA->start();
        $processB->start();

        usleep(100_000);
        touch($barrier);

        $processA->wait();
        $processB->wait();
        @unlink($barrier);

        $outputs = [$processA->getOutput(), $processB->getOutput()];

        $winners = array_filter($outputs, fn ($o) => str_starts_with($o, 'terminated:'));
        $losers = array_filter($outputs, fn ($o) => $o === 'rejected:already_terminated');

        $this->assertCount(1, $winners, 'Exactly one concurrent terminal action must succeed. Outputs: '.implode(' | ', $outputs));
        $this->assertCount(1, $losers, 'The loser must be rejected as already-terminated. Outputs: '.implode(' | ', $outputs));

        $terminalRows = app(TenantContext::class)->withSchool(
            $this->school,
            fn () => StudentProcessingAuthorization::query()->where('terminates_authorization_id', $grant->id)->count(),
        );
        $this->assertSame(1, $terminalRows, 'The grant must have exactly one terminal event, never two.');
    }

    #[Test]
    public function two_concurrent_supersede_attempts_against_the_same_grant_leave_exactly_one_winner(): void
    {
        $this->school = $this->createSchool();
        $student = $this->createStudent($this->school, ['date_of_birth' => '2000-01-01']);
        $actor = $this->staffActor($this->school);
        $grant = app(StudentProcessingAuthorizationService::class)->recordStatutorySchoolPurpose(
            $this->school, $student, ProcessingAuthorizationPurpose::AcademicRecords, $actor,
        );

        $barrier = tempnam(sys_get_temp_dir(), 'spa_barrier_');
        unlink($barrier);

        $script = __DIR__.'/../../../Support/terminate-processing-authorization.php';
        $processA = new Process(['php', $script, $this->school->id, $grant->id, $actor->id, 'supersede', $barrier]);
        $processB = new Process(['php', $script, $this->school->id, $grant->id, $actor->id, 'supersede', $barrier]);
        $processA->start();
        $processB->start();

        usleep(100_000);
        touch($barrier);

        $processA->wait();
        $processB->wait();
        @unlink($barrier);

        $outputs = [$processA->getOutput(), $processB->getOutput()];

        $winners = array_filter($outputs, fn ($o) => str_starts_with($o, 'terminated:'));
        $losers = array_filter($outputs, fn ($o) => $o === 'rejected:already_terminated');

        $this->assertCount(1, $winners, 'Exactly one concurrent supersede must succeed. Outputs: '.implode(' | ', $outputs));
        $this->assertCount(1, $losers, 'The loser must be rejected. Outputs: '.implode(' | ', $outputs));

        // No partial lineage state: exactly one terminal event AND
        // exactly one new replacement grant exist -- never a
        // terminated-with-no-replacement or two replacements.
        [$terminalRows, $newGrants] = app(TenantContext::class)->withSchool($this->school, fn () => [
            StudentProcessingAuthorization::query()->where('terminates_authorization_id', $grant->id)->count(),
            StudentProcessingAuthorization::query()
                ->where('student_id', $student->id)
                ->where('basis_type', ProcessingAuthorizationBasisType::AdultStudentConsent->value)
                ->where('status', 'recorded')
                ->count(),
        ]);

        $this->assertSame(1, $terminalRows);
        $this->assertSame(1, $newGrants);
    }

    /**
     * Phase 0H.4D-P2 lock-freshness correction §10/§11: this test
     * previously inferred "the terminal action must have blocked on
     * the lock" purely from elapsed time (`sleep($sleepSeconds)` then
     * "did >= sleepSeconds-0.5 seconds pass"), which the closure audit
     * found could intermittently fail for a reason unrelated to any
     * real defect: nothing guaranteed the lock-holder process actually
     * won the race to acquire the Student row lock before the
     * terminate process attempted its own. Replaced with an explicit
     * three-signal handshake that proves actual database serialization
     * rather than inferring it from scheduler timing:
     *
     * 1. The lock-holder process signals `$lockAcquired` itself, and
     *    ONLY after `lockQualifyingAuthorizationIdForProcessing()` has
     *    already returned -- i.e. only once PostgreSQL has genuinely
     *    granted both the Student and grant row locks to it.
     * 2. This test then polls real PostgreSQL session state
     *    (`pg_stat_activity`) until the terminate process's own named
     *    session is observed with `wait_event_type = 'Lock'` --
     *    positive proof it is blocked waiting on a lock this process
     *    holds, not merely "hasn't finished yet".
     * 3. Only after that positive confirmation does this test signal
     *    `$release`, letting the lock-holder commit.
     *
     * No step depends on a fixed sleep duration or an elapsed-time
     * threshold anywhere in this test.
     */
    #[Test]
    public function a_concurrent_terminal_action_blocks_until_a_locked_processing_read_releases(): void
    {
        $this->school = $this->createSchool();
        $student = $this->createStudent($this->school, ['date_of_birth' => '2000-01-01']);
        $actor = $this->staffActor($this->school);
        $grant = app(StudentProcessingAuthorizationService::class)->recordAdultStudentConsent(
            $this->school, $student, ProcessingAuthorizationPurpose::AcademicRecords, $actor,
        );

        $startBarrier = tempnam(sys_get_temp_dir(), 'spa_start_');
        unlink($startBarrier);
        $lockAcquired = tempnam(sys_get_temp_dir(), 'spa_locked_');
        unlink($lockAcquired);
        $release = tempnam(sys_get_temp_dir(), 'spa_release_');
        unlink($release);

        $lockScript = __DIR__.'/../../../Support/lock-processing-authorization-for-processing.php';
        $terminateScript = __DIR__.'/../../../Support/terminate-processing-authorization.php';

        $lockProcess = new Process(['php', $lockScript, $this->school->id, $student->id, $startBarrier, $lockAcquired, $release]);
        $terminateProcess = new Process(['php', $terminateScript, $this->school->id, $grant->id, $actor->id, 'withdraw', $startBarrier]);

        $lockProcess->start();
        $terminateProcess->start();
        touch($startBarrier);

        // The lock-holder process must ALWAYS be released, even if an
        // assertion below fails -- otherwise a failed assertion would
        // leave its transaction (and the row locks it holds) open
        // forever, orphaning a real OS process and hanging every
        // subsequent test that touches the same School/Student
        // (this exact failure mode was hit once while developing this
        // handshake and is the reason for this try/finally).
        try {
            // Signal 1: the lock-holder proves, itself, that it
            // actually holds the locks -- this only exists on disk
            // after lockQualifyingAuthorizationIdForProcessing() has
            // returned.
            $deadline = microtime(true) + 10;
            while (! file_exists($lockAcquired)) {
                if (microtime(true) > $deadline) {
                    $this->fail('Lock-holder process never signalled lock acquisition within the 10s deadline.');
                }
                usleep(1_000);
            }

            // Signal 2: positively observe the terminate process's own
            // named PostgreSQL session genuinely blocked on a lock --
            // never inferred from how much time has elapsed.
            $blockedConfirmed = false;
            $pollDeadline = microtime(true) + 5;
            while (microtime(true) < $pollDeadline) {
                $row = DB::connection('pgsql_admin')->selectOne(
                    "select wait_event_type from pg_stat_activity where application_name = 'spa_worker_withdraw'"
                );
                if ($row !== null && $row->wait_event_type === 'Lock') {
                    $blockedConfirmed = true;
                    break;
                }
                usleep(2_000);
            }
            $this->assertTrue($blockedConfirmed, 'The terminate process was never observed genuinely blocked on a PostgreSQL lock via pg_stat_activity -- serialization was not proven.');
        } finally {
            // Signal 3: release the lock-holder no matter what
            // happened above.
            touch($release);
            $lockProcess->wait();
            $terminateProcess->wait();
            @unlink($startBarrier);
            @unlink($lockAcquired);
            @unlink($release);
        }

        $this->assertStringStartsWith('locked:'.$grant->id, $lockProcess->getOutput());
        $this->assertSame('terminated:withdrawn', $terminateProcess->getOutput());
    }
}

<?php

namespace Tests\Feature\Students\ProcessingAuthorization;

use App\Domain\Students\Application\StudentProcessingAuthorizationService;
use App\Domain\Students\Domain\ProcessingAuthorizationBasisType;
use App\Domain\Students\Domain\ProcessingAuthorizationPurpose;
use App\Domain\Students\Infrastructure\StudentProcessingAuthorization;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
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

    #[Test]
    public function a_concurrent_terminal_action_blocks_until_a_locked_processing_read_releases(): void
    {
        $this->school = $this->createSchool();
        $student = $this->createStudent($this->school, ['date_of_birth' => '2000-01-01']);
        $actor = $this->staffActor($this->school);
        $grant = app(StudentProcessingAuthorizationService::class)->recordAdultStudentConsent(
            $this->school, $student, ProcessingAuthorizationPurpose::AcademicRecords, $actor,
        );

        $barrier = tempnam(sys_get_temp_dir(), 'spa_barrier_');
        unlink($barrier);
        $sleepSeconds = 2;

        $lockScript = __DIR__.'/../../../Support/lock-processing-authorization-for-processing.php';
        $terminateScript = __DIR__.'/../../../Support/terminate-processing-authorization.php';

        $lockProcess = new Process(['php', $lockScript, $this->school->id, $student->id, (string) $sleepSeconds, $barrier]);
        $terminateProcess = new Process(['php', $terminateScript, $this->school->id, $grant->id, $actor->id, 'withdraw', $barrier]);

        $lockProcess->start();
        $terminateProcess->start();

        usleep(100_000);
        $start = microtime(true);
        touch($barrier);

        $lockProcess->wait();
        $terminateProcess->wait();
        $elapsed = microtime(true) - $start;
        @unlink($barrier);

        $this->assertStringStartsWith('locked:'.$grant->id, $lockProcess->getOutput());
        $this->assertSame('terminated:withdrawn', $terminateProcess->getOutput());

        // Prove serialization, not merely eventual success: the
        // terminal action could only complete after the reader's
        // transaction (holding the grant row lock for $sleepSeconds)
        // released it.
        $this->assertGreaterThanOrEqual($sleepSeconds - 0.5, $elapsed, 'The terminal action must have blocked on the row lock, not raced past it.');
    }
}

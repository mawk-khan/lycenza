<?php

namespace Tests\Feature\Timetable;

use App\Domain\Timetable\Infrastructure\TimetablePeriod;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\Feature\Timetable\Concerns\CreatesTimetableFixtures;
use Tests\TestCase;

/**
 * Phase 0H (Timetable foundation): the REQUIRED real-concurrency proof
 * for the overlap invariant -- two GENUINELY separate OS processes, not
 * two sequential calls in one PHP process, both attempt to CREATE a
 * DIFFERENT, mutually-overlapping active Period for the SAME School at
 * the same time. Mirrors AcademicYearActivationConcurrencyTest.php's
 * exact pattern (including `protected $connectionsToTransact = []` --
 * the subprocesses are separate PostgreSQL sessions and can never see
 * this test process's otherwise-uncommitted fixture rows -- and cleanup
 * via `$school->delete()` in tearDown()).
 *
 * Architecture note (flagged -- not fully specified by this
 * checkpoint's brief): App\Support\Concurrency\TenantLock wraps
 * Laravel's `Cache::lock()`, backed by whatever the `cache.default`
 * config resolves to. This test suite's own phpunit.xml pins
 * `CACHE_STORE=array` for ordinary (single-process) tests -- but the
 * `array` store is an in-PHP-process-only structure, never shared
 * between two separate OS processes, so using it here would make BOTH
 * subprocesses acquire their own independent, mutually invisible
 * "lock" and the whole point of this test would be silently defeated
 * (both would proceed, producing two overlapping active Periods).
 * Both subprocesses below are therefore started with an explicit
 * `CACHE_STORE=database` environment override (Symfony Process's `$env`
 * constructor argument merges onto, rather than replaces, the inherited
 * environment) -- Laravel's `database` cache store's `cache_locks`
 * table (the same table the base `0001_01_01_000001_create_cache_table`
 * migration already creates) is real, shared PostgreSQL state both
 * processes see, without requiring a live Redis connection in every
 * environment this suite runs in.
 */
class TimetablePeriodConcurrencyTest extends TestCase
{
    use CreatesTimetableFixtures;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    private ?School $school = null;

    protected function tearDown(): void
    {
        if ($this->school !== null) {
            $this->school->delete(); // cascades timetable_periods/timetable_entries
        }

        parent::tearDown();
    }

    #[Test]
    public function two_real_concurrent_processes_creating_overlapping_periods_leave_exactly_one_active(): void
    {
        $this->school = $this->createSchool();
        $context = app(TenantContext::class);
        $actor = $this->fullTimetableActor($this->school);

        $script = __DIR__.'/../../Support/create-overlapping-timetable-period.php';
        $env = ['CACHE_STORE' => 'database'];

        $processA = new Process(
            ['php', $script, $this->school->id, 'P1', 'Period 1', '09:00:00', '10:00:00', '', $actor->id],
            null,
            $env,
        );
        $processB = new Process(
            ['php', $script, $this->school->id, 'P2', 'Period 2', '09:30:00', '10:30:00', '', $actor->id],
            null,
            $env,
        );
        $processA->start();
        $processB->start();
        $processA->wait();
        $processB->wait();

        $outputs = [$processA->getOutput(), $processB->getOutput()];
        $createdCount = count(array_filter($outputs, fn ($o) => str_starts_with($o, 'created:')));

        $this->assertSame(1, $createdCount, 'Exactly one of the two concurrent overlapping-Period creations must succeed.');
        $rejected = array_values(array_filter($outputs, fn ($o) => ! str_starts_with($o, 'created:')));
        $this->assertCount(1, $rejected);
        $this->assertStringContainsString('TimetablePeriodOverlapException', $rejected[0]);

        $activeCount = $context->withSchool(
            $this->school,
            fn () => TimetablePeriod::query()->where('school_id', $this->school->id)->where('status', 'active')->count(),
        );
        $this->assertSame(1, $activeCount, 'The database must contain exactly one active overlapping Period -- zero overlapping active Periods.');
    }
}

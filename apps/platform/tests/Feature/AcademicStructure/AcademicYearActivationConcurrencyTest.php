<?php

namespace Tests\Feature\AcademicStructure;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Concerns\ForcesConcurrentOverlap;
use Tests\TestCase;

/**
 * REQUIRED real-concurrency proof (Phase 0D section 80): two GENUINELY
 * separate OS processes -- not two sequential calls in one PHP process
 * -- both attempt to activate a DIFFERENT (draft) Academic Year for
 * the SAME School against real PostgreSQL, at the same time. The
 * database's own partial unique index
 * (`academic_years_one_active_per_school`) is what makes this safe;
 * this test proves the final state, not just that the application code
 * "looks" correct.
 *
 * Deliberately does NOT use DatabaseTransactions for the fixtures this
 * test creates (see $connectionsToTransact) -- the two subprocesses
 * are separate PostgreSQL sessions and can never see this test
 * process's uncommitted rows, mirroring
 * WebhookDeliveryConcurrencyTest's identical reasoning.
 */
class AcademicYearActivationConcurrencyTest extends TestCase
{
    use CreatesTenancyFixtures, ForcesConcurrentOverlap;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    private ?School $school = null;

    protected function tearDown(): void
    {
        if ($this->school !== null) {
            $this->deleteSchoolAsAdmin($this->school); // cascades academic_years
        }

        parent::tearDown();
    }

    #[Test]
    public function two_real_concurrent_processes_activating_different_years_leave_exactly_one_active(): void
    {
        $this->school = $this->createSchool();
        $context = app(TenantContext::class);

        $yearA = $context->withSchool($this->school, fn () => AcademicYear::factory()->for($this->school, 'school')->create([
            'code' => 'AY-A', 'name' => '2026-27',
        ]));
        $yearB = $context->withSchool($this->school, fn () => AcademicYear::factory()->for($this->school, 'school')->create([
            'code' => 'AY-B', 'name' => '2027-28', 'starts_on' => '2027-06-01', 'ends_on' => '2028-05-31',
        ]));

        // Forced, verified overlap (Tests\Concerns\ForcesConcurrentOverlap):
        // A's activation stays uncommitted until B is observed blocked on
        // the one-active-per-School partial unique index entry A holds.
        $script = __DIR__.'/../../Support/activate-academic-year.php';
        [$holderOutput, $contenderOutput] = $this->raceWithHeldHolder(
            ['php', $script, $this->school->id, $yearA->id],
            ['php', $script, $this->school->id, $yearB->id],
        );

        $this->assertSame('activated', $holderOutput, 'The first (held) activation must succeed.');
        $this->assertSame(
            'rejected:App\\Domain\\AcademicStructure\\Application\\Exceptions\\ConcurrentActivationConflictException',
            $contenderOutput,
            'The overlapping activation must be rejected with the domain concurrency exception.',
        );

        $activeYears = $context->withSchool(
            $this->school,
            fn () => AcademicYear::query()->where('school_id', $this->school->id)->where('status', 'active')->count(),
        );
        $this->assertSame(1, $activeYears, 'The database must contain exactly one active Academic Year for this School.');
    }
}

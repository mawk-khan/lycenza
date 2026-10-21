<?php

namespace Tests\Feature\Fees;

use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Models\Campus;
use App\Models\School;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesFinanceFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Concerns\ForcesConcurrentOverlap;
use Tests\TestCase;

/**
 * ORG.7G-1A (ADR 0076 §49): a manual Charge reads its creation-date placement
 * FOR SHARE, so it stores the Campus of the placement context that actually
 * stood when it committed -- proven with two GENUINELY separate OS processes
 * (the contender observed blocked), in both orders, with no deadlock:
 * - the Charge locks first: the placement's cancellation waits, the Charge
 *   keeps B (valid when it was created) and is never moved afterwards;
 * - the cancellation locks first: the Charge waits, then sees no valid
 *   placement that day and is whole-School (NULL), never the stale B.
 */
class ChargeCampusConcurrencyTest extends TestCase
{
    use CreatesFinanceFixtures, CreatesTenancyFixtures, ForcesConcurrentOverlap;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    private School $school;

    private Campus $b;

    private StudentEnrollment $onB;

    /** @var array<string, string> */
    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = $this->createSchool();
        $a = $this->createCampus($this->school, ['name' => 'North']);
        $this->b = $this->createCampus($this->school, ['name' => 'South']);
        $grade = $this->createGradeLevel($this->school);
        $year = $this->createAcademicYear($this->school, ['status' => 'active', 'starts_on' => now()->subDays(200)->toDateString(), 'ends_on' => now()->addDays(30)->toDateString()]);
        $student = $this->createStudent($this->school, ['status' => 'active']);
        $this->createStudentEnrollment($student, $this->createSection($year, $a, $grade, ['code' => 'SA', 'status' => 'active']), [
            'status' => 'transferred', 'starts_on' => now()->subDays(60)->toDateString(), 'ends_on' => now()->subDay()->toDateString(),
        ]);
        $this->onB = $this->createStudentEnrollment($student, $this->createSection($year, $this->b, $grade, ['code' => 'SB', 'status' => 'active']), [
            'status' => 'active', 'starts_on' => now()->toDateString(),
        ]);
        $this->ids = [
            'student' => $student->id,
            'year' => $year->id,
            'receivable' => $this->createLedgerAccount($this->school, ['code' => 'AR-RACE', 'type' => 'asset'])->id,
            'revenue' => $this->createLedgerAccount($this->school, ['code' => 'INC-RACE', 'type' => 'income'])->id,
        ];
    }

    protected function tearDown(): void
    {
        $this->purgeSchoolsAsAdmin([$this->school->id]);

        parent::tearDown();
    }

    /** @return array<int, string> */
    private function op(string $operation, string ...$args): array
    {
        return ['php', __DIR__.'/../../Support/student-eligibility-op.php', $operation, $this->school->id, ...$args];
    }

    private function charge(): array
    {
        return $this->op('manual-charge', $this->ids['student'], $this->ids['year'], $this->ids['receivable'], $this->ids['revenue']);
    }

    private function storedCampus(): ?string
    {
        $campuses = DB::connection('pgsql_admin')->table('charges')->where('school_id', $this->school->id)->pluck('campus_id');
        $this->assertCount(1, $campuses);

        return $campuses->first();
    }

    #[Test]
    public function a_charge_that_locks_first_keeps_the_placement_it_saw(): void
    {
        [$charge, $cancel] = $this->raceWithHeldHolder($this->charge(), $this->op('enrollment-cancel', $this->onB->id));

        $this->assertSame(['ok', 'ok'], [$charge, $cancel]);
        $this->assertSame($this->b->id, $this->storedCampus(), 'B was the valid placement when the Charge was created, and the later cancellation never moves it');
    }

    #[Test]
    public function a_cancellation_that_locks_first_is_seen_by_the_charge(): void
    {
        [$cancel, $charge] = $this->raceWithHeldHolder($this->op('enrollment-cancel', $this->onB->id), $this->charge());

        $this->assertSame(['ok', 'ok'], [$cancel, $charge]);
        $this->assertNull($this->storedCampus(), 'no valid placement that day: whole-School, never the cancelled B');
    }
}

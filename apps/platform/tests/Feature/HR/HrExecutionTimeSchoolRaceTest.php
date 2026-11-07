<?php

namespace Tests\Feature\HR;

use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Models\School;
use App\Support\Tenancy\SchoolNotOperationalException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Concerns\ForcesConcurrentOverlap;
use Tests\TestCase;

/**
 * HR.R1-C11 (HRC-11): the race rule 86 exists for. A request passes `school-context`,
 * then the School is suspended. Two real sessions, the holder held open and the
 * contender observed blocked: the writer's FOR SHARE on the School orders it against
 * the suspension, whichever comes first.
 */
class HrExecutionTimeSchoolRaceTest extends TestCase
{
    use CreatesTenancyFixtures, ForcesConcurrentOverlap;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    private School $school;

    private EmploymentRecord $employment;

    private string $hr;

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = $this->createSchool();
        $this->hr = $this->fullHrActor($this->school)->id;
        $this->employment = $this->createEmploymentRecord($this->createEmployee($this->school), ['status' => 'active', 'starts_on' => '2024-01-01', 'ends_on' => null]);
    }

    protected function tearDown(): void
    {
        $this->deleteSchoolAsAdmin($this->school);
        parent::tearDown();
    }

    /** @return list<string> */
    private function suspend(): array
    {
        return ['php', __DIR__.'/../../Support/archived-identity-op.php', 'suspend', $this->school->id];
    }

    /** @return list<string> */
    private function end(): array
    {
        return ['php', __DIR__.'/../../Support/hr-lifecycle-op.php', $this->school->id, 'end', $this->employment->id, '2030-01-01', $this->hr];
    }

    private function state(): array
    {
        return [
            DB::connection('pgsql_admin')->table('schools')->where('id', $this->school->id)->value('status'),
            DB::connection('pgsql_admin')->table('employment_records')->where('id', $this->employment->id)->value('status'),
        ];
    }

    #[Test]
    public function a_write_waiting_on_a_suspension_is_refused_once_it_commits(): void
    {
        [$holder, $contender] = $this->raceWithHeldHolder($this->suspend(), $this->end());

        $this->assertSame('ok:1', $holder);
        $this->assertSame('rejected:'.SchoolNotOperationalException::class, $contender);
        $this->assertSame(['suspended', 'active'], $this->state());
    }

    #[Test]
    public function a_suspension_waiting_on_a_write_lands_after_it(): void
    {
        [$holder, $contender] = $this->raceWithHeldHolder($this->end(), $this->suspend());

        $this->assertStringStartsWith('ok:', $holder);
        $this->assertSame('ok:1', $contender);
        $this->assertSame(['suspended', 'separated'], $this->state());
    }

    /** @return list<string> */
    private function editProbation(): array
    {
        return ['php', __DIR__.'/../../Support/hr-lifecycle-op.php', $this->school->id, 'update', $this->employment->id, '2029-12-31', $this->hr];
    }

    /** @return array{0: mixed, 1: mixed, 2: mixed} */
    private function employmentRow(): array
    {
        $row = DB::connection('pgsql_admin')->table('employment_records')->where('id', $this->employment->id)->first();

        return [$row->status, $row->ends_on, $row->probation_ends_on];
    }

    #[Test]
    public function an_edit_waiting_on_an_end_never_brings_the_old_state_back(): void
    {
        // HR.R1-C11 (HRC-10): the edit holds a model read before the end committed, and writes only its own field.
        [$holder, $contender] = $this->raceWithHeldHolder($this->end(), $this->editProbation());

        $this->assertStringStartsWith('ok:', $holder);
        $this->assertSame('ok:separated', $contender);
        $this->assertSame(['separated', '2030-01-01', '2029-12-31'], $this->employmentRow());
    }

    #[Test]
    public function an_end_waiting_on_an_edit_keeps_both(): void
    {
        [$holder, $contender] = $this->raceWithHeldHolder($this->editProbation(), $this->end());

        $this->assertSame('ok:active', $holder);
        $this->assertStringStartsWith('ok:', $contender);
        $this->assertSame(['separated', '2030-01-01', '2029-12-31'], $this->employmentRow());
    }
}

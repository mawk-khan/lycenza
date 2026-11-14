<?php

namespace Tests\Feature\HR\Separation;

use App\Domain\HR\Application\EmploymentService;
use App\Domain\HR\Application\Separation\ClearanceResponsibility;
use App\Domain\HR\Application\Separation\SeparationCaseReadService;
use App\Domain\HR\Application\Separation\SeparationCaseService;
use App\Domain\HR\Application\Separation\SeparationClearanceRequirementService;
use App\Domain\HR\Application\Separation\SeparationReason;
use App\Domain\HR\Application\Workforce\StaffType;
use App\Domain\HR\Infrastructure\EmploymentSeparationCase;
use App\Domain\Leave\Application\Calendar\StaffCalendarHistory;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Concerns\ForcesConcurrentOverlap;
use Tests\TestCase;

/**
 * HR.R1-C35 WO-21 (HRC-8) on COMMITTED data.
 *
 * - A reclassification and an approval of the same employment, in two OS processes (holder held open,
 *   contender observed blocked): both take the Employee's placement lock and then the employment row, so the
 *   approval records the staff type the employment had when it ran -- the new one when the reclassification
 *   committed first; the old (unclassified) one, with its skipped requirements, when the approval did.
 * - A case approved before WO-21 (written below the guards, as the old schema allowed) says its basis was not
 *   recorded, and is never back-filled.
 */
class SeparationClearanceBasisConcurrencyTest extends TestCase
{
    use CreatesTenancyFixtures, ForcesConcurrentOverlap;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    private ?School $school = null;

    private User $hr;

    private User $approver;

    private EmploymentSeparationCase $case;

    private string $lab;

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = $this->createSchool();
        $context = app(TenantContext::class);
        $context->set($this->school);
        $this->hr = $this->createUserWithCapabilities($this->school, ['hr.employees.assignments.manage', 'hr.separations.view', 'hr.separations.manage', 'hr.separations.configure']);
        $this->approver = $this->createUserWithCapabilities($this->school, ['hr.separations.view', 'hr.separations.approve']);
        $requirements = app(SeparationClearanceRequirementService::class);
        $requirements->create($this->school, 'keys', 'Keys returned', ClearanceResponsibility::HR, true, null, $this->hr);
        $this->lab = $requirements->create($this->school, 'lab', 'Lab equipment returned', ClearanceResponsibility::HR, true, StaffType::TEACHING, $this->hr)->id;
        $employment = app(EmploymentService::class)->create($this->createEmployee($this->school, ['user_id' => null]), ['employment_type' => 'permanent', 'starts_on' => '2022-01-01'], $this->hr);
        $today = app(StaffCalendarHistory::class)->today($this->school);
        $this->case = app(SeparationCaseService::class)->record($this->school, $employment->id, SeparationReason::RESIGNATION, $today, $today, $this->hr);
        $context->clearAll();
    }

    protected function tearDown(): void
    {
        if ($this->school !== null) {
            try {
                $this->purgeSchoolsAsAdmin([$this->school->id]);
            } catch (\Throwable) {
                // Append-only evidence makes the School hard to delete; residue is reset between sessions.
            }
        }
        parent::tearDown();
    }

    /** @return list<string> */
    private function script(string $op, User $actor, ?string $extra = null): array
    {
        return ['php', __DIR__.'/../../../Support/separation-race.php', $op, $this->school->id, $actor->id, $this->case->id, (string) $this->case->version, ...($extra === null ? [] : [$extra])];
    }

    private function row(): object
    {
        return DB::connection('pgsql_admin')->table('employment_separation_cases')->where('id', $this->case->id)->first(['status', 'clearance_staff_type', 'clearance_skipped_requirement_ids']);
    }

    #[Test]
    public function a_reclassification_held_open_is_the_staff_type_the_waiting_approval_records(): void
    {
        [$holder, $contender] = $this->raceWithHeldHolder($this->script('reclassify', $this->hr, 'teaching'), $this->script('approve', $this->approver));

        $this->assertSame('ok:reclassified:teaching', $holder);
        $this->assertSame('ok:approved', $contender);
        $row = $this->row();
        $this->assertSame(['teaching', '{}'], [$row->clearance_staff_type, $row->clearance_skipped_requirement_ids], 'classified: nothing skipped');
    }

    #[Test]
    public function an_approval_held_open_records_unclassified_and_the_waiting_reclassification_never_rewrites_it(): void
    {
        [$holder, $contender] = $this->raceWithHeldHolder($this->script('approve', $this->approver), $this->script('reclassify', $this->hr, 'teaching'));

        $this->assertSame('ok:approved', $holder);
        $this->assertSame('ok:reclassified:teaching', $contender);
        $row = $this->row();
        $this->assertSame([null, '{'.$this->lab.'}'], [$row->clearance_staff_type, $row->clearance_skipped_requirement_ids], 'what the checklist used, then');
    }

    #[Test]
    public function a_case_approved_before_wo21_says_not_recorded_and_is_never_back_filled(): void
    {
        $context = app(TenantContext::class);
        $context->withSchool($this->school, fn () => app(SeparationCaseService::class)->approve($this->school, $this->case->id, $this->case->version, $this->approver, $this->freshMfaProofFor($this->approver)));
        // As the pre-WO-21 schema left it: approved, with no recorded basis (cleared below the guards).
        $admin = DB::connection('pgsql_admin');
        $admin->transaction(function () use ($admin): void {
            $admin->statement("SET LOCAL session_replication_role = 'replica'");
            $admin->table('employment_separation_cases')->where('id', $this->case->id)->update(['clearance_staff_type' => null, 'clearance_skipped_requirement_ids' => null]);
        });

        $basis = $context->withSchool($this->school, fn () => app(SeparationCaseReadService::class)->detail($this->school, $this->hr, $this->case->id)['clearanceBasis']);
        $this->assertSame(['state' => 'not_recorded'], $basis, 'never reconstructed from today');

        $error = '';
        try {
            $context->withSchool($this->school, fn () => DB::table('employment_separation_cases')->where('id', $this->case->id)
                ->update(['clearance_skipped_requirement_ids' => '{'.$this->lab.'}', 'version' => DB::raw('version + 1')]));
        } catch (QueryException $e) {
            $error = $e->getMessage();
        }
        $this->assertStringContainsString('recorded once, at approval (hr_separation_clearance_basis_guard)', $error);
        $this->assertNull($this->row()->clearance_skipped_requirement_ids);
    }
}

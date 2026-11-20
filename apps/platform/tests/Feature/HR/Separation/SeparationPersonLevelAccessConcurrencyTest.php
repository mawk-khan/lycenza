<?php

namespace Tests\Feature\HR\Separation;

use App\Domain\HR\Application\AccountLinks\EmployeeAccountLinkCorrectionService;
use App\Domain\HR\Application\EmployeeService;
use App\Domain\HR\Application\EmploymentService;
use App\Domain\HR\Application\Separation\SeparationCaseService;
use App\Domain\HR\Application\Separation\SeparationObligation;
use App\Domain\HR\Application\Separation\SeparationObligations;
use App\Domain\HR\Application\Separation\SeparationReason;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmployeeAccountLink;
use App\Domain\HR\Infrastructure\EmploymentSeparationCase;
use App\Domain\Leave\Application\Calendar\StaffCalendarHistory;
use App\Models\School;
use App\Models\SchoolMembership;
use App\Models\User;
use App\Support\Tenancy\SchoolTimezone;
use App\Support\Tenancy\TenantContext;
use App\Support\Testing\LocalCatalogueFixtures;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Concerns\ForcesConcurrentOverlap;
use Tests\Feature\HR\Concerns\CreatesLinkedHrStaff;
use Tests\TestCase;

/**
 * HR.R1-C35 WO-24 (WO20-N1, RF1-N2, WO18-N1; RF2 §5.4): the person-level access answer against the link, grant and
 * correction changes that move it, in two real OS processes on COMMITTED data, the holder held open and the
 * contender observed blocked on it. The orders come from the existing locks; WO-24 adds none:
 * - a completion holds Identity's School access lock first, then the Employee FOR SHARE; an unlink or a link takes
 *   the Employee FOR UPDATE; a grant takes the School access lock first; a correction's approval takes the Employee
 *   FOR UPDATE (it may unlink). So each pair serializes, and the waiting side reads what the first committed:
 *   - an unlink first: the ended, uncorrected link is still the person, so the completion is refused;
 *   - a grant to an older account first: the completion is refused; the completion first: it stands, and the later
 *     grant shows OPEN on the completed case without rewriting it (WO20-N5's display);
 *   - a correction first: the corrected account leaves, so the completion goes through; the completion first: it
 *     stands, and the correction is applied after it;
 *   - kept-access recording against a grant to an older account: the set is bound before or after it, never a mix;
 *   - a death record by the older account waits for a correction of its link and is then allowed.
 * Also a record from before WO-20 (no set, written below the guards): the runtime role cannot complete it while an
 * unlinked account of the person holds a role (D-WO20-2, the database half).
 */
class SeparationPersonLevelAccessConcurrencyTest extends TestCase
{
    use CreatesLinkedHrStaff, CreatesTenancyFixtures, ForcesConcurrentOverlap;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    private ?School $school = null;

    private User $admin;

    private User $hr;

    private User $linker;

    private User $subjectUser;

    private SchoolMembership $subject;

    private User $older;

    private SchoolMembership $olderMembership;

    private Employee $employee;

    private string $employmentId;

    private EmploymentSeparationCase $case;

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = $this->createSchool();
        $context = app(TenantContext::class);
        $context->set($this->school);
        $this->admin = $this->createUser();
        $this->assignSchoolRole($this->createMembership($this->admin, $this->school), 'school_admin');
        $this->hr = $this->createUserWithCapabilities($this->school, ['hr.employees.assignments.manage', 'hr.separations.view', 'hr.separations.manage']);
        $this->linker = $this->createUserWithCapabilities($this->school, ['hr.employees.manage', 'hr.employees.assignments.manage']);
        $approver = $this->createUserWithCapabilities($this->school, ['hr.separations.view', 'hr.separations.approve']);
        $this->subjectUser = $this->createUser();
        $this->subject = $this->createMembership($this->subjectUser, $this->school);
        $this->older = $this->createUserWithCapabilities($this->school, ['hr.employees.assignments.manage']);
        $this->olderMembership = SchoolMembership::query()->where('school_id', $this->school->id)->where('user_id', $this->older->id)->sole();
        $this->employee = $this->createEmployee($this->school, ['user_id' => null]);
        $this->employmentId = app(EmploymentService::class)->create($this->employee, ['employment_type' => 'permanent', 'starts_on' => '2022-01-01'], $this->hr)->id;

        // Link history through the application: older (ended), then the subject (current); none corrected.
        $this->link($this->older);
        $this->unlinkNow();
        $this->link($this->subjectUser);
        // The older account's own role (hr.employees.assignments.manage) is revoked: each test grants what it needs.
        $this->revokeAll($this->olderMembership);

        $cases = app(SeparationCaseService::class);
        $today = app(StaffCalendarHistory::class)->today($this->school);
        $case = $cases->record($this->school, $this->employmentId, SeparationReason::RESIGNATION, $today, $today, $this->hr);
        $case = $cases->approve($this->school, $case->id, $case->version, $approver, $this->freshMfaProofFor($approver));
        $this->case = $cases->finalize($this->school, $case->id, $case->version, $this->hr, $this->freshMfaProofFor($this->hr));
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

    private function inSchool(callable $work): mixed
    {
        return app(TenantContext::class)->withSchool($this->school, $work);
    }

    private function link(User $user): void
    {
        $this->inSchool(fn () => app(EmployeeService::class)->linkUser(Employee::query()->findOrFail($this->employee->id), $user->id, $this->linker));
    }

    private function unlinkNow(): void
    {
        $this->inSchool(fn () => app(EmployeeService::class)->unlinkUser(Employee::query()->findOrFail($this->employee->id), $this->linker));
    }

    private function revokeAll(SchoolMembership $membership): void
    {
        DB::connection('pgsql_admin')->table('membership_role_assignments')->where('school_membership_id', $membership->id)->whereNull('revoked_at')
            ->update(['revoked_at' => now(), 'revoked_by_user_id' => $this->admin->id, 'revocation_reason' => 'revoked']);
    }

    /** @return list<string> */
    private function complete(?int $version = null): array
    {
        return ['php', __DIR__.'/../../../Support/separation-race.php', 'complete', $this->school->id, $this->hr->id, $this->case->id, (string) ($version ?? $this->case->version)];
    }

    /** @return list<string> */
    private function retain(): array
    {
        return ['php', __DIR__.'/../../../Support/separation-race.php', 'retain', $this->school->id, $this->admin->id, $this->case->id, (string) $this->case->version];
    }

    /** @return list<string> */
    private function grant(SchoolMembership $membership, string $role = 'teacher'): array
    {
        return ['php', __DIR__.'/../../../Support/staff-account-op.php', 'grant', $this->school->id, $this->admin->id, $membership->id, $role];
    }

    /** @return list<string> */
    private function unlinkOp(): array
    {
        return ['php', __DIR__.'/../../../Support/acting-employee-op.php', 'unlink', $this->school->id, $this->linker->id, $this->employee->id];
    }

    /** @return list<string> a correction of the older account's link, requested now and approved in the process */
    private function correctOlderOp(?Employee $employee = null): array
    {
        $employee ??= $this->employee;
        $linkId = (string) $this->inSchool(fn () => EmployeeAccountLink::query()->where('employee_id', $employee->id)->where('user_id', $this->older->id)->value('id'));
        $correction = $this->requestCorrection($this->inSchool(fn () => Employee::query()->findOrFail($employee->id)), $linkId, $this->linkedHrStaff($this->school));
        $version = $this->inSchool(fn () => app(EmployeeAccountLinkCorrectionService::class)->version($this->school->id, $linkId));

        return ['php', __DIR__.'/../../../Support/payroll-reconciliation-op.php', 'approve-correction', $this->school->id, $correction->id, $version, $this->linkedHrStaff($this->school)->id];
    }

    /** A committed School grant written before the race (the catalogue's local/testing seam). */
    private function grantNow(SchoolMembership $membership, string $name = 'Seeded role'): void
    {
        LocalCatalogueFixtures::grantRole($membership, $this->createFixtureRole(['hr.employees.view'], name: $name));
    }

    private function caseRow(): EmploymentSeparationCase
    {
        return $this->inSchool(fn () => EmploymentSeparationCase::query()->findOrFail($this->case->id));
    }

    private function access(): string
    {
        return $this->inSchool(fn () => collect(app(SeparationObligations::class)->forCase($this->school, EmploymentSeparationCase::query()->findOrFail($this->case->id)))
            ->firstWhere('code', SeparationObligations::SCHOOL_ACCESS)->state);
    }

    /** @return list<string> */
    private function live(SchoolMembership ...$memberships): array
    {
        return DB::connection('pgsql_admin')->table('membership_role_assignments as a')->join('roles as r', 'r.id', '=', 'a.role_id')
            ->whereIn('a.school_membership_id', array_map(fn (SchoolMembership $m) => $m->id, $memberships))->whereNull('a.revoked_at')->where('r.scope', 'school')
            ->orderBy('a.id')->pluck('a.id')->map(fn ($id) => (string) $id)->all();
    }

    #[Test]
    public function an_unlink_held_open_is_seen_by_the_waiting_completion_which_is_refused(): void
    {
        $this->grantNow($this->subject);

        [$holder, $contender] = $this->raceWithHeldHolder($this->unlinkOp(), $this->complete());

        $this->assertSame('unlinked', $holder);
        $this->assertSame('refused:SEPARATION_OBLIGATIONS_OPEN', $contender, 'it waited on the Employee row, then read the ended link as the person');
        $this->assertSame('finalized', $this->caseRow()->status);
        $this->assertCount(1, $this->live($this->subject), 'nothing was revoked');
        $this->assertSame(SeparationObligation::OPEN, $this->access());
    }

    #[Test]
    public function a_completion_held_open_is_ordered_before_a_waiting_unlink_and_stands(): void
    {
        [$holder, $contender] = $this->raceWithHeldHolder($this->complete(), $this->unlinkOp());

        $this->assertSame(['ok:completed', 'unlinked'], [$holder, $contender]);
        $case = $this->caseRow();
        $this->assertSame(['completed', $this->hr->id], [$case->status, $case->completed_by_user_id], 'never re-read or rewritten');
    }

    #[Test]
    public function a_grant_to_an_older_account_held_open_is_seen_by_the_waiting_completion(): void
    {
        [$holder, $contender] = $this->raceWithHeldHolder($this->grant($this->olderMembership), $this->complete());

        $this->assertSame('granted', $holder);
        $this->assertSame('refused:SEPARATION_OBLIGATIONS_OPEN', $contender, 'it waited on Identity\'s lock, then read the older account\'s role');
        $this->assertSame('finalized', $this->caseRow()->status);
    }

    #[Test]
    public function a_completion_held_open_is_ordered_before_a_waiting_grant_to_an_older_account(): void
    {
        [$holder, $contender] = $this->raceWithHeldHolder($this->complete(), $this->grant($this->olderMembership));

        $this->assertSame(['ok:completed', 'granted'], [$holder, $contender]);
        $this->assertSame('completed', $this->caseRow()->status);
        $this->assertSame(SeparationObligation::OPEN, $this->access(), 'the later role shows on the completed case; the completion is not rewritten');
    }

    #[Test]
    public function a_correction_held_open_is_seen_by_the_waiting_completion_which_then_completes(): void
    {
        $this->grantNow($this->olderMembership);
        $this->assertSame(SeparationObligation::OPEN, $this->access());

        [$holder, $contender] = $this->raceWithHeldHolder($this->correctOlderOp(), $this->complete());

        $this->assertSame(['ok', 'ok:completed'], [$holder, $contender], 'the corrected account is someone else\'s, never held to this case');
        $this->assertCount(1, $this->live($this->olderMembership), 'the unrelated person keeps their role');
    }

    #[Test]
    public function a_completion_held_open_is_ordered_before_a_waiting_correction(): void
    {
        $correction = $this->correctOlderOp();

        [$holder, $contender] = $this->raceWithHeldHolder($this->complete(), $correction);

        $this->assertSame(['ok:completed', 'ok'], [$holder, $contender]);
        $case = $this->caseRow();
        $this->assertSame(['completed', $this->hr->id], [$case->status, $case->completed_by_user_id]);
        $this->assertNotNull($this->inSchool(fn () => EmployeeAccountLink::query()->where('employee_id', $this->employee->id)->where('user_id', $this->older->id)->value('voided_at')));
    }

    #[Test]
    public function a_grant_to_an_older_account_held_open_is_bound_by_the_waiting_recording(): void
    {
        $this->grantNow($this->subject);

        [$holder, $contender] = $this->raceWithHeldHolder($this->grant($this->olderMembership), $this->retain());

        $this->assertSame('granted', $holder);
        $both = $this->live($this->subject, $this->olderMembership);
        $this->assertCount(2, $both);
        $this->assertSame('ok:finalized:'.implode(',', $both), $contender, 'the set after the grant, every account of the person');
        $this->assertSame(SeparationObligation::RETAINED, $this->access());
    }

    #[Test]
    public function a_recording_held_open_binds_before_a_waiting_grant_to_an_older_account_which_reopens_the_case(): void
    {
        $this->grantNow($this->subject);
        $before = $this->live($this->subject, $this->olderMembership);

        [$holder, $contender] = $this->raceWithHeldHolder($this->retain(), $this->grant($this->olderMembership));

        $this->assertSame(['ok:finalized:'.implode(',', $before), 'granted'], [$holder, $contender]);
        $this->assertSame(SeparationObligation::OPEN, $this->access(), 'the older account\'s later role is outside the kept set');
    }

    #[Test]
    public function a_death_record_by_the_older_account_waits_for_a_correction_of_its_link_and_is_then_allowed(): void
    {
        // Another Employee the older account was once linked to (ended, uncorrected), employed, with no case open.
        $other = $this->createEmployee($this->school, ['user_id' => null]);
        $employment = $this->inSchool(fn () => app(EmploymentService::class)->create($other, ['employment_type' => 'permanent', 'starts_on' => '2022-01-01'], $this->hr));
        $this->inSchool(fn () => app(EmployeeService::class)->linkUser(Employee::query()->findOrFail($other->id), $this->older->id, $this->linker));
        $this->inSchool(fn () => app(EmployeeService::class)->unlinkUser(Employee::query()->findOrFail($other->id), $this->linker));
        // The older account's authority to record a death (its role is revoked in setUp).
        LocalCatalogueFixtures::grantRole($this->olderMembership, $this->createFixtureRole(['hr.employees.assignments.manage'], name: 'Death recorder'));
        $today = SchoolTimezone::today($this->school);
        $death = ['php', __DIR__.'/../../../Support/acting-employee-op.php', 'deceased', $this->school->id, $this->older->id, $employment->id, $today];

        $refused = new Process($death);
        $refused->mustRun();
        $this->assertSame('rejected:HR_KNOWN_SAME_PERSON', trim($refused->getOutput()), 'before the correction: the older account is the person');

        [$holder, $contender] = $this->raceWithHeldHolder($this->correctOlderOp($other), $death);

        $this->assertSame(['ok', 'deceased'], [$holder, $contender], 'it waited on the Employee row, then read the void');
    }

    #[Test]
    public function a_record_from_before_wo20_cannot_be_completed_raw_while_an_unlinked_account_holds_a_role(): void
    {
        $this->grantNow($this->subject);
        $admin = DB::connection('pgsql_admin');
        $admin->transaction(function () use ($admin): void {
            $admin->statement("SET LOCAL session_replication_role = 'replica'");
            $admin->table('employment_separation_cases')->where('id', $this->case->id)
                ->update(['access_retained_by_user_id' => $this->admin->id, 'access_retained_at' => now(), 'version' => $this->case->version + 1]);
        });
        $this->unlinkNow();
        $this->assertSame(SeparationObligation::OPEN, $this->access(), 'unbound is never RETAINED (D-WO20-2), after an unlink too');

        $error = '';
        try {
            $this->inSchool(fn () => DB::table('employment_separation_cases')->where('id', $this->case->id)->update([
                'status' => 'completed', 'completed_by_user_id' => $this->hr->id, 'completed_at' => now(), 'version' => $this->case->version + 2,
            ]));
        } catch (QueryException $e) {
            $error = $e->getMessage();
        }
        $this->assertStringContainsString('(hr_separation_completion_access)', $error);
        $this->assertSame('finalized', $this->caseRow()->status);
    }
}

<?php

namespace Tests\Feature\HR\Separation;

use App\Domain\HR\Application\EmploymentService;
use App\Domain\HR\Application\Separation\SeparationCaseReadService;
use App\Domain\HR\Application\Separation\SeparationCaseService;
use App\Domain\HR\Application\Separation\SeparationException;
use App\Domain\HR\Application\Separation\SeparationObligation;
use App\Domain\HR\Application\Separation\SeparationObligations;
use App\Domain\HR\Application\Separation\SeparationReason;
use App\Domain\HR\Infrastructure\EmploymentSeparationCase;
use App\Domain\Leave\Application\Calendar\StaffCalendarHistory;
use App\Models\School;
use App\Models\SchoolMembership;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Concerns\ForcesConcurrentOverlap;
use Tests\TestCase;

/**
 * HR.R1-C35 WO-20 (HRC-4): recording "access kept" against Identity's grant, revocation and off-boarding, and
 * against the case's own completion, in two real OS processes, holder held open and contender observed blocked
 * (COMMITTED fixtures). The recording takes Identity's School access lock FIRST, the lock every grant,
 * revocation, off-boarding and completion takes first (rule 141), so each pair is ordered strictly:
 * - a grant or revocation first: the recording binds the set as it stands after it -- never a mix;
 * - the recording first: it binds the set before; the later grant is outside it, so the case is OPEN again;
 * - an off-boarding first: nothing is left to keep, and nothing is recorded;
 * - a completion or a second recording first: the waiting recording is stale and records nothing.
 *
 * Also, on committed data: a record made before WO-20 (written below the guards, as the old schema allowed)
 * binds nothing, is never RETAINED, and can never be back-filled (D-WO20-2).
 */
class SeparationRetainedAccessConcurrencyTest extends TestCase
{
    use CreatesTenancyFixtures, ForcesConcurrentOverlap;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    private ?School $school = null;

    private User $admin;

    private User $hr;

    private SchoolMembership $subject;

    private EmploymentSeparationCase $case;

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = $this->createSchool();
        $context = app(TenantContext::class);
        $context->set($this->school);
        // The issuer of grants and the recorder of kept access: a School administrator (school.members.manage).
        $this->admin = $this->createUser();
        $this->assignSchoolRole($this->createMembership($this->admin, $this->school), 'school_admin');
        $this->hr = $this->createUserWithCapabilities($this->school, ['hr.employees.assignments.manage', 'hr.separations.view', 'hr.separations.manage']);
        $approver = $this->createUserWithCapabilities($this->school, ['hr.separations.view', 'hr.separations.approve']);
        $subjectUser = $this->createUser();
        $this->subject = $this->createMembership($subjectUser, $this->school);
        $this->assignSchoolRole($this->subject, 'staff_self_service');
        $employee = $this->createEmployee($this->school, ['user_id' => $subjectUser->id]);
        $employment = app(EmploymentService::class)->create($employee, ['employment_type' => 'permanent', 'starts_on' => '2022-01-01'], $this->hr);

        $cases = app(SeparationCaseService::class);
        $today = app(StaffCalendarHistory::class)->today($this->school);
        $case = $cases->record($this->school, $employment->id, SeparationReason::RESIGNATION, $today, $today, $this->hr);
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

    /** @return list<string> */
    private function retain(?int $version = null): array
    {
        return ['php', __DIR__.'/../../../Support/separation-race.php', 'retain', $this->school->id, $this->admin->id, $this->case->id, (string) ($version ?? $this->case->version)];
    }

    /** @return list<string> */
    private function staff(string $op, string $role = 'teacher'): array
    {
        return ['php', __DIR__.'/../../../Support/staff-account-op.php', $op, $this->school->id, $this->admin->id, $this->subject->id, ...($op === 'suspend' ? [] : [$role])];
    }

    /** @return list<string> the subject's live School grant ids, sorted */
    private function live(): array
    {
        return DB::connection('pgsql_admin')->table('membership_role_assignments as a')->join('roles as r', 'r.id', '=', 'a.role_id')
            ->where('a.school_membership_id', $this->subject->id)->whereNull('a.revoked_at')->where('r.scope', 'school')
            ->orderBy('a.id')->pluck('a.id')->map(fn ($id) => (string) $id)->all();
    }

    private function stored(): ?string
    {
        return DB::connection('pgsql_admin')->table('employment_separation_cases')->where('id', $this->case->id)->value('access_retained_grant_ids');
    }

    private function access(): string
    {
        return app(TenantContext::class)->withSchool($this->school, fn () => collect(app(SeparationObligations::class)
            ->forCase($this->school, EmploymentSeparationCase::query()->findOrFail($this->case->id)))->firstWhere('code', SeparationObligations::SCHOOL_ACCESS)->state);
    }

    #[Test]
    public function a_grant_held_open_is_bound_by_the_waiting_recording(): void
    {
        [$holder, $contender] = $this->raceWithHeldHolder($this->staff('grant'), $this->retain());

        $this->assertSame('granted', $holder);
        $live = $this->live();
        $this->assertCount(2, $live);
        $this->assertSame('ok:finalized:'.implode(',', $live), $contender, 'the set after the grant, both grants');
        $this->assertSame(SeparationObligation::RETAINED, $this->access());
    }

    #[Test]
    public function a_recording_held_open_binds_the_set_before_the_waiting_grant_which_reopens_the_case(): void
    {
        $before = $this->live();

        [$holder, $contender] = $this->raceWithHeldHolder($this->retain(), $this->staff('grant'));

        $this->assertSame('ok:finalized:'.implode(',', $before), $holder);
        $this->assertSame('granted', $contender);
        $this->assertSame('{'.implode(',', $before).'}', $this->stored(), 'the later grant never entered the evidence');
        $this->assertSame(SeparationObligation::OPEN, $this->access(), 'a grant outside the kept set');
    }

    #[Test]
    public function a_revocation_held_open_is_reflected_by_the_waiting_recording(): void
    {
        DB::transaction(fn () => app(TenantContext::class)->withSchool($this->school, fn () => $this->assignSchoolRole($this->subject, 'teacher')));
        $this->assertCount(2, $this->live());

        [$holder, $contender] = $this->raceWithHeldHolder($this->staff('revoke-role', 'teacher'), $this->retain());

        $this->assertSame('revoked', $holder);
        $this->assertSame('ok:finalized:'.implode(',', $this->live()), $contender);
        $this->assertCount(1, $this->live(), 'only the role still held was kept');
    }

    #[Test]
    public function an_off_boarding_held_open_leaves_nothing_to_keep(): void
    {
        [$holder, $contender] = $this->raceWithHeldHolder($this->staff('suspend'), $this->retain());

        $this->assertSame('suspended', $holder);
        $this->assertSame('refused:SEPARATION_NO_ACTIVE_ACCESS', $contender);
        $this->assertNull($this->stored());
    }

    #[Test]
    public function a_completion_or_a_second_recording_held_open_makes_the_waiting_recording_stale(): void
    {
        $version = $this->case->version;

        [$holder, $contender] = $this->raceWithHeldHolder($this->retain($version), $this->retain($version));
        $this->assertSame('ok:finalized:'.implode(',', $this->live()), $holder);
        $this->assertSame('refused:SEPARATION_STALE', $contender, 'one recording, one set');

        [$holder, $contender] = $this->raceWithHeldHolder(
            ['php', __DIR__.'/../../../Support/separation-race.php', 'complete', $this->school->id, $this->hr->id, $this->case->id, (string) ($version + 1)],
            $this->retain($version + 1),
        );
        $this->assertSame('ok:completed', $holder);
        $this->assertSame('refused:SEPARATION_STALE', $contender);
    }

    #[Test]
    public function a_record_from_before_wo20_binds_nothing_is_never_retained_and_is_never_back_filled(): void
    {
        // As the pre-WO-20 schema allowed: kept access recorded with no set (written below the guards).
        $admin = DB::connection('pgsql_admin');
        $admin->transaction(function () use ($admin): void {
            $admin->statement("SET LOCAL session_replication_role = 'replica'");
            $admin->table('employment_separation_cases')->where('id', $this->case->id)
                ->update(['access_retained_by_user_id' => $this->admin->id, 'access_retained_at' => now(), 'version' => $this->case->version + 1]);
        });
        $this->assertNull($this->stored());

        $context = app(TenantContext::class);
        $this->assertSame(SeparationObligation::OPEN, $this->access(), 'unbound is never RETAINED (D-WO20-2)');
        $detail = $context->withSchool($this->school, fn () => app(SeparationCaseReadService::class)->detail($this->school, $this->hr, $this->case->id));
        $this->assertSame([false, 0, null], [$detail['accessRetention']['bound'], $detail['accessRetention']['grantCount'], $detail['accessRetention']['roles']], 'no roles to show, and none invented');
        $refusal = function (callable $call) use ($context): string {
            try {
                $context->withSchool($this->school, $call);
            } catch (SeparationException $e) {
                return $e->errorCode();
            }

            return 'none';
        };
        $version = $this->case->version + 1;
        $this->assertSame('SEPARATION_OBLIGATIONS_OPEN', $refusal(fn () => app(SeparationCaseService::class)->complete($this->school, $this->case->id, $version, $this->hr)));
        $this->assertSame('SEPARATION_ACCESS_ALREADY_RECORDED', $refusal(fn () => app(SeparationCaseService::class)->recordAccessRetained($this->school, $this->case->id, $version, $this->admin)));

        // Today's roles are never written as that record's history, even as the runtime role.
        $error = '';
        try {
            $context->withSchool($this->school, fn () => DB::table('employment_separation_cases')->where('id', $this->case->id)
                ->update(['access_retained_grant_ids' => EmploymentSeparationCase::grantIdArray($this->live()), 'version' => $version + 1]));
        } catch (QueryException $e) {
            $error = $e->getMessage();
        }
        $this->assertStringContainsString('written only with the recording (hr_separation_retained_grants_guard)', $error);
        $this->assertNull($this->stored());
    }
}

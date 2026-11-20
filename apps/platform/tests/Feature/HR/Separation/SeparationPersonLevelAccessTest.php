<?php

namespace Tests\Feature\HR\Separation;

use App\Domain\HR\Application\EmployeeLifecycleService;
use App\Domain\HR\Application\EmployeeService;
use App\Domain\HR\Application\EmploymentService;
use App\Domain\HR\Application\Exceptions\HrException;
use App\Domain\HR\Application\Separation\SeparationCaseService;
use App\Domain\HR\Application\Separation\SeparationException;
use App\Domain\HR\Application\Separation\SeparationObligation;
use App\Domain\HR\Application\Separation\SeparationObligations;
use App\Domain\HR\Application\Separation\SeparationReason;
use App\Domain\HR\Application\Separation\SeparationSubject;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmployeeAccountLink;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\HR\Infrastructure\EmploymentSeparationCase;
use App\Models\School;
use App\Models\SchoolAuditEvent;
use App\Models\SchoolMembership;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use App\Support\Testing\LocalCatalogueFixtures;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Concerns\ProvidesSensitiveActionMfa;
use Tests\Feature\HR\Concerns\CreatesLinkedHrStaff;
use Tests\TestCase;
use Throwable;

/**
 * HR.R1-C35 WO-24 (WO20-N1, RF1-N2, WO20-N7, WO18-N1; owner decision D-RF2-2, RF2 §5.3; R-HR12-3 not amended).
 *
 * Changing which account an Employee is linked to never clears its separation's School-access obligation:
 * - the obligation reads the live School grants of EVERY account with an uncorrected link (current or ended) to
 *   the Employee, in every case state (WO20-N1, WO20-N7);
 * - kept access binds the grants of every one of those accounts, so it can be recorded after an unlink, with
 *   each grant's holder named in the audit (RF2 §5.3 item 1a); a bound set lacking an older account's role reads
 *   OPEN until that role is off-boarded (D-WO20-1);
 * - a link corrected as another person's leaves the set in every state; an unlink never does (rule 145);
 * - an unlink and an employment end (the direct record of a death) refuse an actor known to be the Employee
 *   through an older or known-same account, with `HR_KNOWN_SAME_PERSON` (RF1-N2, WO18-N1); the current-link
 *   refusal `HR_SELF_ADMINISTRATION` stays first;
 * - the database refuses a completion while such an account holds a School role outside the kept set, as the
 *   runtime role, and a recorded set that omits such an account's grant (`hr_separation_retained_grants_guard`).
 *
 * Reproduced first at 5b1cf382 (a throwaway test through the services, deleted): after an unlink the case read
 * CLEAR and completed with the grant still active; an older account's grant left a kept record RETAINED; the
 * older account's unlink and deceased end were accepted; the runtime role completed raw with a live grant.
 */
class SeparationPersonLevelAccessTest extends TestCase
{
    use CreatesLinkedHrStaff, CreatesTenancyFixtures, ProvidesSensitiveActionMfa;

    private School $school;

    private User $hr;

    private User $approver;

    private User $members;

    private User $third;

    private User $subjectUser;

    private SchoolMembership $membership;

    private Employee $employee;

    private EmploymentRecord $employment;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-06-15 10:00:00');
        $this->school = $this->createSchool();
        app(TenantContext::class)->set($this->school);
        $this->hr = $this->createUserWithCapabilities($this->school, ['hr.employees.manage', 'hr.employees.assignments.manage', 'hr.separations.view', 'hr.separations.manage']);
        $this->approver = $this->createUserWithCapabilities($this->school, ['hr.separations.view', 'hr.separations.approve']);
        $this->members = $this->createUserWithCapabilities($this->school, ['school.members.manage', 'hr.separations.view']);
        $this->third = $this->createUserWithCapabilities($this->school, ['hr.employees.manage', 'hr.employees.assignments.manage']);
        $this->subjectUser = $this->createUser();
        $this->membership = $this->createMembership($this->subjectUser, $this->school);
        $this->employee = $this->createEmployee($this->school, ['user_id' => $this->subjectUser->id]);
        $this->employment = app(EmploymentService::class)->create($this->employee, ['employment_type' => 'permanent', 'starts_on' => '2022-01-01'], $this->hr);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function cases(): SeparationCaseService
    {
        return app(SeparationCaseService::class);
    }

    private function recorded(): EmploymentSeparationCase
    {
        return $this->cases()->record($this->school, $this->employment->id, SeparationReason::RESIGNATION, '2026-06-15', '2026-06-15', $this->hr);
    }

    private function approved(?EmploymentSeparationCase $case = null): EmploymentSeparationCase
    {
        $case ??= $this->recorded();

        return $this->cases()->approve($this->school, $case->id, $case->version, $this->approver, $this->freshMfaProofFor($this->approver));
    }

    private function finalized(?EmploymentSeparationCase $case = null): EmploymentSeparationCase
    {
        $case ??= $this->approved();

        return $this->cases()->finalize($this->school, $case->id, $case->version, $this->hr, $this->freshMfaProofFor($this->hr));
    }

    private function grant(SchoolMembership $membership, string $name, ?string $campusId = null): string
    {
        return LocalCatalogueFixtures::grantRole($membership, $this->createFixtureRole(['hr.employees.view'], name: $name), $campusId)->id;
    }

    private function revoke(string ...$grantIds): void
    {
        DB::table('membership_role_assignments')->whereIn('id', $grantIds)->update(['revoked_at' => now(), 'revoked_by_user_id' => $this->hr->id, 'revocation_reason' => 'revoked']);
    }

    private function membershipOf(User $user): SchoolMembership
    {
        return SchoolMembership::query()->where('school_id', $this->school->id)->where('user_id', $user->id)->sole();
    }

    private function access(EmploymentSeparationCase $case): SeparationObligation
    {
        app(TenantContext::class)->set($this->school);

        return collect(app(SeparationObligations::class)->forCase($this->school, $case->refresh()))->firstWhere('code', SeparationObligations::SCHOOL_ACCESS);
    }

    private function complete(EmploymentSeparationCase $case, ?User $as = null): string
    {
        return $this->code(fn () => $this->cases()->complete($this->school, $case->id, $case->refresh()->version, $as ?? $this->hr)->status);
    }

    private function code(callable $call): string
    {
        try {
            $result = $call();

            return is_string($result) ? $result : 'ok';
        } catch (SeparationException|HrException $e) {
            return $e->errorCode();
        } catch (AuthorizationException) {
            return 'AuthorizationException';
        } catch (Throwable $e) {
            return class_basename($e).':'.$e->getMessage();
        } finally {
            app(TenantContext::class)->set($this->school);
        }
    }

    private function unlink(User $actor, ?Employee $employee = null): string
    {
        return $this->code(function () use ($actor, $employee): string {
            app(EmployeeService::class)->unlinkUser(($employee ?? $this->employee)->fresh(), $actor);

            return 'unlinked';
        });
    }

    private function link(Employee $employee, User $user): void
    {
        app(EmployeeService::class)->linkUser($employee->fresh(), $user->id, $this->third);
        app(TenantContext::class)->set($this->school);
    }

    /**
     * An older account of the person, written through the application by a third party (rule 95): the subject
     * unlinked, the older account linked then unlinked, the subject linked again. Link history:
     * subject (ended), older (ended), subject (current) -- none corrected.
     */
    private function olderAccount(array $capabilities = []): User
    {
        $older = $this->createUserWithCapabilities($this->school, $capabilities);
        $this->assertSame('unlinked', $this->unlink($this->third));
        $this->link($this->employee, $older);
        $this->assertSame('unlinked', $this->unlink($this->third));
        $this->link($this->employee, $this->subjectUser);

        return $older;
    }

    /** An account known to be the same person as $of (rule 152): both linked, uncorrected, to another Employee. */
    private function aliasOf(User $of, array $capabilities = []): User
    {
        $alias = $this->createUserWithCapabilities($this->school, $capabilities);
        $other = $this->createEmployee($this->school, ['user_id' => null]);
        $this->link($other, $of);
        $this->assertSame('unlinked', $this->unlink($this->third, $other));
        $this->link($other, $alias);

        return $alias;
    }

    private function linkIdOf(User $user): string
    {
        return (string) EmployeeAccountLink::query()->where('employee_id', $this->employee->id)->where('user_id', $user->id)->orderBy('linked_at')->value('id');
    }

    /** @return list<array{string, string}> each link's account (by role in this test) and its state */
    private function linkHistory(User ...$named): array
    {
        $names = collect($named)->mapWithKeys(fn (User $u, int $i) => [$u->id => "account{$i}"])->all();
        $names[$this->subjectUser->id] = 'subject';

        return EmployeeAccountLink::query()->where('employee_id', $this->employee->id)->orderBy('linked_at')->get()
            ->map(fn (EmployeeAccountLink $l) => [$names[$l->user_id] ?? 'other', ($l->ended_at === null ? 'current' : 'ended').($l->voided_at === null ? '' : ',corrected')])->all();
    }

    private function raw(callable $write): string
    {
        try {
            $affected = DB::transaction($write);

            return is_int($affected) ? "ok:{$affected}" : 'ok';
        } catch (QueryException $e) {
            return preg_match('/\((hr_separation_completion_access|hr_separation_retained_grants_guard)\)/', $e->getMessage(), $m) === 1 ? $m[1] : substr($e->getMessage(), 0, 200);
        }
    }

    private function rawComplete(EmploymentSeparationCase $case): string
    {
        return $this->raw(fn () => DB::table('employment_separation_cases')->where('id', $case->id)->update([
            'status' => 'completed', 'completed_by_user_id' => $this->hr->id, 'completed_at' => now(), 'version' => DB::raw('version + 1'),
        ]));
    }

    /** @return list<string> */
    private static function sorted(string ...$ids): array
    {
        sort($ids, SORT_STRING);

        return $ids;
    }

    private function activeGrantCount(User $user): int
    {
        return DB::table('membership_role_assignments')->where('school_membership_id', $this->membershipOf($user)->id)->whereNull('revoked_at')->count();
    }

    #[Test]
    public function an_unlink_never_clears_school_access_and_completion_waits_for_off_boarding(): void
    {
        $grant = $this->grant($this->membership, 'Librarian');
        $case = $this->finalized();
        $this->assertSame(SeparationObligation::OPEN, $this->access($case)->state);

        // RF2 §5.2: the same holder of hr.employees.manage and hr.separations.manage unlinks, then completes.
        $this->assertSame('unlinked', $this->unlink($this->hr));
        $access = $this->access($case);
        $this->assertSame([SeparationObligation::OPEN, 1], [$access->state, $access->count], 'the ended, uncorrected link is still the person');
        $this->assertStringContainsString('An account of this person still holds School roles', (string) $access->detail);
        $this->assertSame('SEPARATION_OBLIGATIONS_OPEN', $this->complete($case));
        $this->assertSame(['finalized', 1, 'active'], [$case->refresh()->status, $this->activeGrantCount($this->subjectUser), $this->membership->refresh()->status]);
        $this->assertSame([['subject', 'ended']], $this->linkHistory(), 'an unlink is never a correction');

        // Off-boarding (rule 92) resolves it.
        $this->revoke($grant);
        $this->assertSame(SeparationObligation::CLEAR, $this->access($case)->state);
        $this->assertSame('completed', $this->complete($case));
    }

    #[Test]
    public function every_case_state_reads_every_account_of_the_person(): void
    {
        $older = $this->olderAccount();
        $bursar = $this->grant($this->membershipOf($older), 'Bursar');

        $case = $this->recorded();
        $this->assertSame(SeparationObligation::ATTENTION, $this->access($case)->state, 'recorded');
        $case = $this->approved($case);
        $this->assertSame(SeparationObligation::ATTENTION, $this->access($case)->state, 'approved');
        $case = $this->finalized($case);
        $this->assertSame(SeparationObligation::OPEN, $this->access($case)->state, 'finalized');
        $this->assertSame('SEPARATION_OBLIGATIONS_OPEN', $this->complete($case));

        $this->revoke($bursar);
        $this->assertSame('completed', $this->complete($case));
        $completed = $case->refresh();

        // A completed case shows a later grant as an open obligation (WO20-N5's display), never by rewriting the completion.
        $this->grant($this->membershipOf($older), 'Coach');
        $this->assertSame(SeparationObligation::OPEN, $this->access($case)->state);
        $this->assertSame(['completed', $completed->completed_by_user_id, $completed->completed_at?->toIso8601String(), $completed->version],
            [$case->refresh()->status, $case->completed_by_user_id, $case->completed_at?->toIso8601String(), $case->version]);
    }

    #[Test]
    public function a_legacy_record_still_reads_open_after_an_unlink(): void
    {
        $this->grant($this->membership, 'Librarian');
        $this->assertSame('unlinked', $this->unlink($this->third));
        $subject = new SeparationSubject($this->employment->id, $this->employee->id, null, '2026-06-15', true);

        // D-WO20-2: a record with a recorder and no set (pre-WO-20) binds nothing; the database half is
        // SeparationPersonLevelAccessConcurrencyTest's (a legacy row needs committed data below the guards).
        $access = app(SeparationObligations::class)->schoolAccess($this->school, $subject, true, null);
        $this->assertSame([SeparationObligation::OPEN, 1], [$access->state, $access->count]);
        $this->assertStringContainsString('recorded before the kept roles were stored', (string) $access->detail);
    }

    #[Test]
    public function an_older_account_holding_none_of_the_kept_grants_is_read_wo20_n7(): void
    {
        $older = $this->olderAccount();
        $librarian = $this->grant($this->membership, 'Librarian');
        $case = $this->finalized();
        $this->cases()->recordAccessRetained($this->school, $case->id, $case->version, $this->members);
        app(TenantContext::class)->set($this->school);
        $this->assertSame([$librarian], $case->refresh()->retainedGrantIds(), 'the older account held nothing when it was recorded');

        // The bound set holds the then-current account's grant only, as a record made before WO-24 does.
        $bursar = $this->grant($this->membershipOf($older), 'Bursar');
        $access = $this->access($case);
        $this->assertSame([SeparationObligation::OPEN, 2], [$access->state, $access->count]);
        $this->assertStringContainsString('not among the roles recorded as kept', (string) $access->detail);
        $this->assertSame('SEPARATION_OBLIGATIONS_OPEN', $this->complete($case));
        $this->assertSame('SEPARATION_ACCESS_ALREADY_RECORDED', $this->code(fn () => $this->cases()->recordAccessRetained($this->school, $case->id, $case->refresh()->version, $this->members)), 'never recorded again (D-WO20-1)');

        $this->revoke($bursar);
        $this->assertSame(SeparationObligation::RETAINED, $this->access($case)->state);
        $this->assertSame('completed', $this->complete($case));
    }

    #[Test]
    public function kept_access_recorded_after_an_unlink_binds_the_older_accounts_grants(): void
    {
        $librarian = $this->grant($this->membership, 'Librarian');
        $case = $this->finalized();
        $this->assertSame('unlinked', $this->unlink($this->third));
        $this->assertSame(SeparationObligation::OPEN, $this->access($case)->state);

        $case = $this->cases()->recordAccessRetained($this->school, $case->id, $case->refresh()->version, $this->members);
        app(TenantContext::class)->set($this->school);
        $this->assertSame([$librarian], $case->retainedGrantIds());
        $audit = SchoolAuditEvent::query()->where('event_type', 'hr.separation.access_retained')->sole();
        $this->assertSame([[$librarian], [$this->subjectUser->id]], [$audit->metadata['roleAssignmentIds'], $audit->metadata['holderUserIds']]);
        $this->assertSame(SeparationObligation::RETAINED, $this->access($case)->state);
        $this->assertSame('completed', $this->complete($case));
    }

    #[Test]
    public function kept_access_binds_every_account_of_the_person_and_names_each_holder(): void
    {
        $older = $this->olderAccount();
        $bursar = $this->grant($this->membershipOf($older), 'Bursar');
        $librarian = $this->grant($this->membership, 'Librarian');
        $unrelated = $this->grant($this->membershipOf($this->approver), 'Unrelated');
        $case = $this->finalized();

        $case = $this->cases()->recordAccessRetained($this->school, $case->id, $case->version, $this->members);
        app(TenantContext::class)->set($this->school);
        $this->assertSame(self::sorted($bursar, $librarian), $case->retainedGrantIds(), 'every account of the person, never an unrelated one');
        $this->assertNotContains($unrelated, $case->retainedGrantIds());
        $audit = SchoolAuditEvent::query()->where('event_type', 'hr.separation.access_retained')->sole();
        $this->assertEqualsCanonicalizing([$older->id, $this->subjectUser->id], $audit->metadata['holderUserIds']);
        $this->assertSame(SeparationObligation::RETAINED, $this->access($case)->state);

        // Same count, different set, on the older account: OPEN (WO-20's rule, now across accounts).
        $this->revoke($bursar);
        $this->grant($this->membershipOf($older), 'Bursar');
        $this->assertSame([SeparationObligation::OPEN, 2], [$this->access($case)->state, $this->access($case)->count]);
        $this->assertSame(self::sorted($bursar, $librarian), $case->refresh()->retainedGrantIds(), 'the evidence did not move');
        $this->assertSame('SEPARATION_OBLIGATIONS_OPEN', $this->complete($case));
    }

    #[Test]
    public function a_correction_releases_in_every_state_and_an_unlink_never_does(): void
    {
        $older = $this->olderAccount();
        $this->grant($this->membershipOf($older), 'Bursar');
        $case = $this->approved();
        $this->assertSame(SeparationObligation::ATTENTION, $this->access($case)->state);
        $this->assertSame([['subject', 'ended'], ['account0', 'ended'], ['subject', 'current']], $this->linkHistory($older));

        // The older account turns out to be someone else's (rule 145: two linked staff, fresh MFA).
        $this->correctLink($this->employee, $this->linkIdOf($older));
        app(TenantContext::class)->set($this->school);
        $this->assertSame([['subject', 'ended'], ['account0', 'ended,corrected'], ['subject', 'current']], $this->linkHistory($older));
        $this->assertSame(SeparationObligation::CLEAR, $this->access($case)->state, 'approved: never asks to off-board an unrelated person');
        $case = $this->finalized($case);
        $this->assertSame(SeparationObligation::CLEAR, $this->access($case)->state, 'finalized');
        $this->assertSame(1, $this->activeGrantCount($older), 'the unrelated person keeps their role');
        $this->assertSame('completed', $this->complete($case));
    }

    #[Test]
    public function grants_are_read_in_this_school_only_on_active_memberships_campus_scoped_included(): void
    {
        $older = $this->olderAccount();
        $case = $this->finalized();

        // The older account's role in ANOTHER School is not this School's access.
        $otherSchool = $this->createSchool();
        app(TenantContext::class)->withSchool($otherSchool, fn () => $this->grant($this->createMembership($older, $otherSchool), 'Elsewhere'));
        $this->assertSame(SeparationObligation::CLEAR, $this->access($case)->state);

        // A Campus-scoped School role is School staff access.
        $campus = $this->createCampus($this->school);
        $scoped = $this->grant($this->membershipOf($older), 'Campus coach', $campus->id);
        $this->assertSame([SeparationObligation::OPEN, 1], [$this->access($case)->state, $this->access($case)->count]);
        $this->assertSame('SEPARATION_OBLIGATIONS_OPEN', $this->complete($case));

        // A suspended membership holds no live access (off-boarding).
        DB::table('school_memberships')->where('id', $this->membershipOf($older)->id)->update(['status' => 'suspended']);
        $this->assertSame(SeparationObligation::CLEAR, $this->access($case)->state);
        $this->assertNotNull($scoped);
        $this->assertSame('completed', $this->complete($case));
    }

    #[Test]
    public function an_employee_never_linked_is_clear_and_says_so(): void
    {
        $employee = $this->createEmployee($this->school, ['user_id' => null]);
        $employment = app(EmploymentService::class)->create($employee, ['employment_type' => 'permanent', 'starts_on' => '2022-01-01'], $this->hr);
        $access = app(SeparationObligations::class)->schoolAccess($this->school, new SeparationSubject($employment->id, $employee->id, null, '2026-06-15', true), false);
        $this->assertSame([SeparationObligation::CLEAR, 0, 'No user account is linked to this employee.'], [$access->state, $access->count, $access->detail]);

        // Linked once, then unlinked: no longer "never linked", and clear only because that account holds no role.
        $this->assertSame('unlinked', $this->unlink($this->third));
        $access = app(SeparationObligations::class)->schoolAccess($this->school, new SeparationSubject($this->employment->id, $this->employee->id, null, '2026-06-15', true), false);
        $this->assertSame([SeparationObligation::CLEAR, null], [$access->state, $access->detail]);
    }

    #[Test]
    public function the_unlink_self_check_is_person_level(): void
    {
        $older = $this->olderAccount(['hr.employees.manage']);
        $alias = $this->aliasOf($older, ['hr.employees.manage']);
        $current = $this->createUserWithCapabilities($this->school, ['hr.employees.manage']);
        $currentEmployee = $this->createEmployee($this->school, ['user_id' => $current->id]);
        $history = $this->linkHistory($older);

        $attempted = false;
        DB::connection()->beforeExecuting(function (string $query) use (&$attempted): void {
            $attempted = $attempted || (str_starts_with(strtolower(ltrim($query)), 'update "employees"') && str_contains($query, '"user_id"'));
        });
        $this->assertSame('HR_KNOWN_SAME_PERSON', $this->unlink($older), 'an older, uncorrected account of the person (RF1-N2)');
        $this->assertSame('HR_KNOWN_SAME_PERSON', $this->unlink($alias), 'an account known to be the same person (rule 152)');
        $this->assertFalse($attempted, 'refused before any write');
        $this->assertSame('HR_SELF_ADMINISTRATION', $this->unlink($current, $currentEmployee), 'the current link keeps its own refusal first');
        $this->assertSame($history, $this->linkHistory($older));
        $this->assertSame($this->subjectUser->id, $this->employee->fresh()->user_id);

        // Once corrected as someone else's, the older account is not the person and may unlink.
        $this->correctLink($this->employee, $this->linkIdOf($older));
        app(TenantContext::class)->set($this->school);
        $this->assertSame('unlinked', $this->unlink($older));
        $this->assertNull($this->employee->fresh()->user_id);
    }

    #[Test]
    public function the_unlink_routes_refuse_a_known_same_actor_and_allow_an_independent_one(): void
    {
        $older = $this->olderAccount(['hr.employees.manage']);
        $independent = $this->createUserWithCapabilities($this->school, ['hr.employees.manage']);
        $api = fn (User $as) => $this->withHeader('Authorization', 'Bearer '.$as->createToken('test-device')->plainTextToken)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/schools/{$this->school->id}/employees/{$this->employee->id}/unlink-user");

        $api($older)->assertForbidden()->assertJsonPath('error.code', 'HR_KNOWN_SAME_PERSON');
        app(TenantContext::class)->set($this->school);
        $this->assertSame($this->subjectUser->id, $this->employee->fresh()->user_id);

        $this->actingAs($older)->post("/app/schools/{$this->school->id}/activate");
        $this->post("/app/hr/employees/{$this->employee->id}/account-link/remove")->assertSessionHasErrors('self_administration');
        app(TenantContext::class)->set($this->school);
        $this->assertSame($this->subjectUser->id, $this->employee->fresh()->user_id);

        $this->actingAs($independent)->post("/app/schools/{$this->school->id}/activate");
        $this->post("/app/hr/employees/{$this->employee->id}/account-link/remove")->assertSessionHasNoErrors()->assertRedirect();
        app(TenantContext::class)->set($this->school);
        $this->assertNull($this->employee->fresh()->user_id);
    }

    #[Test]
    public function the_deceased_direct_end_is_person_level(): void
    {
        $caps = ['hr.employees.assignments.manage'];
        $older = $this->olderAccount($caps);
        $alias = $this->aliasOf($older, $caps);
        $separate = fn (User $as, ?EmploymentRecord $employment = null) => $this->code(fn () => app(EmployeeLifecycleService::class)
            ->separate(($employment ?? $this->employment)->fresh(), '2026-06-14', $as, $this->freshMfaProofFor($as))->status);

        $this->assertSame('HR_KNOWN_SAME_PERSON', $separate($older), 'an older, uncorrected account of the person (WO18-N1)');
        $this->assertSame('HR_KNOWN_SAME_PERSON', $separate($alias), 'an account known to be the same person');
        $this->assertSame('HR_SELF_ADMINISTRATION', $separate($this->subjectUserWith($caps)), 'the current link keeps its own refusal first');
        $this->assertSame([null, 'active'], [$this->employment->fresh()->ends_on, $this->employment->fresh()->status]);

        $this->assertSame('AuthorizationException', $separate($this->createUserWithCapabilities($this->school, ['hr.employees.view'])), 'missing authority');
        $otherSchool = $this->createSchool();
        $foreign = $this->createUserWithCapabilities($otherSchool, $caps);
        $this->assertSame('AuthorizationException', $separate($foreign), 'another School\'s administrator');

        // An independent administrator records the death lawfully.
        $this->assertSame('deceased', $separate($this->third));
        $this->assertSame('deceased', $this->employment->fresh()->status);
    }

    /** The subject's own account, given the capability (it is the CURRENT link). */
    private function subjectUserWith(array $capabilities): User
    {
        LocalCatalogueFixtures::grantRole($this->membership, $this->createFixtureRole($capabilities, name: 'Subject role'));

        return $this->subjectUser;
    }

    #[Test]
    public function a_corrected_older_account_may_record_the_death(): void
    {
        $older = $this->olderAccount(['hr.employees.assignments.manage']);
        $this->correctLink($this->employee, $this->linkIdOf($older));
        app(TenantContext::class)->set($this->school);

        $this->assertSame('deceased', $this->code(fn () => app(EmployeeLifecycleService::class)
            ->separate($this->employment->fresh(), '2026-06-14', $older, $this->freshMfaProofFor($older))->status));
    }

    #[Test]
    public function the_web_death_record_refuses_a_known_same_actor_and_allows_an_independent_one(): void
    {
        $older = $this->olderAccount(['hr.employees.assignments.manage']);
        $this->actingAs($older)->post("/app/schools/{$this->school->id}/activate");
        $this->post("/app/hr/employees/{$this->employee->id}/employment-records/{$this->employment->id}/end",
            ['ends_on' => '2026-06-14', 'status' => 'deceased', 'confirm' => '1', 'mfa_code' => $this->freshMfaCode($older)])
            ->assertSessionHasErrors('self_administration');
        app(TenantContext::class)->set($this->school);
        $this->assertSame('active', $this->employment->fresh()->status);

        // An independent administrator records it through the same route (review W24-R6).
        $this->actingAs($this->third)->post("/app/schools/{$this->school->id}/activate");
        $this->post("/app/hr/employees/{$this->employee->id}/employment-records/{$this->employment->id}/end",
            ['ends_on' => '2026-06-14', 'status' => 'deceased', 'confirm' => '1', 'mfa_code' => $this->freshMfaCode($this->third)])
            ->assertSessionHasNoErrors()->assertRedirect();
        app(TenantContext::class)->set($this->school);
        $this->assertSame('deceased', $this->employment->fresh()->status);
    }

    #[Test]
    public function the_runtime_role_cannot_complete_while_an_account_of_the_person_holds_a_role(): void
    {
        $this->assertSame('school_os_app', DB::selectOne('select current_user as u')->u);
        $older = $this->olderAccount();
        $librarian = $this->grant($this->membership, 'Librarian');
        $case = $this->finalized();

        $this->assertSame('hr_separation_completion_access', $this->rawComplete($case), 'not kept, the current account holds a role');
        $this->assertSame('unlinked', $this->unlink($this->third));
        $this->assertSame('hr_separation_completion_access', $this->rawComplete($case), 'after an unlink: the ended link is still the person');
        $this->revoke($librarian);
        $bursar = $this->grant($this->membershipOf($older), 'Bursar');
        $this->assertSame('hr_separation_completion_access', $this->rawComplete($case), 'an older account\'s role');
        $this->assertSame(['finalized', 1], [$case->refresh()->status, $this->activeGrantCount($older)], 'nothing changed');

        $this->revoke($bursar);
        $this->assertSame('ok:1', $this->rawComplete($case), 'off-boarded: the positive control');
        $this->assertSame('completed', $case->refresh()->status);
    }

    #[Test]
    public function the_runtime_role_cannot_complete_outside_the_kept_set_and_a_correction_releases(): void
    {
        $older = $this->olderAccount();
        $librarian = $this->grant($this->membership, 'Librarian');
        $case = $this->finalized();
        $this->cases()->recordAccessRetained($this->school, $case->id, $case->version, $this->members);
        app(TenantContext::class)->set($this->school);
        $this->assertSame([$librarian], $case->refresh()->retainedGrantIds());
        $this->grant($this->membershipOf($older), 'Bursar');

        $this->assertSame('hr_separation_completion_access', $this->rawComplete($case), 'an older account\'s role outside the kept set');
        $this->correctLink($this->employee, $this->linkIdOf($older));
        app(TenantContext::class)->set($this->school);
        $this->assertSame('ok:1', $this->rawComplete($case), 'corrected as someone else\'s: never held to this case, and the kept role is kept');
    }

    #[Test]
    public function the_runtime_role_cannot_record_a_set_that_omits_an_account_of_the_person(): void
    {
        $older = $this->olderAccount();
        $librarian = $this->grant($this->membership, 'Librarian');
        $bursar = $this->grant($this->membershipOf($older), 'Bursar');
        $case = $this->finalized();
        $record = fn (array $ids) => fn () => DB::table('employment_separation_cases')->where('id', $case->id)->update([
            'access_retained_by_user_id' => $this->members->id, 'access_retained_at' => now(),
            'access_retained_grant_ids' => EmploymentSeparationCase::grantIdArray($ids), 'version' => DB::raw('version + 1'),
        ]);

        $this->assertSame('hr_separation_retained_grants_guard', $this->raw($record([$librarian])), 'the current account only (WO-20\'s old set)');
        $this->assertSame('hr_separation_retained_grants_guard', $this->raw($record([$bursar])), 'the older account only');
        $this->assertSame('ok:1', $this->raw($record(self::sorted($librarian, $bursar))), 'every account of the person: the positive control');
    }

    #[Test]
    public function the_database_refuses_a_completion_the_service_did_not_see(): void
    {
        $older = $this->olderAccount();
        $case = $this->finalized();
        $role = $this->createFixtureRole(['hr.employees.view'], name: 'Late role');
        $membership = $this->membershipOf($older);

        // A grant written just after the service's obligation read, inside its transaction: only the database sees it.
        $fired = false;
        DB::listen(function ($query) use (&$fired, $role, $membership): void {
            if (! $fired && str_contains($query->sql, 'from "school_memberships" as "m"') && str_contains($query->sql, 'order by "a"."id"')) {
                $fired = true;
                LocalCatalogueFixtures::grantRole($membership, $role);
            }
        });

        // The service saw no role, so it attempted the UPDATE: the refusal is the database's (review W24-R2).
        $attempted = false;
        DB::connection()->beforeExecuting(function (string $query) use (&$attempted): void {
            $attempted = $attempted || str_starts_with(strtolower(ltrim($query)), 'update "employment_separation_cases"');
        });
        $this->assertSame('SEPARATION_OBLIGATIONS_OPEN', $this->complete($case));
        $this->assertTrue($fired);
        $this->assertTrue($attempted, 'the service passed it to the database, which refused');
        $this->assertSame('finalized', $case->refresh()->status);
    }

    #[Test]
    public function the_web_completion_waits_for_an_older_accounts_access_and_then_completes(): void
    {
        $older = $this->olderAccount();
        $bursar = $this->grant($this->membershipOf($older), 'Bursar');
        $case = $this->finalized();

        $this->actingAs($this->hr)->post("/app/schools/{$this->school->id}/activate");
        $this->post("/app/hr/separations/{$case->id}/complete", ['version' => $case->version])->assertSessionHasErrors('refusal');
        // The refusal is the access obligation's, not a stale version, a state or a clearance item (review W24-R7).
        $this->assertSame('Outstanding work remains: School staff access.', session('errors')->first('refusal'));
        app(TenantContext::class)->set($this->school);
        $this->assertSame('finalized', $case->refresh()->status);

        $this->revoke($bursar);
        $this->actingAs($this->hr)->post("/app/schools/{$this->school->id}/activate");
        $this->post("/app/hr/separations/{$case->id}/complete", ['version' => $case->version])->assertSessionHasNoErrors();
        app(TenantContext::class)->set($this->school);
        $this->assertSame('completed', $case->refresh()->status);
    }

    #[Test]
    public function another_school_or_no_school_context_reaches_no_case(): void
    {
        $this->grant($this->membership, 'Librarian');
        $case = $this->finalized();
        $other = $this->createSchool();

        app(TenantContext::class)->set($other);
        $this->assertSame('ok:0', $this->rawComplete($case), 'RLS: another School\'s context sees no row (rule 28)');
        app(TenantContext::class)->clearAll();
        $this->assertSame('ok:0', $this->rawComplete($case), 'no School context: fail closed, no row');
        app(TenantContext::class)->set($this->school);
        $this->assertSame('finalized', $case->refresh()->status);
        $this->assertSame('SEPARATION_NOT_FOUND', $this->code(fn () => $this->cases()->complete($other, $case->id, $case->refresh()->version, $this->createUserWithCapabilities($other, ['hr.separations.manage']))->status));
    }
}

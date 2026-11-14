<?php

namespace Tests\Feature\HR\Separation;

use App\Domain\HR\Application\EmployeeService;
use App\Domain\HR\Application\EmploymentService;
use App\Domain\HR\Application\Separation\SeparationCaseReadService;
use App\Domain\HR\Application\Separation\SeparationCaseService;
use App\Domain\HR\Application\Separation\SeparationException;
use App\Domain\HR\Application\Separation\SeparationObligation;
use App\Domain\HR\Application\Separation\SeparationObligations;
use App\Domain\HR\Application\Separation\SeparationReason;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmployeeAccountLink;
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
use Tests\Feature\HR\Concerns\CreatesLinkedHrStaff;
use Tests\TestCase;
use Throwable;

/**
 * HR.R1-C35 WO-20 (HRC-4; owner decisions D-C35-2, D-WO20-1, D-WO20-2).
 *
 * "Access kept" binds the SET of the account's live School role grants at the moment it is recorded:
 * - the stored set is exactly those grants, read by the server under Identity's School access lock;
 * - RETAINED only while every live grant is in the set -- a new role, or a revoke-then-grant with the same
 *   count, returns the obligation to OPEN and blocks completion; a kept role since revoked leaves the rest kept;
 * - the set never changes afterwards (role rename, revocation, suspension, disabling, relinking) and is never
 *   recorded again (D-WO20-1: off-boarding resolves it);
 * - a record made before WO-20 binds nothing and is never RETAINED (D-WO20-2), and can never be back-filled
 *   (SeparationRetainedAccessConcurrencyTest, which can write such a row below the guards on committed data);
 * - the database re-reads the set and refuses a forged, partial, padded, other-School, rewritten or inserted one.
 *
 * Reproduced first at 31a14420 (a throwaway test, deleted): after "kept" was recorded, a role granted later
 * still read RETAINED and completion succeeded; a revoke-then-grant (same count) read RETAINED too.
 */
class SeparationRetainedAccessBindingTest extends TestCase
{
    use CreatesLinkedHrStaff, CreatesTenancyFixtures;

    private School $school;

    private User $hr;

    private User $members;

    private User $subjectUser;

    private SchoolMembership $membership;

    private EmploymentSeparationCase $case;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-06-15 10:00:00');
        $this->school = $this->createSchool();
        app(TenantContext::class)->set($this->school);
        $this->hr = $this->createUserWithCapabilities($this->school, ['hr.employees.assignments.manage', 'hr.separations.view', 'hr.separations.manage']);
        $approver = $this->createUserWithCapabilities($this->school, ['hr.separations.view', 'hr.separations.approve']);
        $this->members = $this->createUserWithCapabilities($this->school, ['school.members.manage', 'hr.separations.view']);
        $this->subjectUser = $this->createUser();
        $this->membership = $this->createMembership($this->subjectUser, $this->school);
        $employee = $this->createEmployee($this->school, ['user_id' => $this->subjectUser->id]);
        $employment = app(EmploymentService::class)->create($employee, ['employment_type' => 'permanent', 'starts_on' => '2022-01-01'], $this->hr);

        $cases = app(SeparationCaseService::class);
        $case = $cases->record($this->school, $employment->id, SeparationReason::RESIGNATION, '2026-06-15', '2026-06-15', $this->hr);
        $case = $cases->approve($this->school, $case->id, $case->version, $approver, $this->freshMfaProofFor($approver));
        $this->case = $cases->finalize($this->school, $case->id, $case->version, $this->hr, $this->freshMfaProofFor($this->hr));
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

    private function grant(string $name, ?SchoolMembership $membership = null): string
    {
        return LocalCatalogueFixtures::grantRole($membership ?? $this->membership, $this->createFixtureRole(['hr.employees.view'], name: $name))->id;
    }

    private function revoke(string $grantId): void
    {
        DB::table('membership_role_assignments')->where('id', $grantId)->update(['revoked_at' => now(), 'revoked_by_user_id' => $this->hr->id, 'revocation_reason' => 'revoked']);
    }

    private function access(): SeparationObligation
    {
        return collect(app(SeparationObligations::class)->forCase($this->school, $this->case->refresh()))->firstWhere('code', SeparationObligations::SCHOOL_ACCESS);
    }

    private function keep(): void
    {
        $this->case = $this->cases()->recordAccessRetained($this->school, $this->case->id, $this->case->refresh()->version, $this->members);
    }

    private function completion(): string
    {
        try {
            $this->cases()->complete($this->school, $this->case->id, $this->case->refresh()->version, $this->hr);

            return 'completed';
        } catch (SeparationException $e) {
            return $e->errorCode();
        }
    }

    private function raw(callable $write): string
    {
        try {
            DB::transaction($write);

            return 'ok';
        } catch (QueryException $e) {
            return preg_match('/\((hr_separation_retained_grants_guard|hr_employment_separation_cases_guard)\)|retained_grants_shape|retained_pair/', $e->getMessage(), $m) === 1 ? $m[0] : substr($e->getMessage(), 0, 160);
        }
    }

    /** @return list<string> */
    private static function sorted(string ...$ids): array
    {
        sort($ids, SORT_STRING);

        return $ids;
    }

    #[Test]
    public function the_recording_binds_exactly_the_live_role_set_read_by_the_server(): void
    {
        $a = $this->grant('Librarian');
        $b = $this->grant('Coach');
        $this->keep();

        $this->assertSame(self::sorted($a, $b), $this->case->retainedGrantIds());
        $this->assertSame(SeparationObligation::RETAINED, $this->access()->state);
        $audit = SchoolAuditEvent::query()->where('event_type', 'hr.separation.access_retained')->sole();
        $this->assertSame(self::sorted($a, $b), $audit->metadata['roleAssignmentIds'], 'the audit names the grants, never a person');

        $read = app(SeparationCaseReadService::class)->detail($this->school, $this->members, $this->case->id);
        $this->assertTrue($read['accessRetention']['bound']);
        $this->assertSame(['Coach', 'Librarian'], array_column($read['accessRetention']['roles'], 'roleName'));
        $this->assertSame([null, null], array_column($read['accessRetention']['roles'], 'campus'), 'whole-School grants');
        $this->assertSame('completed', $this->completion());
    }

    #[Test]
    public function a_role_granted_after_the_recording_is_not_kept_and_blocks_completion_until_off_boarded(): void
    {
        $this->grant('Librarian');
        $this->keep();
        $later = $this->grant('Coach');

        $access = $this->access();
        $this->assertSame([SeparationObligation::OPEN, 2], [$access->state, $access->count]);
        $this->assertStringContainsString('not among the roles recorded as kept', (string) $access->detail);
        $this->assertSame('SEPARATION_OBLIGATIONS_OPEN', $this->completion());
        $this->assertSame('SEPARATION_ACCESS_ALREADY_RECORDED', $this->refusal(fn () => $this->keep()), 'never recorded again (D-WO20-1)');

        $this->revoke($later); // off-boarding the role that was not kept
        $this->assertSame(SeparationObligation::RETAINED, $this->access()->state);
        $this->assertSame('completed', $this->completion());
    }

    #[Test]
    public function the_same_count_with_a_different_set_is_not_the_kept_set(): void
    {
        $a = $this->grant('Librarian');
        $b = $this->grant('Coach');
        $this->keep();
        $this->revoke($a);
        $this->grant('Librarian'); // a re-grant is a new grant: still two roles, not the same two grants

        $this->assertSame([SeparationObligation::OPEN, 2], [$this->access()->state, $this->access()->count]);
        $this->assertSame(self::sorted($a, $b), $this->case->refresh()->retainedGrantIds(), 'the evidence did not move');
        $this->assertSame('SEPARATION_OBLIGATIONS_OPEN', $this->completion());
    }

    #[Test]
    public function later_changes_never_rewrite_the_kept_set(): void
    {
        $a = $this->grant('Librarian');
        $b = $this->grant('Coach');
        $this->keep();
        $bound = $this->case->retainedGrantIds();

        // A kept role revoked: the rest stays kept, and the reader marks it.
        $this->revoke($a);
        $this->assertSame([SeparationObligation::RETAINED, 1], [$this->access()->state, $this->access()->count]);
        $roles = collect(app(SeparationCaseReadService::class)->detail($this->school, $this->members, $this->case->id)['accessRetention']['roles'])->keyBy('roleName');
        $this->assertTrue($roles['Librarian']['revoked']);
        $this->assertFalse($roles['Coach']['revoked']);

        // A role renamed (the catalogue's own seam): the evidence names grants, so it is unchanged; the reader shows today's name.
        $roleId = DB::table('membership_role_assignments')->where('id', $b)->value('role_id');
        LocalCatalogueFixtures::asOwner(fn () => DB::table('roles')->where('id', $roleId)->update(['name' => 'Sports coach']));
        $this->assertContains('Sports coach', array_column(app(SeparationCaseReadService::class)->detail($this->school, $this->members, $this->case->id)['accessRetention']['roles'], 'roleName'));

        // The membership suspended, the account disabled.
        DB::table('school_memberships')->where('id', $this->membership->id)->update(['status' => 'suspended']);
        $this->assertSame(SeparationObligation::CLEAR, $this->access()->state, 'no live access');
        DB::table('users')->where('id', $this->subjectUser->id)->update(['is_disabled' => true]);

        $this->assertSame($bound, $this->case->refresh()->retainedGrantIds());
        $this->assertSame(self::sorted($a, $b), $bound);
    }

    #[Test]
    public function a_relinked_employee_brings_an_account_whose_roles_were_never_kept(): void
    {
        $this->grant('Librarian');
        $this->keep();
        $other = $this->createUser();
        $this->grant('Coach', $this->createMembership($other, $this->school));
        $employeeId = DB::table('employment_records')->where('id', $this->case->employment_record_id)->value('employee_id');
        Employee::query()->whereKey($employeeId)->update(['user_id' => $other->id]);

        $this->assertSame(SeparationObligation::OPEN, $this->access()->state);
    }

    #[Test]
    public function no_role_no_account_and_no_authority_record_nothing(): void
    {
        $this->assertSame('SEPARATION_NO_ACTIVE_ACCESS', $this->refusal(fn () => $this->keep()), 'a confirmed empty set is not kept access');

        $this->grant('Librarian');
        $viewer = $this->createUserWithCapabilities($this->school, ['hr.separations.view']);
        $this->assertSame('AuthorizationException', $this->refusal(fn () => $this->cases()->recordAccessRetained($this->school, $this->case->id, $this->case->refresh()->version, $viewer)));
        $other = $this->createSchool();
        $this->assertSame('SEPARATION_NOT_FOUND', $this->refusal(fn () => $this->cases()->recordAccessRetained($other, $this->case->id, $this->case->refresh()->version, $this->createUserWithCapabilities($other, ['school.members.manage']))));
        app(TenantContext::class)->set($this->school);

        $employeeId = DB::table('employment_records')->where('id', $this->case->employment_record_id)->value('employee_id');
        Employee::query()->whereKey($employeeId)->update(['user_id' => null]);
        $this->assertSame('SEPARATION_NO_ACTIVE_ACCESS', $this->refusal(fn () => $this->keep()), 'no linked account, nothing to keep');
        $this->assertNull($this->case->refresh()->access_retained_at);
    }

    #[Test]
    public function a_set_that_changes_under_the_recording_is_refused_and_nothing_is_recorded(): void
    {
        $this->grant('Librarian');
        $role = $this->createFixtureRole(['hr.employees.view'], name: 'Coach');
        // Inject a grant right after the service read the set, inside its transaction: the database re-reads it.
        // The first read of the set is the obligation's; the second is the capture the recording binds.
        $fired = false;
        $reads = 0;
        DB::listen(function ($query) use (&$fired, &$reads, $role): void {
            if (! $fired && str_contains($query->sql, 'from "school_memberships" as "m"') && str_contains($query->sql, 'order by "a"."id"') && ++$reads === 2) {
                $fired = true;
                LocalCatalogueFixtures::grantRole($this->membership, $role);
            }
        });

        $this->assertSame('SEPARATION_ACCESS_CHANGED', $this->refusal(fn () => $this->keep()));
        $this->assertTrue($fired);
        $case = $this->case->refresh();
        $this->assertSame([null, null], [$case->access_retained_at, $case->retainedGrantIds()], 'no recording without its evidence');
    }

    #[Test]
    public function the_web_form_cannot_supply_the_set(): void
    {
        $a = $this->grant('Librarian');
        $forged = (string) Str::uuid();
        $this->actingAs($this->members)->post("/app/schools/{$this->school->id}/activate");
        $this->post("/app/hr/separations/{$this->case->id}/access-retained", ['version' => $this->case->version, 'confirm' => '1', 'access_retained_grant_ids' => [$forged], 'grant_ids' => [$forged]])
            ->assertSessionHasNoErrors();

        app(TenantContext::class)->set($this->school); // the request cleared the context
        $this->assertSame([$a], $this->case->refresh()->retainedGrantIds(), 'the server read the set; the posted ids were ignored');
    }

    #[Test]
    public function the_runtime_role_cannot_forge_rewrite_or_pre_set_the_kept_set(): void
    {
        $a = $this->grant('Librarian');
        $b = $this->grant('Coach');
        $otherSchool = $this->createSchool();
        $foreign = app(TenantContext::class)->withSchool($otherSchool, fn () => $this->grant('Bursar', $this->createMembership($this->createUser(), $otherSchool)));
        app(TenantContext::class)->set($this->school);
        $record = fn (array $ids) => fn () => DB::table('employment_separation_cases')->where('id', $this->case->id)->update([
            'access_retained_by_user_id' => $this->members->id, 'access_retained_at' => now(),
            'access_retained_grant_ids' => EmploymentSeparationCase::grantIdArray($ids), 'version' => DB::raw('version + 1'),
        ]);
        $guard = '(hr_separation_retained_grants_guard)';

        $this->assertSame($guard, $this->raw($record([$a])), 'a partial set');
        $this->assertSame($guard, $this->raw($record(self::sorted($a, $b, $foreign))), 'padded with another School\'s grant');
        $this->assertSame($guard, $this->raw($record([$foreign])), 'another School\'s grant');
        $this->assertSame($guard, $this->raw($record(array_reverse(self::sorted($a, $b)))), 'not the canonical order');
        $this->assertSame($guard, $this->raw($record([...self::sorted($a, $b), $a])), 'a duplicate');
        $this->assertSame($guard, $this->raw(fn () => DB::table('employment_separation_cases')->where('id', $this->case->id)->update([
            'access_retained_grant_ids' => '{}', 'version' => DB::raw('version + 1')])), 'an empty set without a recording');
        $this->assertSame('ok', $this->raw($record(self::sorted($a, $b))), 'the exact live set is the positive control');
        $this->assertSame($guard, $this->raw(fn () => DB::table('employment_separation_cases')->where('id', $this->case->id)
            ->update(['access_retained_grant_ids' => EmploymentSeparationCase::grantIdArray([$a]), 'version' => DB::raw('version + 1')])), 'rewritten afterwards');
        $this->assertSame($guard, $this->raw(fn () => DB::table('employment_separation_cases')->where('id', $this->case->id)
            ->update(['access_retained_grant_ids' => null, 'version' => DB::raw('version + 1')])), 'erased afterwards');
        $this->assertSame(self::sorted($a, $b), $this->case->refresh()->retainedGrantIds());
    }

    #[Test]
    public function only_a_viewer_who_may_see_role_assignments_sees_the_kept_roles(): void
    {
        $this->grant('Librarian');
        $this->keep();
        $hrOfficer = $this->hr; // hr.separations.view, but neither school.roles.view nor school.members.manage
        $roles = $this->createUserWithCapabilities($this->school, ['hr.separations.view', 'school.roles.view']);
        $read = fn (User $viewer) => app(SeparationCaseReadService::class)->detail($this->school, $viewer, $this->case->id)['accessRetention'];

        $this->assertSame([true, 1, null], [$read($hrOfficer)['bound'], $read($hrOfficer)['grantCount'], $read($hrOfficer)['roles']], 'a count, never which roles');
        $this->assertSame(['Librarian'], array_column($read($roles)['roles'], 'roleName'));
        $this->assertSame(['Librarian'], array_column($read($this->members)['roles'], 'roleName'));
    }

    #[Test]
    public function unlinking_or_relinking_the_employee_never_clears_kept_access_with_an_extra_role(): void
    {
        $this->grant('Librarian');
        $this->keep();
        $this->grant('Coach'); // outside the kept set, on the same account
        $employeeId = DB::table('employment_records')->where('id', $this->case->employment_record_id)->value('employee_id');

        Employee::query()->whereKey($employeeId)->update(['user_id' => null]);
        $this->assertSame(SeparationObligation::OPEN, $this->access()->state, 'the kept grants\' holder still has an extra role');
        $this->assertSame('SEPARATION_OBLIGATIONS_OPEN', $this->completion());

        Employee::query()->whereKey($employeeId)->update(['user_id' => $this->createUser()->id]); // an account with no School role
        $this->assertSame(SeparationObligation::OPEN, $this->access()->state);

        // The extra role off-boarded: the holder's grants are within the set again.
        DB::table('membership_role_assignments')->where('school_membership_id', $this->membership->id)->whereNotIn('id', $this->case->refresh()->retainedGrantIds())
            ->update(['revoked_at' => now(), 'revoked_by_user_id' => $this->hr->id, 'revocation_reason' => 'revoked']);
        $this->assertSame(SeparationObligation::RETAINED, $this->access()->state);
    }

    #[Test]
    public function an_account_corrected_as_someone_else_is_never_held_to_the_kept_set(): void
    {
        $this->grant('Librarian');
        $this->keep();
        $employee = Employee::query()->findOrFail(DB::table('employment_records')->where('id', $this->case->employment_record_id)->value('employee_id'));
        $requester = $this->linkedHrStaff($this->school);
        $reviewer = $this->linkedHrStaff($this->school);
        app(EmployeeService::class)->unlinkUser($employee->fresh(), $requester);
        $link = EmployeeAccountLink::query()->where('employee_id', $employee->id)->where('user_id', $this->subjectUser->id)->sole();
        $this->approveCorrection($this->school, $this->requestCorrection($employee, $link->id, $requester), $reviewer);
        app(TenantContext::class)->set($this->school);

        // The account turned out to be someone else's; it keeps working and gains an unrelated role.
        $this->grant('Coach');
        $this->assertSame(SeparationObligation::CLEAR, $this->access()->state, 'never asks to off-board an unrelated person (delta review M-A)');
    }

    #[Test]
    public function the_recording_time_and_recorder_are_evidence_too(): void
    {
        $a = $this->grant('Librarian');
        $guard = '(hr_separation_retained_grants_guard)';
        $this->assertSame('retained_pair', $this->raw(fn () => DB::table('employment_separation_cases')->where('id', $this->case->id)
            ->update(['access_retained_at' => now(), 'version' => DB::raw('version + 1')])), 'a time without a recorder');
        $this->assertSame($guard, $this->raw(fn () => DB::table('employment_separation_cases')->where('id', $this->case->id)
            ->update(['access_retained_by_user_id' => $this->members->id, 'access_retained_at' => now(), 'version' => DB::raw('version + 1')])), 'a recording without its set');

        $this->keep();
        $this->assertSame($guard, $this->raw(fn () => DB::table('employment_separation_cases')->where('id', $this->case->id)
            ->update(['access_retained_at' => now()->subDay(), 'version' => DB::raw('version + 1')])), 'the time rewritten');
        $this->assertSame([$a], $this->case->refresh()->retainedGrantIds());
    }

    private function refusal(callable $call): string
    {
        try {
            $call();
        } catch (SeparationException $e) {
            return $e->errorCode();
        } catch (AuthorizationException) {
            return 'AuthorizationException';
        } catch (Throwable $e) {
            return class_basename($e).':'.$e->getMessage();
        }
        $this->fail('expected a refusal');
    }
}

<?php

namespace Tests\Feature\HR;

use App\Domain\HR\Application\AccountLinks\EmployeeAccountLinkCorrectionService;
use App\Domain\HR\Application\AccountLinks\EmployeeAccountLinkHistory;
use App\Domain\HR\Application\AccountLinks\EmployeeAccountLinkVoidReason;
use App\Domain\HR\Application\EmployeeService;
use App\Domain\HR\Application\Exceptions\EmployeeAccountLinkAlreadyCorrectedException;
use App\Domain\HR\Application\Exceptions\EmployeeAccountLinkCorrectionAlreadyDecidedException;
use App\Domain\HR\Application\Exceptions\EmployeeAccountLinkCorrectionAlreadyRequestedException;
use App\Domain\HR\Application\Exceptions\EmployeeAccountLinkCorrectionNotIndependentException;
use App\Domain\HR\Application\Exceptions\EmployeeAccountLinkCorrectionNotQualifiedException;
use App\Domain\HR\Application\Exceptions\EmployeeAccountLinkCorrectionStaleException;
use App\Domain\HR\Application\Exceptions\HrSelfAdministrationException;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmployeeAccountLink;
use App\Domain\HR\Infrastructure\EmployeeAccountLinkCorrection;
use App\Models\School;
use App\Models\SchoolAuditEvent;
use App\Models\User;
use App\Support\Auth\Mfa\FreshMfaProof;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Concerns\ProvidesSensitiveActionMfa;
use Tests\Feature\HR\Concerns\CreatesLinkedHrStaff;
use Tests\TestCase;

/**
 * HR.R1-C8 (OD-HR-25 as amended by the owner): a link is corrected by two linked staff,
 * and nothing is effective until the second approves. Reproduced at c4ea0307: a
 * never-linked HR account cleared its owner's link alone. Two accounts are never proof
 * of two people: these tests prove separation of duties and no more than that.
 */
class EmployeeAccountLinkCorrectionTest extends TestCase
{
    use CreatesLinkedHrStaff, CreatesTenancyFixtures, ProvidesSensitiveActionMfa;

    private School $school;

    private User $requester;

    private User $reviewer;

    private Employee $employee;

    private User $linked;

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = $this->createSchool();
        $this->requester = $this->linkedHrStaff($this->school);
        $this->reviewer = $this->linkedHrStaff($this->school);
        $this->employee = $this->createEmployee($this->school, ['user_id' => null]);
        $this->linked = $this->createUser();
        $this->createMembership($this->linked, $this->school);
        $this->in(fn () => app(EmployeeService::class)->linkUser($this->employee->fresh(), $this->linked->id, $this->requester));
        $this->in(fn () => app(EmployeeService::class)->unlinkUser($this->employee->fresh(), $this->requester));
    }

    private function in(callable $fn): mixed
    {
        return app(TenantContext::class)->withSchool($this->school, $fn);
    }

    private function link(): EmployeeAccountLink
    {
        return $this->in(fn () => EmployeeAccountLink::query()->where('employee_id', $this->employee->id)->orderByDesc('linked_at')->firstOrFail());
    }

    private function correction(?User $by = null): EmployeeAccountLinkCorrection
    {
        return $this->requestCorrection($this->employee, $this->link()->id, $by ?? $this->requester);
    }

    #[Test]
    public function a_request_changes_nothing_and_an_approval_marks_the_row_and_keeps_it(): void
    {
        $correction = $this->correction();

        $this->assertNull($this->link()->voided_at, 'a request alone never voids');
        $this->assertSame('requested', $correction->status);

        $this->approveCorrection($this->school, $correction, $this->reviewer);

        $link = $this->link();
        $this->assertNotNull($link->voided_at);
        $this->assertSame($this->reviewer->id, $link->voided_by_user_id);
        $this->assertSame('wrong_person', $link->void_reason);
        $this->assertSame($this->linked->id, $link->user_id, 'what was recorded stays');
        $row = $this->in(fn () => $correction->fresh());
        $this->assertSame('approved', $row->status);
        $this->assertSame($this->requester->id, $row->requested_by_user_id);
        $this->assertSame($this->reviewer->id, $row->decided_by_user_id);
    }

    #[Test]
    public function a_refusal_leaves_the_link_counting_and_a_new_request_is_possible(): void
    {
        $this->refuseCorrection($this->school, $this->correction(), $this->reviewer);

        $this->assertNull($this->link()->voided_at);
        $this->assertSame('requested', $this->correction()->status);
    }

    #[Test]
    public function an_approval_of_a_current_link_unlinks_it_through_the_one_writer_and_audits_with_provenance(): void
    {
        $other = $this->createUser();
        $this->createMembership($other, $this->school);
        $this->in(fn () => app(EmployeeService::class)->linkUser($this->employee->fresh(), $other->id, $this->requester));

        $this->approveCorrection($this->school, $this->correction(), $this->reviewer);

        $this->assertNull($this->in(fn () => $this->employee->fresh()->user_id));
        $link = $this->link();
        $this->assertSame($this->reviewer->id, $link->ended_by_user_id);
        $this->assertNotNull($link->voided_at);
        $audits = $this->in(fn () => SchoolAuditEvent::query()->where('event_type', 'employee.user_link_corrected')->where('subject_id', $this->employee->id)->get());
        $this->assertCount(1, $audits);
        $this->assertSame($this->reviewer->id, $audits[0]->actor_user_id);
        $this->assertTrue($audits[0]->metadata['wasCurrent']);
        $this->assertSame([$this->requester->id], $audits[0]->metadata['participants']['requester']);
        $this->assertTrue($audits[0]->metadata['freshMfa']);
        $this->assertSame(1, $this->in(fn () => SchoolAuditEvent::query()->where('event_type', 'employee.user_link_correction_requested')->where('subject_id', $this->employee->id)->count()));
    }

    #[Test]
    public function the_requester_never_decides(): void
    {
        $correction = $this->correction();

        try {
            $this->approveCorrection($this->school, $correction, $this->requester);
            $this->fail('self-approved');
        } catch (EmployeeAccountLinkCorrectionNotIndependentException) {
            $this->assertNull($this->link()->voided_at);
        }
        $this->expectException(EmployeeAccountLinkCorrectionNotIndependentException::class);
        $this->refuseCorrection($this->school, $correction, $this->requester);
    }

    #[Test]
    public function a_never_linked_hr_account_takes_no_part_which_closes_the_reproduced_path(): void
    {
        $secondAccount = $this->fullHrActor($this->school);

        try {
            $this->correction($secondAccount);
            $this->fail('a never-linked account requested');
        } catch (EmployeeAccountLinkCorrectionNotQualifiedException) {
            $this->assertSame(0, $this->in(fn () => EmployeeAccountLinkCorrection::query()->count()));
        }

        $this->expectException(EmployeeAccountLinkCorrectionNotQualifiedException::class);
        $this->approveCorrection($this->school, $this->correction(), $secondAccount);
    }

    #[Test]
    public function staff_whose_employment_ended_or_whose_employee_is_archived_do_not_qualify(): void
    {
        $leaver = $this->createUserWithCapabilities($this->school, ['hr.employees.manage']);
        $own = $this->createEmployee($this->school, ['user_id' => $leaver->id]);
        $this->createEmploymentRecord($own, ['status' => 'separated', 'starts_on' => '2014-01-01', 'ends_on' => '2015-01-01']);

        $this->expectException(EmployeeAccountLinkCorrectionNotQualifiedException::class);
        $this->correction($leaver);
    }

    #[Test]
    public function a_person_ever_linked_to_the_employee_takes_no_part_even_after_their_link_was_corrected(): void
    {
        $this->assertSame('approved', $this->approveCorrection($this->school, $this->correction(), $this->reviewer)->status);
        $hr = $this->linkedHrStaff($this->school);
        // That account's link to THIS Employee was corrected away, and it still takes no part.
        $this->in(fn () => DB::statement('UPDATE employees SET user_id = NULL WHERE user_id = ?', [$hr->id]));
        $this->in(fn () => DB::statement('UPDATE employees SET user_id = ? WHERE id = ?', [$hr->id, $this->employee->id]));
        $this->in(fn () => DB::statement('UPDATE employees SET user_id = NULL WHERE id = ?', [$this->employee->id]));
        $next = $this->createUser();
        $this->createMembership($next, $this->school);
        $this->in(fn () => app(EmployeeService::class)->linkUser($this->employee->fresh(), $next->id, $this->requester));

        $this->expectException(HrSelfAdministrationException::class);
        $this->correction($hr);
    }

    #[Test]
    public function a_second_account_known_to_be_the_requester_never_decides(): void
    {
        // Both accounts hold uncorrected links to one Employee: as far as anything here can know, one person.
        $shared = $this->createEmployee($this->school, ['user_id' => null]);
        foreach ([$this->reviewer, $this->requester] as $account) {
            $own = $this->in(fn () => DB::table('employees')->where('user_id', $account->id)->value('id'));
            $this->in(function () use ($account, $own, $shared): void {
                DB::statement('UPDATE employees SET user_id = NULL WHERE id = ?', [$own]);
                DB::statement('UPDATE employees SET user_id = ? WHERE id = ?', [$account->id, $shared->id]);
                DB::statement('UPDATE employees SET user_id = NULL WHERE id = ?', [$shared->id]);
                DB::statement('UPDATE employees SET user_id = ? WHERE id = ?', [$account->id, $own]);
            });
        }
        $correction = $this->correction();

        $this->expectException(EmployeeAccountLinkCorrectionNotIndependentException::class);
        $this->approveCorrection($this->school, $correction, $this->reviewer);
    }

    /** $account holds an uncorrected ended link to the Employee $staff is currently linked to. */
    private function makeAliasOf(User $staff, User $account): void
    {
        $this->in(function () use ($staff, $account): void {
            $own = DB::table('employees')->where('user_id', $staff->id)->value('id');
            DB::statement('UPDATE employees SET user_id = NULL WHERE id = ?', [$own]);
            DB::statement('UPDATE employees SET user_id = ? WHERE id = ?', [$account->id, $own]);
            DB::statement('UPDATE employees SET user_id = NULL WHERE id = ?', [$own]);
            DB::statement('UPDATE employees SET user_id = ? WHERE id = ?', [$staff->id, $own]);
        });
    }

    #[Test]
    public function a_known_alias_of_the_corrected_account_never_decides(): void
    {
        $correction = $this->correction();
        $this->makeAliasOf($this->reviewer, $this->linked);

        // The history changed too, so refuse is the honest test of who may act at all.
        $this->expectException(EmployeeAccountLinkCorrectionNotIndependentException::class);
        $this->refuseCorrection($this->school, $correction, $this->reviewer);
    }

    #[Test]
    public function a_known_alias_of_the_corrected_account_never_requests(): void
    {
        $this->makeAliasOf($this->requester, $this->linked);

        try {
            $this->correction();
            $this->fail('an alias of the corrected account requested');
        } catch (EmployeeAccountLinkCorrectionNotIndependentException) {
            $this->assertSame(0, $this->in(fn () => EmployeeAccountLinkCorrection::query()->count()));
        }
        $this->assertFalse($this->in(fn () => app(EmployeeAccountLinkHistory::class)->forEmployee($this->employee->fresh(), $this->requester))[0]['mayRequestCorrection']);
    }

    #[Test]
    public function a_decision_under_another_employee_is_not_found(): void
    {
        $correction = $this->correction();
        $other = $this->createEmployee($this->school, ['user_id' => null]);

        $this->expectException(ModelNotFoundException::class);
        $this->in(fn () => app(EmployeeAccountLinkCorrectionService::class)->approve($other->fresh(), $correction->id, $correction->evidence_version, $this->reviewer, new FreshMfaProof($this->reviewer->id, CarbonImmutable::now())));
    }

    #[Test]
    public function a_revoked_reviewer_cannot_finish(): void
    {
        $correction = $this->correction();
        $revoked = $this->in(fn () => DB::table('membership_role_assignments')->whereIn('school_membership_id', DB::table('school_memberships')->where('user_id', $this->reviewer->id)->select('id'))
            ->update(['revoked_at' => now(), 'revoked_by_user_id' => $this->requester->id, 'revocation_reason' => 'revoked']));
        $this->assertGreaterThan(0, $revoked);

        $this->expectException(AuthorizationException::class);
        $this->approveCorrection($this->school, $correction, $this->reviewer);
    }

    #[Test]
    public function a_stale_request_can_only_be_refused(): void
    {
        $correction = $this->correction();
        $version = $correction->evidence_version;
        $new = $this->createUser();
        $this->createMembership($new, $this->school);
        $this->in(fn () => app(EmployeeService::class)->linkUser($this->employee->fresh(), $new->id, $this->requester));

        try {
            $this->approveCorrection($this->school, $correction, $this->reviewer);
            $this->fail('approved over a changed history');
        } catch (EmployeeAccountLinkCorrectionStaleException) {
            $this->assertNull($this->in(fn () => EmployeeAccountLink::query()->findOrFail($correction->account_link_id))->voided_at);
        }
        $history = $this->in(fn () => app(EmployeeAccountLinkHistory::class)->forEmployee($this->employee->fresh(), $this->reviewer));
        $this->assertTrue(collect($history)->firstWhere('id', $correction->account_link_id)['correctionRequest']['stale']);
        $this->assertNotSame($version, collect($history)->firstWhere('id', $correction->account_link_id)['correctionRequest']['evidenceVersion']);

        $this->assertSame('refused', $this->refuseCorrection($this->school, $correction, $this->reviewer)->status);
    }

    #[Test]
    public function an_approval_naming_evidence_it_did_not_review_is_stale(): void
    {
        $this->expectException(EmployeeAccountLinkCorrectionStaleException::class);
        $this->approveCorrection($this->school, $this->correction(), $this->reviewer, str_repeat('a', 64));
    }

    #[Test]
    public function an_ordinary_unlink_of_the_target_does_not_make_a_request_stale(): void
    {
        $other = $this->createUser();
        $this->createMembership($other, $this->school);
        $this->in(fn () => app(EmployeeService::class)->linkUser($this->employee->fresh(), $other->id, $this->requester));
        $correction = $this->correction();
        $this->in(fn () => app(EmployeeService::class)->unlinkUser($this->employee->fresh(), $this->requester));

        $this->assertSame('approved', $this->approveCorrection($this->school, $correction, $this->reviewer)->status);
    }

    #[Test]
    public function one_open_request_per_link_and_a_decision_is_final(): void
    {
        $correction = $this->correction();

        try {
            $this->correction();
            $this->fail('two open requests');
        } catch (EmployeeAccountLinkCorrectionAlreadyRequestedException) {
            $this->addToAssertionCount(1);
        }

        $this->approveCorrection($this->school, $correction, $this->reviewer);
        try {
            $this->approveCorrection($this->school, $correction, $this->reviewer);
            $this->fail('applied twice');
        } catch (EmployeeAccountLinkCorrectionAlreadyDecidedException) {
            $this->assertSame(1, $this->in(fn () => SchoolAuditEvent::query()->where('event_type', 'employee.user_link_corrected')->count()));
        }
        $this->expectException(EmployeeAccountLinkAlreadyCorrectedException::class);
        $this->correction();
    }

    #[Test]
    public function the_decider_needs_their_own_proof_and_whole_school_authority(): void
    {
        $correction = $this->correction();
        try {
            $this->in(fn () => app(EmployeeAccountLinkCorrectionService::class)->approve($this->employee->fresh(), $correction->id, $correction->evidence_version, $this->reviewer, new FreshMfaProof($this->requester->id, CarbonImmutable::now())));
            $this->fail('someone else\'s code');
        } catch (\LogicException) {
            $this->assertNull($this->link()->voided_at);
        }

        $payroll = $this->linkedHrStaff($this->school, ['payroll.runs.approve', 'payroll.compensation.sensitive.view', 'payroll.runs.reverse', 'payroll.runs.post']);
        $this->expectException(AuthorizationException::class);
        $this->approveCorrection($this->school, $correction, $payroll);
    }

    #[Test]
    public function a_one_administrator_school_waits_and_the_conflict_stands(): void
    {
        $school = $this->createSchool();
        $only = $this->linkedHrStaff($school);
        $employee = $this->createEmployee($school, ['user_id' => null]);
        $person = $this->createUser();
        $this->createMembership($person, $school);
        app(TenantContext::class)->withSchool($school, function () use ($employee, $person, $only) {
            app(EmployeeService::class)->linkUser($employee->fresh(), $person->id, $only);
            app(EmployeeService::class)->unlinkUser($employee->fresh(), $only);
        });
        $link = app(TenantContext::class)->withSchool($school, fn () => EmployeeAccountLink::query()->where('employee_id', $employee->id)->firstOrFail());
        $correction = $this->requestCorrection($employee, $link->id, $only);

        try {
            $this->approveCorrection($school, $correction, $only);
            $this->fail('the only administrator approved their own request');
        } catch (EmployeeAccountLinkCorrectionNotIndependentException) {
            $this->assertSame('requested', app(TenantContext::class)->withSchool($school, fn () => $correction->fresh()->status));
        }
        $history = app(TenantContext::class)->withSchool($school, fn () => app(EmployeeAccountLinkHistory::class)->forEmployee($employee->fresh(), $only));
        $this->assertFalse($history[0]['correctionRequest']['mayDecide']);

        // A second qualified person is what ends the wait, and nothing else does.
        $this->assertSame('approved', $this->approveCorrection($school, $correction, $this->linkedHrStaff($school))->status);
    }

    #[Test]
    public function the_page_offers_each_person_only_what_they_may_do(): void
    {
        $correction = $this->correction();
        $read = fn (User $as) => $this->in(fn () => app(EmployeeAccountLinkHistory::class)->forEmployee($this->employee->fresh(), $as))[0];

        $this->assertFalse($read($this->requester)['correctionRequest']['mayDecide']);
        $this->assertTrue($read($this->reviewer)['correctionRequest']['mayDecide']);
        $this->assertFalse($read($this->reviewer)['mayRequestCorrection'], 'one open request');
        $this->assertFalse($read($this->fullHrActor($this->school))['correctionRequest']['mayDecide'], 'a never-linked account');
        $this->assertSame(['id', 'reason', 'requestedBy', 'requestedAt', 'evidenceVersion', 'stale', 'mayDecide'], array_keys($read($this->reviewer)['correctionRequest']));
        $this->assertSame($correction->id, $read($this->reviewer)['correctionRequest']['id']);
    }

    #[Test]
    public function the_web_routes_request_and_decide_with_a_fresh_code(): void
    {
        $reviewer = $this->fullHrActor($this->school);
        $this->createEmploymentRecord($this->createEmployee($this->school, ['user_id' => $reviewer->id]), ['status' => 'active', 'starts_on' => '2014-01-01', 'ends_on' => null]);
        $this->mfaEnrolled($reviewer);
        $link = $this->link();
        $this->actingAs($this->requester)->post("/app/schools/{$this->school->id}/activate");
        $base = "/app/hr/employees/{$this->employee->id}";

        $this->post("{$base}/account-link/{$link->id}/correction", ['reason' => 'nonsense'])->assertSessionHasErrors('reason');
        $this->post("{$base}/account-link/{$link->id}/correction", ['reason' => 'wrong_person'])->assertRedirect($base)->assertSessionHasNoErrors();
        $this->assertNull($this->link()->voided_at);
        $correction = $this->in(fn () => EmployeeAccountLinkCorrection::query()->firstOrFail());

        $this->actingAs($reviewer)->post("/app/schools/{$this->school->id}/activate");
        $this->get($base)->assertOk()->assertInertia(fn ($page) => $page->where('accountLinkHistory.0.correctionRequest.mayDecide', true));

        $url = "{$base}/account-link-corrections/{$correction->id}/decision";
        $version = $correction->evidence_version;
        $this->post($url, ['decision' => 'approve', 'reviewed_version' => $version, 'confirm' => true])->assertSessionHasErrors('mfa_code');
        $this->post($url, ['decision' => 'approve', 'reviewed_version' => $version, 'mfa_code' => $this->freshMfaCode($reviewer)])->assertSessionHasErrors('confirm');
        $this->assertNull($this->link()->voided_at);

        $this->post($url, ['decision' => 'approve', 'reviewed_version' => $version, 'confirm' => true, 'mfa_code' => $this->freshMfaCode($reviewer)])
            ->assertRedirect($base)->assertSessionHasNoErrors()->assertSessionHas('flash', 'Account link corrected.');
        $this->assertSame('wrong_person', $this->link()->void_reason);
    }

    #[Test]
    public function a_web_refusal_is_a_form_message(): void
    {
        $correction = $this->correction();
        $this->mfaEnrolled($this->requester);
        $this->actingAs($this->requester)->post("/app/schools/{$this->school->id}/activate");

        $this->post("/app/hr/employees/{$this->employee->id}/account-link-corrections/{$correction->id}/decision", ['decision' => 'refuse', 'mfa_code' => $this->freshMfaCode($this->requester)])
            ->assertSessionHasErrors('account');
        $this->assertSame('requested', $this->in(fn () => $correction->fresh()->status));
    }

    #[Test]
    public function a_payroll_user_cannot_reach_the_routes(): void
    {
        $correction = $this->correction();
        $payroll = $this->createUserWithCapabilities($this->school, ['payroll.runs.approve', 'hr.employees.view']);
        $this->actingAs($payroll)->post("/app/schools/{$this->school->id}/activate");

        $this->post("/app/hr/employees/{$this->employee->id}/account-link-corrections/{$correction->id}/decision", ['decision' => 'approve', 'reviewed_version' => $correction->evidence_version, 'confirm' => true, 'mfa_code' => '000000'])->assertForbidden();
        $this->post("/app/hr/employees/{$this->employee->id}/account-link/{$this->link()->id}/correction", ['reason' => 'wrong_person'])->assertForbidden();
        $this->assertNull($this->link()->voided_at);
    }

    #[Test]
    public function the_api_requests_and_decides_under_the_same_contract(): void
    {
        $link = $this->link();
        $base = "/api/v1/schools/{$this->school->id}/employees/{$this->employee->id}";
        $this->mfaEnrolled($this->requester);
        $this->mfaEnrolled($this->reviewer);
        $requester = fn () => $this->withHeader('Authorization', 'Bearer '.$this->mfaToken($this->requester));

        $requester()->getJson("{$base}/account-links")->assertOk()->assertJsonPath('data.0.id', $link->id);
        $requester()->postJson("{$base}/account-links/{$link->id}/correction", ['reason' => 'nonsense'])->assertUnprocessable();
        $requester()->postJson("{$base}/account-links/{$link->id}/correction", ['reason' => 'wrong_person'])->assertCreated();
        $requester()->postJson("{$base}/account-links/{$link->id}/correction", ['reason' => 'wrong_person'])->assertStatus(409)->assertJsonPath('error.code', 'HR_ACCOUNT_LINK_CORRECTION_ALREADY_REQUESTED');
        $correction = $this->in(fn () => EmployeeAccountLinkCorrection::query()->firstOrFail());
        $url = "{$base}/account-link-corrections/{$correction->id}/decision";

        $requester()->postJson($url, ['decision' => 'approve', 'reviewed_version' => $correction->evidence_version, 'mfa_code' => $this->freshMfaCode($this->requester)])->assertForbidden()->assertJsonPath('error.code', 'HR_ACCOUNT_LINK_CORRECTION_NOT_INDEPENDENT');

        Auth()->forgetGuards();
        $reviewer = fn () => $this->withHeader('Authorization', 'Bearer '.$this->mfaToken($this->reviewer));
        $reviewer()->postJson($url, ['decision' => 'approve', 'reviewed_version' => $correction->evidence_version, 'mfa_code' => $this->freshMfaCode($this->reviewer)])->assertOk()->assertJsonPath('data.0.state', 'corrected');
        $reviewer()->postJson($url, ['decision' => 'approve', 'reviewed_version' => $correction->evidence_version, 'mfa_code' => $this->freshMfaCode($this->reviewer)])->assertStatus(409)->assertJsonPath('error.code', 'HR_ACCOUNT_LINK_CORRECTION_ALREADY_DECIDED');
    }

    #[Test]
    public function another_school_reaches_nothing(): void
    {
        $correction = $this->correction();
        $other = $this->createSchool();
        $outsider = $this->linkedHrStaff($other);
        $this->mfaEnrolled($outsider);
        $foreign = $this->createEmployee($other, ['user_id' => null]);
        $api = fn () => $this->withHeader('Authorization', 'Bearer '.$this->mfaToken($outsider));

        $api()->getJson("/api/v1/schools/{$other->id}/employees/{$this->employee->id}/account-links")->assertNotFound();
        $api()->postJson("/api/v1/schools/{$other->id}/employees/{$foreign->id}/account-links/{$this->link()->id}/correction", ['reason' => 'wrong_person'])->assertNotFound();
        $api()->postJson("/api/v1/schools/{$other->id}/employees/{$foreign->id}/account-link-corrections/{$correction->id}/decision", ['decision' => 'approve', 'reviewed_version' => $correction->evidence_version, 'mfa_code' => $this->freshMfaCode($outsider)])->assertNotFound();
        $this->assertNull($this->link()->voided_at);
    }

    #[Test]
    public function an_unknown_link_or_request_is_not_found(): void
    {
        try {
            $this->requestCorrection($this->employee, (string) Str::uuid(), $this->requester);
            $this->fail('an unknown link');
        } catch (ModelNotFoundException) {
            $this->addToAssertionCount(1);
        }
        $this->expectException(ModelNotFoundException::class);
        $this->in(fn () => app(EmployeeAccountLinkCorrectionService::class)->refuse($this->employee->fresh(), (string) Str::uuid(), $this->reviewer, new FreshMfaProof($this->reviewer->id, CarbonImmutable::now())));
    }

    #[Test]
    public function the_reason_list_is_closed(): void
    {
        $this->assertSame(['wrong_person', 'setup_error'], array_column(EmployeeAccountLinkVoidReason::cases(), 'value'));
    }
}

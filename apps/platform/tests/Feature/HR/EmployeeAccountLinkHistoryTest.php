<?php

namespace Tests\Feature\HR;

use App\Domain\HR\Application\AccountLinks\EmployeeAccountLinkCorrectionService;
use App\Domain\HR\Application\AccountLinks\EmployeeAccountLinkHistory;
use App\Domain\HR\Application\AccountLinks\EmployeeAccountLinkVoidReason;
use App\Domain\HR\Application\EmployeeService;
use App\Domain\HR\Application\Exceptions\EmployeeAccountLinkAlreadyCorrectedException;
use App\Domain\HR\Application\Exceptions\HrSelfAdministrationException;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmployeeAccountLink;
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
use Tests\TestCase;

/**
 * HR.R1-C7 (OD-HR-25): `employees.user_id` stays the one current link; its history is
 * written by a trigger, so no path changes the link without leaving a record, and a
 * correction marks a row instead of removing it.
 */
class EmployeeAccountLinkHistoryTest extends TestCase
{
    use CreatesTenancyFixtures, ProvidesSensitiveActionMfa;

    private School $school;

    private User $hr;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = $this->createSchool();
        $this->hr = $this->fullHrActor($this->school);
        $this->employee = $this->createEmployee($this->school, ['user_id' => null]);
    }

    private function member(?School $school = null): User
    {
        $user = $this->createUser();
        $this->createMembership($user, $school ?? $this->school);

        return $user;
    }

    private function in(callable $fn): mixed
    {
        return app(TenantContext::class)->withSchool($this->school, $fn);
    }

    private function linkTo(User $user): void
    {
        $this->in(fn () => app(EmployeeService::class)->linkUser($this->employee->fresh(), $user->id, $this->hr));
    }

    private function unlink(): void
    {
        $this->in(fn () => app(EmployeeService::class)->unlinkUser($this->employee->fresh(), $this->hr));
    }

    /** @return list<EmployeeAccountLink> newest first */
    private function links(?Employee $employee = null): array
    {
        return $this->in(fn () => EmployeeAccountLink::query()->where('employee_id', ($employee ?? $this->employee)->id)->orderByDesc('linked_at')->get()->all());
    }

    private function proof(User $user): FreshMfaProof
    {
        return new FreshMfaProof($user->id, CarbonImmutable::now());
    }

    private function correct(EmployeeAccountLink $link, ?User $as = null, EmployeeAccountLinkVoidReason $reason = EmployeeAccountLinkVoidReason::WrongPerson): EmployeeAccountLink
    {
        $as ??= $this->hr;

        return $this->in(fn () => app(EmployeeAccountLinkCorrectionService::class)->correct($this->employee->fresh(), $link->id, $reason, $as, $this->proof($as)));
    }

    #[Test]
    public function a_link_is_recorded_with_who_made_it(): void
    {
        $user = $this->member();
        $this->linkTo($user);

        $links = $this->links();
        $this->assertCount(1, $links);
        $this->assertSame($user->id, $links[0]->user_id);
        $this->assertSame($this->hr->id, $links[0]->linked_by_user_id);
        $this->assertSame('recorded', $links[0]->origin);
        $this->assertNull($links[0]->ended_at);
        $this->assertSame($user->id, $this->in(fn () => $this->employee->fresh()->user_id), 'the current link is unchanged in shape');
    }

    #[Test]
    public function an_authorized_unlink_works_and_ends_the_interval_with_who_ended_it(): void
    {
        $user = $this->member();
        $this->linkTo($user);
        $this->unlink();

        $this->assertNull($this->in(fn () => $this->employee->fresh()->user_id), 'an unlink stays possible');
        $link = $this->links()[0];
        $this->assertNotNull($link->ended_at);
        $this->assertSame($this->hr->id, $link->ended_by_user_id);
        $this->assertNull($link->voided_at, 'an unlink says nothing about whether the link was true');
    }

    #[Test]
    public function a_failed_link_leaves_no_false_history(): void
    {
        $taken = $this->member();
        $other = $this->createEmployee($this->school, ['user_id' => null]);
        $this->linkTo($taken);

        try {
            $this->in(fn () => app(EmployeeService::class)->linkUser($other->fresh(), $taken->id, $this->hr));
            $this->fail('one account links one Employee');
        } catch (\Throwable) {
        }

        $this->assertCount(0, $this->links($other));
    }

    #[Test]
    public function a_relink_and_an_account_replacement_keep_every_interval_in_order(): void
    {
        [$first, $second, $third] = [$this->member(), $this->member(), $this->member()];
        $this->linkTo($first);
        $this->unlink();
        $this->linkTo($second);
        $this->unlink();
        $this->linkTo($third);

        $links = $this->links();
        $this->assertSame([$third->id, $second->id, $first->id], array_map(fn ($l) => $l->user_id, $links));
        $this->assertNull($links[0]->ended_at, 'exactly one is open');
        $this->assertLessThanOrEqual($links[1]->linked_at, $links[2]->ended_at, 'intervals never overlap');
        $this->assertLessThanOrEqual($links[0]->linked_at, $links[1]->ended_at);
    }

    #[Test]
    public function the_open_row_is_the_current_link_and_the_history_answers_as_of_a_time(): void
    {
        [$first, $second] = [$this->member(), $this->member()];
        $this->linkTo($first);
        $during = $this->links()[0]->linked_at->copy()->addMicroseconds(1)->format('Y-m-d H:i:s.u');
        usleep(2000);
        $this->unlink();
        $this->linkTo($second);

        $asOf = fn (string $at) => $this->in(fn () => DB::table('employee_account_links')->where('employee_id', $this->employee->id)->whereNull('voided_at')
            ->where('linked_at', '<=', $at)->where(fn ($q) => $q->whereNull('ended_at')->orWhere('ended_at', '>', $at))->value('user_id'));
        $this->assertSame($first->id, $asOf($during), 'who it was then, not who it is now');
        $this->assertSame($second->id, $asOf(now()->addMinute()->format('Y-m-d H:i:s.u')));

        $open = $this->in(fn () => DB::table('employee_account_links')->where('employee_id', $this->employee->id)->whereNull('ended_at')->pluck('user_id')->all());
        $this->assertSame([$this->in(fn () => $this->employee->fresh()->user_id)], $open);
    }

    #[Test]
    public function an_employee_created_with_an_account_is_recorded_too(): void
    {
        $user = $this->member();
        $created = $this->in(fn () => app(EmployeeService::class)->create($this->school, ['full_name' => 'Linked From Birth', 'user_id' => $user->id], $this->hr));

        $links = $this->links($created);
        $this->assertCount(1, $links);
        $this->assertSame($this->hr->id, $links[0]->linked_by_user_id);
    }

    #[Test]
    public function an_employee_never_linked_has_no_history_which_stays_unknown(): void
    {
        $this->assertSame([], $this->in(fn () => app(EmployeeAccountLinkHistory::class)->forEmployee($this->employee->fresh(), $this->hr)));
    }

    #[Test]
    public function the_history_reader_keeps_what_is_known_apart_and_shows_only_names_and_dates(): void
    {
        $user = $this->member();
        $this->linkTo($user);
        $this->unlink();
        $this->linkTo($second = $this->member());

        $history = $this->in(fn () => app(EmployeeAccountLinkHistory::class)->forEmployee($this->employee->fresh(), $this->hr));

        $this->assertSame(['current', 'ended'], array_column($history, 'state'));
        $this->assertSame(['id', 'account', 'state', 'evidence', 'linkedAt', 'endedAt', 'correctedAt', 'correctionReason', 'mayCorrect'], array_keys($history[0]));
        $this->assertStringNotContainsString($second->email, json_encode($history));
    }

    #[Test]
    public function a_correction_marks_the_row_and_keeps_it(): void
    {
        $user = $this->member();
        $this->linkTo($user);
        $this->unlink();

        $this->correct($this->links()[0], reason: EmployeeAccountLinkVoidReason::SetupError);

        $link = $this->links()[0];
        $this->assertNotNull($link->voided_at);
        $this->assertSame($this->hr->id, $link->voided_by_user_id);
        $this->assertSame('setup_error', $link->void_reason);
        $this->assertSame($user->id, $link->user_id, 'the history stays');
        $history = $this->in(fn () => app(EmployeeAccountLinkHistory::class)->forEmployee($this->employee->fresh(), $this->hr));
        $this->assertSame('corrected', $history[0]['state']);
        $this->assertFalse($history[0]['mayCorrect'], 'a correction is final');
    }

    #[Test]
    public function correcting_the_current_link_unlinks_it_in_the_same_transaction_and_audits_once(): void
    {
        $user = $this->member();
        $this->linkTo($user);

        $this->correct($this->links()[0]);

        $this->assertNull($this->in(fn () => $this->employee->fresh()->user_id));
        $link = $this->links()[0];
        $this->assertNotNull($link->ended_at);
        $this->assertSame($this->hr->id, $link->ended_by_user_id);
        $this->assertNotNull($link->voided_at);

        $audits = $this->in(fn () => SchoolAuditEvent::query()->where('event_type', 'employee.user_link_corrected')->where('subject_id', $this->employee->id)->get());
        $this->assertCount(1, $audits);
        $this->assertEqualsCanonicalizing(['employeeId', 'linkId', 'userId', 'wasCurrent', 'reason'], array_keys($audits[0]->metadata));
        $this->assertSame($this->hr->id, $audits[0]->actor_user_id);
        $this->assertTrue($audits[0]->metadata['wasCurrent']);
    }

    #[Test]
    public function link_and_unlink_audits_name_the_historical_row(): void
    {
        $user = $this->member();
        $this->linkTo($user);
        $this->unlink();

        $linkId = $this->links()[0]->id;
        foreach (['employee.user_linked', 'employee.user_unlinked'] as $type) {
            $event = $this->in(fn () => SchoolAuditEvent::query()->where('event_type', $type)->where('subject_id', $this->employee->id)->firstOrFail());
            $this->assertSame($linkId, $event->metadata['linkId'], $type);
        }
    }

    #[Test]
    public function a_second_correction_changes_nothing(): void
    {
        $this->linkTo($this->member());
        $this->unlink();
        $link = $this->links()[0];
        $this->correct($link);
        $before = $this->links()[0]->voided_at;

        $this->expectException(EmployeeAccountLinkAlreadyCorrectedException::class);
        try {
            $this->correct($link);
        } finally {
            $this->assertEquals($before, $this->links()[0]->voided_at);
        }
    }

    #[Test]
    public function nobody_corrects_their_own_link(): void
    {
        $this->linkTo($this->hr2 = $this->fullHrActor($this->school));
        $this->unlink();

        $this->expectException(HrSelfAdministrationException::class);
        $this->correct($this->links()[0], $this->hr2);
    }

    private User $hr2;

    #[Test]
    public function an_mfa_proof_is_the_actors_own(): void
    {
        $this->linkTo($this->member());
        $this->unlink();

        $this->expectException(\LogicException::class);
        $this->in(fn () => app(EmployeeAccountLinkCorrectionService::class)->correct($this->employee->fresh(), $this->links()[0]->id, EmployeeAccountLinkVoidReason::WrongPerson, $this->hr, $this->proof($this->member())));
    }

    #[Test]
    public function correction_needs_whole_school_hr_authority(): void
    {
        $this->linkTo($this->member());
        $this->unlink();
        $payroll = $this->createUserWithCapabilities($this->school, ['payroll.runs.approve', 'payroll.compensation.sensitive.view', 'payroll.runs.reverse', 'payroll.runs.post']);

        $this->expectException(AuthorizationException::class);
        $this->correct($this->links()[0], $payroll);
    }

    #[Test]
    public function the_employee_page_shows_the_history_to_hr_only_and_corrects_with_a_fresh_code(): void
    {
        $this->mfaEnrolled($this->hr);
        $user = $this->member();
        $this->linkTo($user);
        $this->unlink();
        $link = $this->links()[0];
        $this->actingAs($this->hr)->post("/app/schools/{$this->school->id}/activate");

        $this->get("/app/hr/employees/{$this->employee->id}")->assertOk()->assertInertia(fn ($page) => $page->has('accountLinkHistory', 1)->where('accountLinkHistory.0.state', 'ended'));

        $url = "/app/hr/employees/{$this->employee->id}/account-link/{$link->id}/correct";
        $this->post($url, ['reason' => 'wrong_person', 'confirm' => true])->assertSessionHasErrors('mfa_code');
        $this->post($url, ['reason' => 'anything', 'confirm' => true, 'mfa_code' => $this->freshMfaCode($this->hr)])->assertSessionHasErrors('reason');
        $this->post($url, ['reason' => 'wrong_person', 'mfa_code' => $this->freshMfaCode($this->hr)])->assertSessionHasErrors('confirm');
        $this->assertNull($this->links()[0]->voided_at);

        $this->post($url, ['reason' => 'wrong_person', 'confirm' => true, 'mfa_code' => $this->freshMfaCode($this->hr)])
            ->assertRedirect("/app/hr/employees/{$this->employee->id}")->assertSessionHasNoErrors()->assertSessionHas('flash', 'Account link corrected.');
        $this->assertSame('wrong_person', $this->links()[0]->void_reason);
    }

    #[Test]
    public function a_payroll_user_cannot_reach_the_correction_route(): void
    {
        $this->linkTo($this->member());
        $this->unlink();
        $payroll = $this->createUserWithCapabilities($this->school, ['payroll.runs.approve', 'hr.employees.view']);
        $this->actingAs($payroll)->post("/app/schools/{$this->school->id}/activate");

        $this->post("/app/hr/employees/{$this->employee->id}/account-link/{$this->links()[0]->id}/correct", ['reason' => 'wrong_person', 'confirm' => true, 'mfa_code' => '000000'])->assertForbidden();
        $this->assertNull($this->links()[0]->voided_at);
    }

    #[Test]
    public function the_api_lists_and_corrects_under_the_same_contract(): void
    {
        $this->linkTo($this->member());
        $this->unlink();
        $link = $this->links()[0];
        $base = "/api/v1/schools/{$this->school->id}/employees/{$this->employee->id}/account-links";
        $this->mfaEnrolled($this->hr);
        $token = $this->mfaToken($this->hr);
        $api = fn () => $this->withHeader('Authorization', "Bearer {$token}");

        $api()->getJson($base)->assertOk()->assertJsonPath('data.0.id', $link->id);
        $api()->postJson("{$base}/{$link->id}/correct", ['reason' => 'nonsense', 'mfa_code' => $this->freshMfaCode($this->hr)])->assertUnprocessable();
        $api()->postJson("{$base}/{$link->id}/correct", ['reason' => 'wrong_person', 'mfa_code' => $this->freshMfaCode($this->hr)])->assertOk()->assertJsonPath('data.0.state', 'corrected');
        $api()->postJson("{$base}/{$link->id}/correct", ['reason' => 'wrong_person', 'mfa_code' => $this->freshMfaCode($this->hr)])->assertStatus(409)->assertJsonPath('error.code', 'HR_ACCOUNT_LINK_ALREADY_CORRECTED');
    }

    #[Test]
    public function another_school_reaches_nothing_through_the_api(): void
    {
        $this->linkTo($this->member());
        $this->unlink();
        $other = $this->createSchool();
        $outsider = $this->fullHrActor($other);
        $this->mfaEnrolled($outsider);
        $token = $this->mfaToken($outsider);
        $foreign = $this->createEmployee($other, ['user_id' => null]);

        $api = fn () => $this->withHeader('Authorization', "Bearer {$token}");
        $api()->getJson("/api/v1/schools/{$other->id}/employees/{$this->employee->id}/account-links")->assertNotFound();
        $api()->postJson("/api/v1/schools/{$other->id}/employees/{$foreign->id}/account-links/{$this->links()[0]->id}/correct", ['reason' => 'wrong_person', 'mfa_code' => $this->freshMfaCode($outsider)])->assertNotFound();
        $this->assertNull($this->links()[0]->voided_at);
    }

    #[Test]
    public function a_user_being_disabled_changes_neither_the_link_nor_its_history(): void
    {
        $user = $this->member();
        $this->linkTo($user);
        DB::connection('pgsql_admin')->table('users')->where('id', $user->id)->update(['is_disabled' => true]);

        $this->assertSame($user->id, $this->in(fn () => $this->employee->fresh()->user_id));
        $this->assertNull($this->links()[0]->ended_at, 'no access comes from this, and no history goes');
    }

    #[Test]
    public function the_correction_function_refuses_an_actor_without_hr_authority_even_called_raw(): void
    {
        $this->linkTo($this->member());
        $this->unlink();
        $nobody = $this->member();

        $this->expectExceptionMessage('holds no whole-School HR authority');
        $this->in(fn () => DB::select('SELECT employee_account_link_void(?, ?, ?, ?)', [$this->school->id, $this->links()[0]->id, 'wrong_person', $nobody->id]));
    }

    #[Test]
    public function a_correction_can_never_be_a_new_or_unknown_reason_even_with_a_valid_actor(): void
    {
        $this->linkTo($this->member());
        $this->unlink();

        $this->expectExceptionMessage('employee_account_links_void_check');
        $this->in(fn () => DB::select('SELECT employee_account_link_void(?, ?, ?, ?)', [$this->school->id, $this->links()[0]->id, 'because', $this->hr->id]));
    }

    #[Test]
    public function an_unknown_link_id_is_not_found(): void
    {
        $this->expectException(ModelNotFoundException::class);
        $this->in(fn () => app(EmployeeAccountLinkCorrectionService::class)->correct($this->employee->fresh(), (string) Str::uuid(), EmployeeAccountLinkVoidReason::WrongPerson, $this->hr, $this->proof($this->hr)));
    }
}

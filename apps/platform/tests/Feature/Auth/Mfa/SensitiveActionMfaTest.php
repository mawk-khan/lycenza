<?php

namespace Tests\Feature\Auth\Mfa;

use App\Domain\Communications\Application\AnnouncementService;
use App\Domain\Communications\Domain\CommunicationAudienceType;
use App\Domain\Communications\Domain\CommunicationDispatchMode;
use App\Domain\Communications\Domain\CommunicationPriority;
use App\Domain\Communications\Domain\CommunicationRequirement;
use App\Domain\Communications\Infrastructure\CommunicationAnnouncement;
use App\Domain\Finance\Infrastructure\JournalEntry;
use App\Domain\Finance\Infrastructure\LedgerAccount;
use App\Domain\HR\Infrastructure\EmployeeDocument;
use App\Domain\Payroll\Infrastructure\PayrollRun;
use App\Models\School;
use App\Models\SchoolAuditEvent;
use App\Models\User;
use App\Models\UserMfaFactor;
use App\Models\UserMfaRecoveryCode;
use App\Models\WebhookEndpoint;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Auth;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCommunicationFixtures;
use Tests\Concerns\CreatesFinanceFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Concerns\ProvidesSensitiveActionMfa;
use Tests\Feature\Auth\Mfa\Concerns\CreatesMfaFixtures;
use Tests\TestCase;

/**
 * SR.4 (ADR 0071 §8, §26.7): the sensitive-action MFA matrix, through the
 * real routes. One definition of "fresh": an enrolled factor plus a current
 * TOTP code or an unused recovery code in THIS request
 * (FreshMfaRequirement), checked after the capability and before the
 * business transaction -- on the browser and the bearer API alike. Highly
 * Sensitive reads need current assurance (session, or a bearer token minted
 * under the current factor), never a code per read. Ordinary work needs
 * none.
 */
class SensitiveActionMfaTest extends TestCase
{
    use CreatesCommunicationFixtures, CreatesFinanceFixtures, CreatesMfaFixtures, CreatesTenancyFixtures, ProvidesSensitiveActionMfa;

    private function bearer(string $token): static
    {
        Auth::forgetGuards();
        $this->flushHeaders();

        return $this->withHeader('Authorization', 'Bearer '.$token);
    }

    private function inSchool(School $school, callable $callback): mixed
    {
        return app(TenantContext::class)->withSchool($school, $callback);
    }

    /** @return array{0: LedgerAccount, 1: LedgerAccount} */
    private function accounts(School $school): array
    {
        return $this->inSchool($school, fn () => [
            LedgerAccount::factory()->for($school, 'school')->type('asset')->create(),
            LedgerAccount::factory()->for($school, 'school')->type('income')->create(),
        ]);
    }

    /** @return array<string, mixed> */
    private function journal(LedgerAccount $debit, LedgerAccount $credit): array
    {
        return ['currency' => 'INR', 'description' => 'Fees', 'lines' => [
            ['ledger_account_id' => $debit->id, 'side' => 'debit', 'amount' => '10.00'],
            ['ledger_account_id' => $credit->id, 'side' => 'credit', 'amount' => '10.00'],
        ]];
    }

    private function unusedRecoveryCodes(User $user): int
    {
        return UserMfaRecoveryCode::query()->where('user_id', $user->id)->whereNull('consumed_at')->count();
    }

    #[Test]
    public function a_fresh_code_is_an_enrolled_factor_plus_a_current_single_use_code_after_the_capability(): void
    {
        $school = $this->createSchool();
        [$debit, $credit] = $this->accounts($school);
        $url = "/api/v1/schools/{$school->id}/journal-entries";
        $count = fn () => $this->inSchool($school, fn () => JournalEntry::query()->count());

        // No factor at all.
        $unenrolled = $this->createUserWithCapabilities($school, ['finance.ledger.post']);
        $this->bearer($unenrolled->createToken('t')->plainTextToken)->postJson($url, $this->journal($debit, $credit) + ['mfa_code' => '123456'])
            ->assertStatus(422)->assertJsonPath('error.errors.mfa_code.0', 'This action requires multi-factor authentication. Enroll a factor under Account security first.');

        // Enrolled: a missing, a wrong and a used code are each refused; nothing posts.
        $poster = $this->createUserWithCapabilities($school, ['finance.ledger.post']);
        $secret = 'JBSWY3DPEHPK3PXP';
        $this->enrollActiveMfaFactor($poster, $secret);
        [$recovery] = $this->issueRecoveryCodes($poster, 1);
        $token = $poster->createToken('t')->plainTextToken;
        $this->bearer($token)->postJson($url, $this->journal($debit, $credit))->assertStatus(422)->assertJsonPath('error.errors.mfa_code.0', 'Enter a current authentication code.');
        $this->bearer($token)->postJson($url, $this->journal($debit, $credit) + ['mfa_code' => '000000'])->assertStatus(422)->assertJsonPath('error.errors.mfa_code.0', 'That code is not valid.');
        $this->assertSame(0, $count());

        // A recovery code works once; a current TOTP code works once (its step is claimed).
        $this->bearer($token)->postJson($url, $this->journal($debit, $credit) + ['mfa_code' => $recovery])->assertCreated();
        $this->bearer($token)->postJson($url, $this->journal($debit, $credit) + ['mfa_code' => $recovery])->assertStatus(422);
        $totp = $this->currentTotpCodeFor($secret);
        $this->bearer($token)->postJson($url, $this->journal($debit, $credit) + ['mfa_code' => $totp])->assertCreated();
        $this->bearer($token)->postJson($url, $this->journal($debit, $credit) + ['mfa_code' => $totp])->assertStatus(422);
        $this->assertSame(2, $count());

        // Capability first: an actor without it is refused 403 and spends no code.
        $viewer = $this->createUserWithCapabilities($school, ['finance.ledger.view']);
        $this->enrollActiveMfaFactor($viewer);
        [$viewerCode] = $this->issueRecoveryCodes($viewer, 1);
        $this->bearer($viewer->createToken('t')->plainTextToken)->postJson($url, $this->journal($debit, $credit) + ['mfa_code' => $viewerCode])->assertForbidden();
        $this->assertSame(1, $this->unusedRecoveryCodes($viewer));

        // The business audit is unchanged: one `journal_entry.posted` per posting, nothing MFA-specific.
        $events = $this->inSchool($school, fn () => SchoolAuditEvent::query()->pluck('event_type')->all());
        $this->assertSame(2, collect($events)->filter(fn ($e) => $e === 'journal_entry.posted')->count());
        $this->assertSame([], array_values(array_filter($events, fn ($e) => str_contains($e, 'mfa'))));
    }

    #[Test]
    public function ledger_reversal_payment_concession_webhook_and_hr_sensitive_writes_refuse_without_a_code(): void
    {
        $school = $this->createSchool();
        [$debit, $credit] = $this->accounts($school);
        $entry = $this->postBalancedJournalEntry($school, $debit, $credit);
        $api = "/api/v1/schools/{$school->id}";
        $actor = $this->createUserWithCapabilities($school, [
            'finance.ledger.reverse', 'finance.fee_concessions.approve', 'integrations.webhooks.manage', 'integrations.webhooks.view',
            'hr.employees.sensitive.manage', 'hr.employees.documents.manage', 'hr.employees.view',
        ]);
        $token = $this->mfaToken($actor);

        $this->bearer($token)->postJson("{$api}/journal-entries/{$entry->id}/reverse")->assertStatus(422)->assertJsonStructure(['error' => ['errors' => ['mfa_code']]]);
        $this->bearer($token)->postJson("{$api}/journal-entries/{$entry->id}/reverse", $this->mfaBody($token))->assertCreated();

        $this->bearer($token)->postJson("{$api}/fee-concessions/".fake()->uuid().'/approve')->assertStatus(422)->assertJsonStructure(['error' => ['errors' => ['mfa_code']]]);
        $this->bearer($token)->postJson("{$api}/fee-concessions/".fake()->uuid().'/revoke')->assertStatus(422);
        $this->bearer($token)->postJson("{$api}/fee-adjustments/".fake()->uuid().'/cancel')->assertStatus(422);

        $this->bearer($token)->withHeader('Idempotency-Key', 'sr4-webhook')->postJson("{$api}/webhook-endpoints", ['name' => 'x', 'url' => 'https://8.8.8.8/hook'])
            ->assertStatus(422)->assertJsonStructure(['error' => ['errors' => ['mfa_code']]]);
        $this->assertSame(0, $this->inSchool($school, fn () => WebhookEndpoint::query()->count()));

        // HR: a highly sensitive record needs the code; a restricted one never does.
        $employee = $this->createEmployee($school);
        $document = ['category' => 'id_proof', 'storage_disk' => 's3', 'storage_path' => 'x.pdf', 'original_filename' => 'x.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 10];
        $this->bearer($token)->withHeader('Idempotency-Key', 'sr4-hr-doc-1')->postJson("{$api}/employees/{$employee->id}/hr-document-records", $document + ['classification_tier' => 'highly_sensitive'])
            ->assertStatus(422)->assertJsonStructure(['error' => ['errors' => ['mfa_code']]]);
        $this->bearer($token)->withHeader('Idempotency-Key', 'sr4-hr-doc-2')->postJson("{$api}/employees/{$employee->id}/hr-document-records", $document + ['classification_tier' => 'highly_sensitive'] + $this->mfaBody($token))
            ->assertCreated();
        $this->bearer($token)->withHeader('Idempotency-Key', 'sr4-hr-doc-3')->postJson("{$api}/employees/{$employee->id}/hr-document-records", $document + ['classification_tier' => 'restricted'])
            ->assertCreated();
        $this->assertSame(2, $this->inSchool($school, fn () => EmployeeDocument::query()->count()));
    }

    #[Test]
    public function payroll_checker_actions_and_compensation_need_a_code_and_amounts_need_current_assurance(): void
    {
        $school = $this->createSchool();
        $api = "/api/v1/schools/{$school->id}";
        $maker = $this->createUserWithCapabilities($school, [
            'payroll.structures.manage', 'payroll.compensation.sensitive.manage', 'payroll.periods.manage',
            'payroll.runs.prepare', 'payroll.accounting.manage',
        ]);
        $makerToken = $this->mfaToken($maker);
        [$expense, $payable] = $this->inSchool($school, fn () => [
            LedgerAccount::factory()->for($school, 'school')->type('expense')->create(),
            LedgerAccount::factory()->for($school, 'school')->type('liability')->create(),
        ]);
        $this->bearer($makerToken)->postJson("{$api}/payroll-accounting-configuration", ['salary_expense_ledger_account_id' => $expense->id, 'salary_payable_ledger_account_id' => $payable->id])->assertCreated();
        $basic = $this->postJson("{$api}/salary-components", ['code' => 'BASIC', 'name' => 'Basic', 'type' => 'earning'])->json('data.id');
        $structure = $this->postJson("{$api}/salary-structures", ['code' => 'G1', 'name' => 'G1'])->json('data.id');
        $line = $this->postJson("{$api}/salary-structures/{$structure}/components", ['salary_component_id' => $basic, 'calculation_type' => 'fixed_amount', 'base_component_id' => null, 'rate' => null, 'display_order' => 1])->json('data.id');
        $this->postJson("{$api}/salary-structures/{$structure}/activate")->assertOk();
        $record = $this->createEmploymentRecord($this->createEmployee($school), ['starts_on' => '2025-01-01']);
        $compensation = ['salary_structure_id' => $structure, 'effective_from' => '2025-01-01', 'fixed_values' => [['salary_structure_component_id' => $line, 'amount' => '50000.00']]];
        $this->bearer($makerToken)->postJson("{$api}/employment-records/{$record->id}/compensation-assignments", $compensation)->assertStatus(422);
        $assignment = $this->bearer($makerToken)->postJson("{$api}/employment-records/{$record->id}/compensation-assignments", $compensation + $this->mfaBody($makerToken))->assertCreated()->json('data.id');
        $period = $this->postJson("{$api}/payroll-periods", ['period_month' => '2026-09-01'])->json('data.id');
        $this->postJson("{$api}/payroll-periods/{$period}/open")->assertOk();
        $run = $this->withHeader('Idempotency-Key', 'sr4-run-create')->postJson("{$api}/payroll-periods/{$period}/payroll-runs")->assertCreated()->json('data.id');
        $this->postJson("{$api}/payroll-runs/{$run}/calculate")->assertOk();

        $checker = $this->createUserWithCapabilities($school, ['payroll.runs.approve', 'payroll.runs.post', 'payroll.runs.reverse', 'payroll.compensation.sensitive.view', 'payroll.runs.view']);
        $checkerToken = $this->mfaToken($checker);
        foreach (['approve' => 200, 'post' => 201, 'reverse' => 201] as $action => $status) {
            $this->bearer($checkerToken)->withHeader('Idempotency-Key', "sr4-{$action}-bare")->postJson("{$api}/payroll-runs/{$run}/{$action}")
                ->assertStatus(422)->assertJsonStructure(['error' => ['errors' => ['mfa_code']]]);
            $this->bearer($checkerToken)->withHeader('Idempotency-Key', "sr4-checker-{$action}")->postJson("{$api}/payroll-runs/{$run}/{$action}", $this->mfaBody($checkerToken))
                ->assertStatus($status);
        }
        $this->assertSame('posted', $this->inSchool($school, fn () => PayrollRun::query()->findOrFail($run)->status));

        // Amounts over the API: assurance is the token minted under the CURRENT factor.
        $this->bearer($checkerToken)->getJson("{$api}/payroll-runs/{$run}/results")->assertOk();
        $this->bearer($checkerToken)->getJson("{$api}/compensation-assignments/{$assignment}/values")->assertOk();
        $reader = $this->createUserWithCapabilities($school, ['payroll.compensation.sensitive.view']);
        $before = $reader->createToken('before-factor')->plainTextToken;
        $this->bearer($before)->getJson("{$api}/payroll-runs/{$run}/results")->assertForbidden()->assertJsonPath('error.code', 'mfa_required_not_enrolled');
        $this->travel(2)->seconds();
        $this->enrollActiveMfaFactor($reader);
        $this->bearer($before)->getJson("{$api}/payroll-runs/{$run}/results")->assertUnauthorized()->assertJsonPath('error.code', 'mfa_step_up_required');
        $after = $reader->createToken('after-factor')->plainTextToken;
        $this->bearer($after)->getJson("{$api}/payroll-runs/{$run}/results")->assertOk();
        $this->bearer($after)->getJson("{$api}/payroll-runs/{$run}/payslips/{$record->id}")->assertOk();

        // An administrative MFA reset (a new factor) ends the old token's assurance.
        $this->travel(2)->seconds();
        UserMfaFactor::query()->where('user_id', $reader->id)->update(['status' => 'revoked']);
        $this->enrollActiveMfaFactor($reader);
        $this->bearer($after)->getJson("{$api}/payroll-runs/{$run}/results")->assertUnauthorized();

        // Over the browser: results only with current sign-in assurance, and the page says why.
        $this->flushHeaders();
        Auth::forgetGuards();
        $this->actingAs($checker)->withHeader('X-School-Id', $school->id);
        session()->forget('mfa_verified_at');
        $this->get("/app/payroll/runs/{$run}")->assertOk()->assertInertia(fn ($page) => $page->where('sensitiveNeedsMfa', true)->where('results', null));
        $this->get("/app/payroll/runs/{$run}/payslips/{$record->id}")->assertUnauthorized()->assertInertia(fn ($page) => $page->component('App/Platform/MfaRequired'));
        $this->withMfaAssurance($checker)->get("/app/payroll/runs/{$run}")->assertOk()->assertInertia(fn ($page) => $page->where('sensitiveNeedsMfa', false)->has('results', 1));
    }

    #[Test]
    public function an_emergency_publish_needs_a_code_and_a_standard_publish_does_not(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $this->createMembership($this->createUser(), $school);
        $draft = fn (CommunicationDispatchMode $mode) => app(AnnouncementService::class)->createDraft(
            $school, $admin, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide,
            requirement: CommunicationRequirement::Required, dispatchMode: $mode,
            emergencyJustification: $mode === CommunicationDispatchMode::Emergency ? 'Evacuation.' : null,
        );
        $emergency = $draft(CommunicationDispatchMode::Emergency);
        $standard = $draft(CommunicationDispatchMode::Standard);
        $this->actingAs($admin)->post("/app/schools/{$school->id}/activate");
        $status = fn ($a) => $this->inSchool($school, fn () => CommunicationAnnouncement::query()->findOrFail($a->id)->status);

        $this->post("/app/communications/announcements/{$emergency->id}/publish", ['acknowledged' => true])->assertSessionHasErrors('mfa_code');
        $this->assertSame('draft', $status($emergency));
        $this->post("/app/communications/announcements/{$emergency->id}/publish", ['acknowledged' => true, 'mfa_code' => $this->freshMfaCode($admin)])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('published', $status($emergency));

        $this->post("/app/communications/announcements/{$standard->id}/publish")->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('published', $status($standard));
    }
}

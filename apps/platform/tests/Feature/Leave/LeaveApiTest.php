<?php

namespace Tests\Feature\Leave;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ProvidesSensitiveActionMfa;
use Tests\Feature\Leave\Concerns\CreatesLeaveFixtures;
use Tests\TestCase;

/**
 * HRX.1 (ADR 0065 §12, §16): the Leave API -- capability-gated (allow and
 * deny for each family), `private-no-store`, idempotent entitlement writes
 * (replay and conflict), and one tenant-safe 404 for another School's ids.
 */
class LeaveApiTest extends TestCase
{
    use CreatesLeaveFixtures, ProvidesSensitiveActionMfa;

    private function as(User $user, ?string $key = null): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$user->createToken('test-device')->plainTextToken)
            ->withHeader('Idempotency-Key', $key ?? (string) Str::uuid());
    }

    private function url(array $w, string $path): string
    {
        return "/api/v1/schools/{$w['school']->id}/leave{$path}";
    }

    /** HR.R1-C35 WO-19 (D-4): an allocation carries a fresh MFA code (a new one unless $overrides names one). */
    private function allocate(array $w, User $actor, array $overrides = [], ?string $key = null): TestResponse
    {
        return $this->as($actor, $key)->postJson($this->url($w, '/allocations'), array_merge([
            'employment_record_id' => $w['employment']->id, 'leave_type_id' => $w['type']->id, 'leave_year_id' => $w['year']->id, 'units' => 6,
        ], array_key_exists('mfa_code', $overrides) ? [] : ['mfa_code' => $this->freshMfaCode($actor)], $overrides));
    }

    #[Test]
    public function each_capability_family_allows_its_operations_and_nothing_else(): void
    {
        $w = $this->leaveWorld();
        $viewer = $this->createUserWithCapabilities($w['school'], ['hr.leave.view']);
        $configurer = $this->createUserWithCapabilities($w['school'], ['hr.leave.configure']);
        $manager = $this->createUserWithCapabilities($w['school'], ['hr.leave.manage']);
        $nobody = $this->createUserWithCapabilities($w['school'], ['hr.employees.view']);

        // Reads: view only.
        $this->as($viewer)->getJson($this->url($w, '/types'))->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->as($nobody)->getJson($this->url($w, '/types'))->assertForbidden();
        $this->as($configurer)->getJson($this->url($w, '/types'))->assertForbidden();

        // Configuration: configure only.
        $this->as($configurer)->postJson($this->url($w, '/types'), ['code' => 'ml', 'name' => 'Other leave', 'is_paid' => true, 'tracks_balance' => true, 'allows_half_day' => false])
            ->assertCreated()->assertJsonPath('data.code', 'ML');
        $this->as($manager)->postJson($this->url($w, '/types'), ['code' => 'xl', 'name' => 'X', 'is_paid' => true, 'tracks_balance' => true, 'allows_half_day' => false])->assertForbidden();
        $this->as($viewer)->putJson($this->url($w, '/settings'), ['leave_year_start_month' => 1])->assertForbidden();

        // Entitlement writes: manage only.
        $this->allocate($w, $configurer)->assertForbidden();
        $this->allocate($w, $viewer)->assertForbidden();
        $this->allocate($w, $manager)->assertCreated()->assertJsonPath('data.kind', 'allocation')->assertJsonPath('data.units', 6);
        // HR.10 (S-7): an adjustment re-verifies a fresh MFA code; without one it is a validation error.
        $adjustment = ['employment_record_id' => $w['employment']->id, 'leave_type_id' => $w['type']->id, 'leave_year_id' => $w['year']->id,
            'direction' => 'debit', 'units' => 2, 'reason' => 'allocation_correction'];
        $this->mfaEnrolled($manager);
        $this->as($manager)->postJson($this->url($w, '/adjustments'), $adjustment)->assertStatus(422);
        $this->as($manager)->postJson($this->url($w, '/adjustments'), $adjustment + ['mfa_code' => $this->freshMfaCode($manager)])->assertCreated();
        $this->as($viewer)->getJson($this->url($w, "/balances?employment_record_id={$w['employment']->id}&leave_year_id={$w['year']->id}"))
            ->assertOk()->assertJsonPath('data.0.availableUnits', 4);
    }

    #[Test]
    public function an_entitlement_write_replays_on_retry_and_refuses_a_reused_key_with_a_different_payload(): void
    {
        $w = $this->leaveWorld();
        $key = (string) Str::uuid();

        // WO-19: the retry resends the identical body (its code already spent): the stored response replays, nothing re-executes.
        $code = $this->freshMfaCode($w['admin']);
        $first = $this->allocate($w, $w['admin'], ['mfa_code' => $code], $key)->assertCreated();
        $this->allocate($w, $w['admin'], ['mfa_code' => $code], $key)->assertCreated()->assertJsonPath('data.id', $first->json('data.id'));
        $this->assertSame(1, $this->inSchool($w['school'], fn () => DB::table('leave_ledger_entries')->count()), 'a retry never grants twice');

        $this->allocate($w, $w['admin'], ['units' => 8, 'mfa_code' => $code], $key)->assertStatus(409)->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_CONFLICT');
        $this->allocate($w, $w['admin'], ['units' => 8])->assertStatus(409)->assertJsonPath('error.code', 'LEAVE_ALREADY_ALLOCATED');
    }

    #[Test]
    public function the_api_validates_its_contract_and_answers_one_404_for_another_schools_ids(): void
    {
        $w = $this->leaveWorld();
        $other = $this->leaveWorld();

        $this->allocate($w, $w['admin'], ['units' => 1.5])->assertStatus(422);
        $this->allocate($w, $w['admin'], ['units' => 0])->assertStatus(422);
        $this->as($w['admin'])->postJson($this->url($w, '/calendar/holidays'), ['date' => '2026-07-01', 'portion' => 'half', 'name' => 'X'])->assertStatus(422);
        $this->as($w['admin'])->putJson($this->url($w, '/calendar/weekdays'), ['weekdays' => [['iso_weekday' => 1, 'portion' => 'full']]])->assertStatus(422);

        $this->allocate($w, $w['admin'], ['employment_record_id' => $other['employment']->id])->assertNotFound();
        $this->as($w['admin'])->patchJson($this->url($w, "/types/{$other['type']->id}"), ['name' => 'Taken'])->assertNotFound();
        $this->as($w['admin'])->postJson($this->url($w, "/policies/{$other['policy']->id}/retire"))->assertNotFound();
        $this->as($w['admin'])->getJson($this->url($w, "/ledger?employment_record_id={$other['employment']->id}&leave_year_id={$other['year']->id}"))->assertOk()->assertJsonPath('data', []);
        $this->as($w['admin'])->patchJson($this->url($w, '/types/not-a-uuid'), ['name' => 'X'])->assertNotFound();
    }

    #[Test]
    public function a_run_previews_then_executes_once_through_the_api(): void
    {
        $w = $this->leaveWorld();
        $query = "?leave_type_id={$w['type']->id}&leave_year_id={$w['year']->id}";

        $this->as($w['admin'])->getJson($this->url($w, "/allocation-runs/preview{$query}"))->assertOk()->assertJsonPath('data.candidateCount', 1);
        $this->as($w['admin'])->postJson($this->url($w, '/allocation-runs'), ['leave_type_id' => $w['type']->id, 'leave_year_id' => $w['year']->id, 'mfa_code' => $this->freshMfaCode($w['admin'])])
            ->assertCreated()->assertJsonPath('data.allocatedCount', 1);
        $this->as($w['admin'])->postJson($this->url($w, '/allocation-runs'), ['leave_type_id' => $w['type']->id, 'leave_year_id' => $w['year']->id, 'mfa_code' => $this->freshMfaCode($w['admin'])])
            ->assertStatus(409)->assertJsonPath('error.code', 'LEAVE_RUN_ALREADY_EXECUTED');
    }
}

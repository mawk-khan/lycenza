<?php

namespace Tests\Feature\Leave;

use App\Domain\Leave\Application\Exceptions\LeaveException;
use App\Domain\Leave\Application\LeaveLedgerService;
use App\Domain\Leave\Application\LeavePolicyAssignmentService;
use App\Domain\Leave\Infrastructure\LeaveLedgerEntry;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ProvidesSensitiveActionMfa;
use Tests\Feature\Leave\Concerns\CreatesLeaveFixtures;
use Tests\TestCase;

/**
 * HR.10 (ADR 0065 §29.4, §29.7; D11-01, S-7): opening balances and
 * adjustments -- `hr.leave.manage` AND a fresh MFA code on web and API, an
 * opening only as the account's first entry and once, never one's own, a
 * correcting adjustment naming an entry of the SAME account, nothing edited.
 */
class LeaveOpeningAdjustmentTest extends TestCase
{
    use CreatesLeaveFixtures, ProvidesSensitiveActionMfa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-05 06:00:00');
    }

    private function code(callable $call): string
    {
        try {
            DB::transaction($call);

            return 'ok';
        } catch (LeaveException $e) {
            return $e->errorCode();
        } catch (QueryException $e) {
            return 'db:'.substr($e->getMessage(), 0, 200);
        } catch (AuthorizationException) {
            return 'forbidden';
        }
    }

    /** A world whose employment has an assignment but NO ledger entry yet. */
    private function fresh(): array
    {
        return $this->leaveWorld();
    }

    #[Test]
    public function an_opening_is_the_accounts_first_entry_once_and_never_ones_own(): void
    {
        $w = $this->fresh();
        $ledger = app(LeaveLedgerService::class);
        $opening = fn (int $units, ?User $actor = null) => fn () => $ledger->recordOpening($w['school'], $w['employment']->id, $w['type']->id, $w['year']->id, $units, $actor ?? $w['admin']);

        $this->assertSame('ok', $this->code($opening(7)));
        $this->assertSame('LEAVE_OPENING_NOT_FIRST', $this->code($opening(2)), 'once per account');

        $x = $this->fresh();
        $this->allocate($x, 10);
        $this->assertSame('LEAVE_OPENING_NOT_FIRST', $this->code(fn () => $ledger->recordOpening($x['school'], $x['employment']->id, $x['type']->id, $x['year']->id, 4, $x['admin'])), 'only as the first entry');

        $viewer = $this->createUserWithCapabilities($w['school'], ['hr.leave.view']);
        $this->assertSame('forbidden', $this->code($opening(2, $viewer)));
        $own = $this->leaveAdmin($w['school']);
        $mine = $this->createEmploymentRecord($this->createEmployee($w['school'], ['user_id' => $own->id]), ['status' => 'active', 'starts_on' => '2024-01-01', 'ends_on' => null]);
        $this->assertSame('LEAVE_SELF_ADMINISTRATION', $this->code(fn () => $ledger->recordOpening($w['school'], $mine->id, $w['type']->id, $w['year']->id, 4, $own)));
    }

    #[Test]
    public function an_adjustment_changes_a_funded_account_and_may_name_the_entry_it_corrects(): void
    {
        $w = $this->fresh();
        $ledger = app(LeaveLedgerService::class);
        $this->assertSame('LEAVE_NOT_ALLOCATED', $this->code(fn () => $ledger->adjust($w['school'], $w['employment']->id, $w['type']->id, $w['year']->id, 'credit', 2, 'entitlement_change', $w['admin'])));
        $opening = $ledger->recordOpening($w['school'], $w['employment']->id, $w['type']->id, $w['year']->id, 6, $w['admin']);
        $correction = $ledger->adjust($w['school'], $w['employment']->id, $w['type']->id, $w['year']->id, 'debit', 2, 'allocation_correction', $w['admin'], $opening->id);
        $this->assertSame(['leave_ledger_entry', $opening->id], [$correction->source_type, $correction->source_id]);
        $this->assertSame(6, $this->inSchool($w['school'], fn () => LeaveLedgerEntry::query()->findOrFail($opening->id)->units), 'the original is never edited');

        $otherEmployment = $this->currentEmployment($w['school']);
        app(LeavePolicyAssignmentService::class)->assign($w['school'], $otherEmployment->id, $w['policy']->id, '2026-04-01', null, $w['admin']);
        $sibling = $ledger->recordOpening($w['school'], $otherEmployment->id, $w['type']->id, $w['year']->id, 4, $w['admin']);
        $this->assertSame('LEAVE_ADJUSTMENT_SOURCE_INVALID', $this->code(fn () => $ledger->adjust($w['school'], $w['employment']->id, $w['type']->id, $w['year']->id, 'credit', 1, 'administrative_correction', $w['admin'], $sibling->id)), 'never another account\'s entry');
        $this->assertSame('LEAVE_ADJUSTMENT_SOURCE_INVALID', $this->code(fn () => $ledger->adjust($w['school'], $w['employment']->id, $w['type']->id, $w['year']->id, 'credit', 1, 'administrative_correction', $w['admin'], (string) Str::uuid())));
        $this->assertSame('LEAVE_BALANCE_INSUFFICIENT', $this->code(fn () => $ledger->adjust($w['school'], $w['employment']->id, $w['type']->id, $w['year']->id, 'debit', 9, 'allocation_correction', $w['admin'])));
    }

    #[Test]
    public function web_and_api_require_a_fresh_mfa_code_after_the_capability(): void
    {
        $w = $this->fresh();
        $this->mfaEnrolled($w['admin']);
        $body = ['employment_record_id' => $w['employment']->id, 'leave_type_id' => $w['type']->id, 'leave_year_id' => $w['year']->id, 'units' => 4];
        $web = fn (?User $u = null) => $this->actingAs($u ?? $w['admin'])->withHeader('X-School-Id', $w['school']->id);

        $web()->post('/app/leave/openings', $body)->assertSessionHasErrors('mfa_code');
        $web()->post('/app/leave/openings', $body + ['mfa_code' => '000000'])->assertSessionHasErrors('mfa_code');
        $viewer = $this->createUserWithCapabilities($w['school'], ['hr.leave.view']);
        $web($viewer)->post('/app/leave/openings', $body + ['mfa_code' => '000000'])->assertForbidden();
        $web()->post('/app/leave/openings', $body + ['mfa_code' => $this->freshMfaCode($w['admin'])])->assertSessionHasNoErrors();

        $adjust = array_merge($body, ['direction' => 'debit', 'reason' => 'allocation_correction', 'units' => 1]);
        $web()->post('/app/leave/adjustments', $adjust)->assertSessionHasErrors('mfa_code');
        $web()->post('/app/leave/adjustments', $adjust + ['mfa_code' => $this->freshMfaCode($w['admin'])])->assertSessionHasNoErrors();
        $this->assertSame([4, 1], $this->inSchool($w['school'], fn () => [
            (int) LeaveLedgerEntry::query()->where('kind', 'opening')->sum('units'), (int) LeaveLedgerEntry::query()->where('kind', 'adjustment')->sum('units'),
        ]));

        $x = $this->fresh();
        $this->mfaEnrolled($x['admin']);
        $xToken = $this->mfaToken($x['admin']);
        $xBody = ['employment_record_id' => $x['employment']->id, 'leave_type_id' => $x['type']->id, 'leave_year_id' => $x['year']->id, 'units' => 4];
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', "Bearer {$xToken}")->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/schools/{$x['school']->id}/leave/openings", $xBody)->assertStatus(422);
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', "Bearer {$xToken}")->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/schools/{$x['school']->id}/leave/openings", $xBody + $this->mfaBody($xToken))->assertCreated()->assertJsonPath('data.kind', 'opening');
    }

    #[Test]
    public function the_default_leave_year_follows_the_schools_day_not_the_servers(): void
    {
        // HR.R1-C10 (LVA-6): 20:00 UTC on 31 March is already 1 April in the School, which is the next Leave Year.
        $w = $this->fresh();
        $next = $this->leaveYear($w['school'], $w['admin'], '2027-04-15');
        $this->travelTo('2027-03-31 20:00:00');

        $this->actingAs($w['admin'])->withHeader('X-School-Id', $w['school']->id)->get('/app/leave/entitlements')->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->component('App/Leave/Entitlements')->where('leaveYearId', $next->id));
    }

    #[Test]
    public function the_entitlements_page_shows_statements_and_the_accruals_page_renders(): void
    {
        $w = $this->fresh();
        $this->allocate($w, 10);
        $page = $this->actingAs($w['admin'])->withHeader('X-School-Id', $w['school']->id);

        $page->get("/app/leave/entitlements?employment_record_id={$w['employment']->id}&leave_year_id={$w['year']->id}")->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->component('App/Leave/Entitlements')->has('statements', 1)
                ->where('statements.0.closing', 10)->where('statements.0.identityHolds', true)->where('mfaEnrolled', false));
        $page->get("/app/leave/accruals?leave_type_id={$w['type']->id}&leave_year_id={$w['year']->id}")->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->component('App/Leave/Accruals')->has('grid.months', 12)->where('canManage', true)->where('runs', []));
        $this->actingAs($this->createUserWithCapabilities($w['school'], ['hr.leave.approve']))->withHeader('X-School-Id', $w['school']->id)
            ->get('/app/leave/accruals')->assertForbidden();
    }
}

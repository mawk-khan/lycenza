<?php

namespace Tests\Feature\App;

use App\Http\Middleware\RequireSchoolContext;
use App\Models\School;
use App\Models\User;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Auth\Mfa\Concerns\CreatesMfaFixtures;
use Tests\Feature\Finance\Concerns\CreatesFinancialPeriodFixtures;
use Tests\TestCase;

/**
 * E21.3A (ADR 0064 §7): Finance -> Financial periods. Viewing needs
 * finance.ledger.view; closing needs finance.periods.manage, the typed
 * period key and a fresh MFA code, checked in that order. No reopen and no
 * manual balance entry exist.
 */
class FinancialPeriodUiTest extends TestCase
{
    use CreatesFinancialPeriodFixtures, CreatesMfaFixtures;

    /** @var array<string, list<string>> */
    private array $codes = [];

    private function enter(User $user, School $school): void
    {
        $this->actingAs($user)->withSession([RequireSchoolContext::SESSION_KEY => $school->id]);
    }

    private function withMfa(User $user): User
    {
        $this->enrollActiveMfaFactor($user);
        $this->codes[$user->id] = $this->issueRecoveryCodes($user, 4);

        return $user;
    }

    #[Test]
    public function the_page_lists_periods_with_their_blockers_under_ledger_view_only(): void
    {
        $w = $this->twoYearWorld();
        $viewer = $this->createUserWithCapabilities($w['school'], ['finance.ledger.view']);

        $this->enter($viewer, $w['school']);
        $this->get('/app/finance/periods')->assertOk()->assertInertia(fn ($page) => $page
            ->component('App/Finance/Periods/Index')
            ->where('canClose', false)
            ->where('unmappedEntries', 0)
            ->has('periods', 2)
            ->where('periods.0.key', '2026-27')
            ->where('periods.0.blockers', ['period_not_ended', 'earlier_period_open'])
            ->where('periods.1.key', '2025-26')
            ->where('periods.1.status', 'open')
            ->where('periods.1.blockers', [])
            ->where('periods.1.startsOn', '2025-04-01')
            ->where('periods.1.endsOn', '2026-03-31'));

        $this->enter($this->createUserWithCapabilities($w['school'], ['finance.charges.view']), $w['school']);
        $this->get('/app/finance/periods')->assertForbidden();
    }

    #[Test]
    public function closing_needs_the_capability_the_typed_key_and_a_fresh_mfa_code(): void
    {
        $w = $this->twoYearWorld();
        $url = "/app/finance/periods/{$w['fy2526']->id}/close";

        // Ledger post/reverse holders cannot close (capability before MFA).
        $this->enter($this->createUserWithCapabilities($w['school'], ['finance.ledger.view', 'finance.ledger.post', 'finance.ledger.reverse']), $w['school']);
        $this->post($url, ['confirmation' => '2025-26', 'mfa_code' => '000000'])->assertForbidden();

        $closer = $this->withMfa($this->createUserWithCapabilities($w['school'], ['finance.ledger.view', 'finance.periods.manage']));
        $this->enter($closer, $w['school']);
        $this->post($url, ['confirmation' => '2025-26'])->assertSessionHasErrors(['mfa_code' => 'Enter a current authentication code.']);
        $this->post($url, ['confirmation' => '2025-26', 'mfa_code' => '000000'])->assertSessionHasErrors(['mfa_code' => 'That code is not valid.']);
        $this->post($url, ['confirmation' => '2026-27', 'mfa_code' => array_shift($this->codes[$closer->id])])->assertSessionHasErrors('period');
        $this->assertSame('open', $this->periodByKey($w['school'], '2025-26')->status);

        $this->post($url, ['confirmation' => '2025-26', 'mfa_code' => array_shift($this->codes[$closer->id])])->assertRedirect('/app/finance/periods');
        $this->assertSame('closed', $this->periodByKey($w['school'], '2025-26')->status);

        $this->get('/app/finance/periods')->assertInertia(fn ($page) => $page
            ->where('canClose', true)
            ->where('periods.1.status', 'closed')
            ->where('periods.1.closedBy', $closer->name));

        $this->post($url, ['confirmation' => '2025-26', 'mfa_code' => array_shift($this->codes[$closer->id])])->assertSessionHasErrors('period');
    }

    #[Test]
    public function a_closer_without_an_mfa_factor_is_refused_and_another_schools_period_is_not_found(): void
    {
        $w = $this->twoYearWorld();
        $this->enter($w['closer'], $w['school']);
        $this->post("/app/finance/periods/{$w['fy2526']->id}/close", ['confirmation' => '2025-26', 'mfa_code' => '123456'])
            ->assertSessionHasErrors(['mfa_code' => 'This action requires multi-factor authentication. Enroll a factor under Account security first.']);

        $other = $this->twoYearWorld();
        $closer = $this->withMfa($w['closer']);
        $this->enter($closer, $w['school']);
        $this->post("/app/finance/periods/{$other['fy2526']->id}/close", ['confirmation' => '2025-26', 'mfa_code' => array_shift($this->codes[$closer->id])])->assertNotFound();
        $this->assertSame('open', $this->periodByKey($other['school'], '2025-26')->status);
    }
}

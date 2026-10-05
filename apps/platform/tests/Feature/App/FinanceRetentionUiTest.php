<?php

namespace Tests\Feature\App;

use App\Http\Middleware\RequireSchoolContext;
use App\Models\School;
use App\Models\User;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CommitsRetentionFixtures;
use Tests\Feature\Finance\Concerns\CreatesFinanceRetentionFixtures;
use Tests\TestCase;

/**
 * E21.3A2 (E21-D8, ADR 0064 §15): after expiry the product answers
 * intentionally. An expired journal entry, Payment or receipt is an
 * ordinary not-found, never a 500, and never "deleted by retention" (no
 * existence oracle). The fee statement says that earlier years' detail
 * has expired, and its outstanding total is unchanged. No transaction is
 * fabricated from a baseline.
 */
class FinanceRetentionUiTest extends TestCase
{
    use CommitsRetentionFixtures, CreatesFinanceRetentionFixtures;

    private function enter(User $user, School $school): void
    {
        $this->actingAs($user)->withSession([RequireSchoolContext::SESSION_KEY => $school->id]);
    }

    #[Test]
    public function expired_records_are_ordinary_not_found_and_the_statement_says_detail_expired(): void
    {
        $w = $this->d8World();
        $this->enableFinanceRetention();
        $this->enter($w['recorder'], $w['school']);
        $outstanding = $this->get("/app/finance/fee-statements/{$w['student']->id}")->assertOk()->viewData('page')['props']['statement']['totals']['outstanding'];

        $this->artisan('platform:finance-retention-prune', ['--school' => $w['school']->id])->assertSuccessful();

        $this->enter($w['recorder'], $w['school']);
        $this->get("/app/finance/journal-entries/{$w['m1']}")->assertNotFound();
        $this->get("/app/finance/payments/{$w['p1']}")->assertNotFound();
        $this->get("/app/finance/payments/{$w['p1']}/receipt")->assertNotFound();
        $this->get('/app/finance/journal-entries')->assertOk();
        $this->get('/app/finance/charges')->assertOk();
        $this->get('/app/finance/payments')->assertOk();
        $this->get("/app/finance/journal-entries/{$w['m2']}")->assertOk();
        $this->get('/app/finance/fee-statements/'.$w['student']->id)->assertOk()->assertInertia(fn ($page) => $page
            ->where('statement.detailExpiredThrough', '2015-16')
            ->where('statement.totals.outstanding', $outstanding));

        $api = fn (string $path) => $this->actingAs($w['recorder'])->withHeader('X-School-Id', $w['school']->id)->getJson("/api/v1/schools/{$w['school']->id}{$path}");
        $api("/payments/{$w['p1']}")->assertNotFound()->assertJsonMissing(['retention']);
        $api("/payments/{$w['p1']}/receipt")->assertNotFound();
        $api("/journal-entries/{$w['m1']}")->assertNotFound();
        $api("/students/{$w['student']->id}/fee-statement")->assertOk()->assertJsonPath('data.detailExpiredThrough', '2015-16');
    }
}
